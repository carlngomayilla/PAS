<?php

namespace App\Services\Ai;

use App\Ai\Agents\PtaImportExtractionAgent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class PtaExternalAiExtractionService
{
    private ?string $lastFailureMessage = null;

    public function __construct(
        private readonly PtaImportExtractionAgent $agent,
        private readonly PtaImportTemplateAnalyzerService $template
    ) {}

    /**
     * @param  array<string,mixed>  $metadata
     * @return array{document:array<string,mixed>,rows:list<array<string,mixed>>,log:list<array<string,mixed>>}|null
     */
    public function extractFromText(string $text, array $metadata = []): ?array
    {
        return $this->extract('pdf', $text, $metadata);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $metadata
     * @return array{document:array<string,mixed>,rows:list<array<string,mixed>>,log:list<array<string,mixed>>}|null
     */
    public function extractFromRows(array $rows, array $metadata = []): ?array
    {
        if ($rows === []) {
            $this->lastFailureMessage = null;

            return null;
        }

        return $this->extract('spreadsheet', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', $metadata);
    }

    public function available(): bool
    {
        return $this->availability()['available'];
    }

    public function lastFailureMessage(): ?string
    {
        return $this->lastFailureMessage;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array{document:array<string,mixed>,rows:list<array<string,mixed>>,log:list<array<string,mixed>>}|null
     */
    private function extract(string $sourceType, string $content, array $metadata): ?array
    {
        $this->lastFailureMessage = null;

        $availability = $this->availability();
        if (! $availability['available']) {
            $this->lastFailureMessage = $availability['message'];

            return null;
        }

        try {
            $response = $this->agent->prompt(
                $this->prompt($sourceType, $content, $metadata),
                provider: $this->providerName(),
                model: $this->modelName($sourceType),
                timeout: max(30, (int) config('ai_training.pta.llm_timeout', 120))
            );

            return $this->normalizeStructuredResponse($response instanceof StructuredAgentResponse ? $response->toArray() : $this->jsonFromText($response->text), $metadata);
        } catch (Throwable $exception) {
            $this->lastFailureMessage = $this->failureMessageFor($exception);

            report($exception);

            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function prompt(string $sourceType, string $content, array $metadata): string
    {
        $template = $this->template->analyze();
        $trainingPrompt = trim((string) ($template['training']['prompt_ia'] ?? ''));
        $maxCharacters = max(5000, (int) config('ai_training.pta.llm_max_chars', 60000));

        return implode("\n\n", array_filter([
            'SOURCE_TYPE='.$sourceType,
            'METADONNEES='.json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'MODELES_LOCAUX_CONFIGURES='.json_encode($this->configuredLocalModels(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'COLONNES_IMPORT_GLOBAL='.json_encode($template['columns'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $trainingPrompt === '' ? null : 'PROMPT_IA_DU_GABARIT='.$trainingPrompt,
            'EXEMPLES_IMPORT_GLOBAL='.json_encode($template['examples'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'CONTENU_SOURCE='.Str::limit($content, $maxCharacters, "\n[CONTENU_TRONQUE]"),
            'Retourne uniquement les donnees structurees. Chaque rows[] doit contenir toutes les colonnes IMPORT_GLOBAL, avec null si absent. log[] doit tracer page_pdf, score_confiance_ia et note_normalisation.',
        ]));
    }

    /**
     * @param  array<string,mixed>|null  $structured
     * @param  array<string,mixed>  $metadata
     * @return array{document:array<string,mixed>,rows:list<array<string,mixed>>,log:list<array<string,mixed>>}|null
     */
    private function normalizeStructuredResponse(?array $structured, array $metadata): ?array
    {
        if ($structured === null) {
            return null;
        }

        $rows = $this->listOfRows($structured['rows'] ?? $structured['items'] ?? []);
        if ($rows === []) {
            return null;
        }

        return [
            'document' => $metadata,
            'rows' => $rows,
            'log' => $this->listOfRows($structured['log'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function jsonFromText(string $text): ?array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode($matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listOfRows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $row): bool => is_array($row)));
    }

    private function providerName(): ?string
    {
        $provider = config('ai_training.pta.llm_provider') ?: config('ai.default');

        return is_string($provider) && trim($provider) !== '' ? trim($provider) : null;
    }

    private function explicitModelName(): ?string
    {
        $model = config('ai_training.pta.llm_model');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    private function modelNameForConfigKey(string $configKey): ?string
    {
        $model = config('ai_training.pta.'.$configKey);

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    private function modelName(string $sourceType): ?string
    {
        $explicitModel = $this->explicitModelName();
        if ($explicitModel !== null) {
            return $explicitModel;
        }

        $keys = match ($sourceType) {
            'spreadsheet' => ['llm_reasoning_model', 'llm_text_model'],
            default => ['llm_text_model', 'llm_reasoning_model'],
        };

        foreach ($keys as $key) {
            $model = $this->modelNameForConfigKey($key);
            if ($model !== null) {
                return $model;
            }
        }

        return null;
    }

    /**
     * @return array{provider:?string,vision:?string,text:?string,reasoning:?string}
     */
    private function configuredLocalModels(): array
    {
        return [
            'provider' => $this->providerName(),
            'vision' => $this->modelNameForConfigKey('llm_vision_model'),
            'text' => $this->modelNameForConfigKey('llm_text_model'),
            'reasoning' => $this->modelNameForConfigKey('llm_reasoning_model'),
        ];
    }

    /**
     * @return array{available:bool,message:?string}
     */
    private function availability(): array
    {
        if (! (bool) config('ai_training.pta.llm_enabled', true)) {
            return ['available' => false, 'message' => null];
        }

        if (PtaImportExtractionAgent::isFaked()) {
            return ['available' => true, 'message' => null];
        }

        if (app()->runningUnitTests() && ! (bool) config('ai_training.pta.llm_allow_in_tests', false)) {
            return ['available' => false, 'message' => null];
        }

        $provider = $this->providerName();
        if (! $this->providerIsConfigured($provider)) {
            return [
                'available' => false,
                'message' => $this->providerConfigurationMessage($provider),
            ];
        }

        $healthFailure = $this->providerHealthFailureMessage($provider);
        if ($healthFailure !== null) {
            return ['available' => false, 'message' => $healthFailure];
        }

        return ['available' => true, 'message' => null];
    }

    private function providerIsConfigured(?string $provider): bool
    {
        if ($provider === null) {
            return false;
        }

        $configuration = config('ai.providers.'.$provider, []);
        if (! is_array($configuration)) {
            return false;
        }

        $driver = (string) ($configuration['driver'] ?? $provider);

        return match ($driver) {
            'ollama' => filled($configuration['url'] ?? null),
            'azure' => filled($configuration['key'] ?? null) && filled($configuration['url'] ?? null) && filled($configuration['deployment'] ?? null),
            'bedrock' => (bool) ($configuration['use_default_credential_provider'] ?? false)
                || filled($configuration['key'] ?? null)
                || (filled($configuration['access_key_id'] ?? null) && filled($configuration['secret_access_key'] ?? null)),
            default => filled($configuration['key'] ?? null),
        };
    }

    private function providerConfigurationMessage(?string $provider): string
    {
        if ($provider === null) {
            return 'Aucun fournisseur IA PTA n est configure. Configurez AI_PTA_LLM_PROVIDER=ollama ou desactivez AI_PTA_LLM_ENABLED.';
        }

        $driver = $this->providerDriver($provider);
        if ($driver === 'ollama') {
            return 'Le fournisseur IA local Ollama n est pas configure. Renseignez OLLAMA_URL et AI_PTA_LLM_PROVIDER=ollama, ou desactivez AI_PTA_LLM_ENABLED.';
        }

        return 'Le fournisseur IA '.$this->providerLabel($provider).' n est pas configure. Renseignez ses variables de configuration ou utilisez AI_PTA_LLM_PROVIDER=ollama.';
    }

    private function providerHealthFailureMessage(?string $provider): ?string
    {
        if ($provider === null || $this->providerDriver($provider) !== 'ollama' || ! (bool) config('ai_training.pta.llm_health_check_enabled', true)) {
            return null;
        }

        $configuration = config('ai.providers.'.$provider, []);
        $url = is_array($configuration) ? trim((string) ($configuration['url'] ?? '')) : '';
        if ($url === '') {
            return $this->providerConfigurationMessage($provider);
        }

        try {
            Http::baseUrl(rtrim($url, '/'))
                ->connectTimeout(max(1, (int) config('ai_training.pta.llm_connect_timeout', 5)))
                ->timeout(max(2, min(15, (int) config('ai_training.pta.llm_timeout', 120))))
                ->retry(
                    max(1, (int) config('ai_training.pta.llm_retry_times', 1)),
                    max(0, (int) config('ai_training.pta.llm_retry_sleep', 200))
                )
                ->get('api/tags')
                ->throw();

            return null;
        } catch (Throwable) {
            return 'Le backend IA local Ollama est indisponible a '.$url.'. L analyse continue avec l extraction locale; demarrez Ollama ou verifiez OLLAMA_URL.';
        }
    }

    private function providerDriver(string $provider): string
    {
        $configuration = config('ai.providers.'.$provider, []);

        return is_array($configuration) ? (string) ($configuration['driver'] ?? $provider) : $provider;
    }

    private function failureMessageFor(Throwable $exception): string
    {
        $provider = $this->providerName() ?? 'IA';
        $providerLabel = $this->providerLabel($provider);
        $providerMessage = $this->providerMessageFrom($exception);
        $normalizedProviderMessage = Str::lower($providerMessage);

        if ($this->providerDriver($provider) === 'ollama' && $this->isConnectionFailure($exception)) {
            $url = (string) config('ai.providers.'.$provider.'.url', 'http://localhost:11434');

            return 'Le backend IA local Ollama est indisponible a '.$url.'. L analyse a continue avec l extraction locale.';
        }

        if ($exception instanceof RateLimitedException) {
            if (str_contains($normalizedProviderMessage, 'quota') || str_contains($normalizedProviderMessage, 'billing')) {
                return "L'appel IA {$providerLabel} a ete refuse: quota ou facturation insuffisante. Verifiez la cle API et le billing du projet.";
            }

            return "L'appel IA {$providerLabel} a ete limite temporairement. Reessayez plus tard ou configurez un autre fournisseur IA.";
        }

        if ($exception instanceof InsufficientCreditsException) {
            return "L'appel IA {$providerLabel} a ete refuse: credits ou quota insuffisants.";
        }

        if ($exception instanceof ProviderOverloadedException) {
            return "Le fournisseur IA {$providerLabel} est temporairement indisponible. L'analyse a continue sans IA externe.";
        }

        if ($providerMessage !== '') {
            return "Le fournisseur IA {$providerLabel} a refuse l'analyse: ".Str::limit($providerMessage, 220);
        }

        return "L'analyse IA externe a echoue: ".Str::limit($exception->getMessage(), 220);
    }

    private function providerLabel(string $provider): string
    {
        return $this->providerDriver($provider) === 'ollama' ? 'Ollama' : Str::headline($provider);
    }

    private function isConnectionFailure(Throwable $exception): bool
    {
        $current = $exception;

        while ($current instanceof Throwable) {
            if ($current instanceof ConnectionException || str_contains(Str::lower($current->getMessage()), 'connection')) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    private function providerMessageFrom(Throwable $exception): string
    {
        $current = $exception;

        while ($current instanceof Throwable) {
            if ($current instanceof RequestException && $current->response !== null) {
                $message = $current->response->json('error.message');

                return is_string($message) ? trim($message) : '';
            }

            $current = $current->getPrevious();
        }

        return '';
    }
}
