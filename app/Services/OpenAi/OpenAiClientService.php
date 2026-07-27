<?php

namespace App\Services\OpenAi;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OpenAiClientService
{
    public function __construct(
        private readonly OpenAiUsageBillingService $billing
    ) {}

    public function available(): bool
    {
        return (bool) config('services.openai_responses.enabled', true)
            && filled(config('services.openai_responses.key'));
    }

    /**
     * @param  array<string,mixed>  $jsonSchema
     * @return array{
     *     data:array<string,mixed>,
     *     text:string,
     *     raw:array<string,mixed>,
     *     request_id:?string,
     *     model:string,
     *     input_tokens:int,
     *     output_tokens:int,
     *     total_cost_usd:float
     * }
     */
    public function createStructuredResponse(
        string $operationType,
        string $input,
        array $jsonSchema,
        ?User $user = null,
        string $module = 'ai_import',
        ?string $model = null,
        bool $highCapability = false
    ): array {
        if (! $this->available()) {
            throw new RuntimeException('OpenAI Responses API non configuree cote serveur.');
        }

        $model = $this->resolveModel($model, $highCapability);
        $estimatedInputTokens = $this->estimateTokens($input);
        $maxInputTokens = max(1, (int) config('services.openai_responses.max_input_tokens', 50000));
        if ($estimatedInputTokens > $maxInputTokens) {
            throw new RuntimeException('Le document depasse la limite de tokens configuree pour l analyse IA.');
        }

        $maxOutputTokens = max(256, (int) config('services.openai_responses.max_output_tokens', 6000));
        $this->billing->ensureMonthlyBudgetAllows(
            $this->billing->estimateCost($estimatedInputTokens, $maxOutputTokens)['total']
        );

        $response = $this->http()
            ->post('responses', [
                'model' => $model,
                'input' => $input,
                'store' => false,
                'max_output_tokens' => $maxOutputTokens,
                'reasoning' => [
                    'effort' => (string) config('services.openai_responses.reasoning_effort', 'medium'),
                ],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => $this->schemaName($operationType),
                        'schema' => $jsonSchema,
                        'strict' => true,
                    ],
                ],
            ])
            ->throw();

        /** @var array<string,mixed> $raw */
        $raw = $response->json();
        $text = $this->extractOutputText($raw);
        $data = $this->decodeJsonText($text);
        $requestId = $response->header('x-request-id') ?: Arr::get($raw, 'id');
        $inputTokens = (int) Arr::get($raw, 'usage.input_tokens', $estimatedInputTokens);
        $outputTokens = (int) Arr::get($raw, 'usage.output_tokens', $this->estimateTokens($text));
        $cost = $this->billing->estimateCost($inputTokens, $outputTokens);

        if ((bool) config('services.openai_responses.log_usage', true)) {
            $this->billing->record($user, $module, $operationType, $model, $inputTokens, $outputTokens, is_string($requestId) ? $requestId : null, [
                'response_id' => Arr::get($raw, 'id'),
                'status' => Arr::get($raw, 'status'),
            ]);
        }

        return [
            'data' => $data,
            'text' => $text,
            'raw' => $raw,
            'request_id' => is_string($requestId) ? $requestId : null,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_cost_usd' => $cost['total'],
        ];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.openai_responses.url'), '/').'/')
            ->withToken((string) config('services.openai_responses.key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(max(1, (int) config('services.openai_responses.connect_timeout', 5)))
            ->timeout(max(10, (int) config('services.openai_responses.timeout', 120)))
            ->retry([100, 500, 1000], 0, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response?->serverError());
            });
    }

    private function resolveModel(?string $model, bool $highCapability): string
    {
        if (is_string($model) && trim($model) !== '') {
            return trim($model);
        }

        $key = $highCapability ? 'model_high' : 'model';

        return (string) config('services.openai_responses.'.$key, 'gpt-5.4-mini');
    }

    private function schemaName(string $operationType): string
    {
        $name = Str::of($operationType)->ascii()->lower()->replaceMatches('/[^a-z0-9_-]+/', '_')->trim('_')->limit(64, '')->toString();

        return $name !== '' ? $name : 'structured_response';
    }

    private function estimateTokens(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    /**
     * @param  array<string,mixed>  $raw
     */
    private function extractOutputText(array $raw): string
    {
        $direct = Arr::get($raw, 'output_text');
        if (is_string($direct) && trim($direct) !== '') {
            return $direct;
        }

        $parts = [];
        foreach ((array) ($raw['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                $text = $content['text'] ?? null;
                if (is_string($text)) {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonText(string $text): array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('La reponse OpenAI ne contient pas de JSON exploitable.');
    }
}
