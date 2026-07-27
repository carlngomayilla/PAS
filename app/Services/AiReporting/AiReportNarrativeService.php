<?php

namespace App\Services\AiReporting;

use App\Models\AiReportRequest;
use App\Models\User;
use App\Services\OpenAi\OpenAiClientService;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

class AiReportNarrativeService
{
    public function __construct(
        private readonly OpenAiClientService $openAi
    ) {}

    /**
     * @param  array<string,mixed>  $metrics
     * @return array{title:string,summary:string,html:string,sections:list<array{title:string,content:string,indicators:array<string,mixed>}>}
     */
    public function generate(AiReportRequest $request, array $metrics, ?User $user = null): array
    {
        if (! $this->openAi->available()) {
            throw new RuntimeException('La generation de rapport IA requiert OpenAI. Aucun fournisseur de secours n est autorise.');
        }

        try {
            $response = $this->openAi->createStructuredResponse(
                'institutional_report',
                $this->prompt($request, $metrics),
                $this->schema(),
                $user,
                'ai_reporting',
                highCapability: true
            );

            $request->forceFill([
                'model_used' => $response['model'],
                'input_tokens' => $response['input_tokens'],
                'output_tokens' => $response['output_tokens'],
                'total_cost_usd' => $response['total_cost_usd'],
            ])->save();

            return $this->normalize($response['data'], $request, $metrics);
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException('OpenAI n a pas pu produire le rapport institutionnel. Aucun brouillon de secours ne sera publie.', previous: $exception);
        }
    }

    /**
     * @param  array<string,mixed>  $metrics
     */
    private function prompt(AiReportRequest $request, array $metrics): string
    {
        return implode("\n\n", [
            'Redige un rapport institutionnel ANBG a partir exclusivement du JSON Laravel fourni.',
            'Ne cree aucun chiffre. Si une donnee est absente, ecris "donnee non disponible".',
            'Type de rapport: '.$request->report_type,
            'Periode: '.$request->period_start?->toDateString().' au '.$request->period_end?->toDateString(),
            'JSON='.json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title', 'summary', 'sections'],
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['title', 'content'],
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $metrics
     * @return array{title:string,summary:string,html:string,sections:list<array{title:string,content:string,indicators:array<string,mixed>}>}
     */
    private function normalize(array $data, AiReportRequest $request, array $metrics): array
    {
        $sections = collect($data['sections'] ?? [])
            ->filter(fn (mixed $section): bool => is_array($section))
            ->map(fn (array $section): array => [
                'title' => (string) ($section['title'] ?? 'Section'),
                'content' => (string) ($section['content'] ?? ''),
                'indicators' => [],
            ])
            ->values()
            ->all();

        if ($sections === []) {
            return $this->fallback($request, $metrics);
        }

        return [
            'title' => (string) ($data['title'] ?? $this->title($request)),
            'summary' => (string) ($data['summary'] ?? ''),
            'html' => (string) ($data['html'] ?? $this->htmlFromSections($sections)),
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array{title:string,summary:string,html:string,sections:list<array{title:string,content:string,indicators:array<string,mixed>}>}
     */
    private function fallback(AiReportRequest $request, array $metrics): array
    {
        $totals = Arr::get($metrics, 'totaux', []);
        $summary = 'Le portefeuille analyse contient '.(Arr::get($totals, 'actions', 0)).' action(s), dont '
            .(Arr::get($totals, 'actions_en_cours', 0)).' en cours, '
            .(Arr::get($totals, 'actions_cloturees', 0)).' cloturee(s), et '
            .(Arr::get($totals, 'actions_hors_delai', 0)).' hors delai.';

        $sections = [
            ['title' => 'Resume executif', 'content' => $summary, 'indicators' => $totals],
            ['title' => 'Avancement par direction', 'content' => $this->mapText(Arr::get($metrics, 'par_direction', [])), 'indicators' => (array) Arr::get($metrics, 'par_direction', [])],
            ['title' => 'Avancement par service', 'content' => $this->mapText(Arr::get($metrics, 'par_service', [])), 'indicators' => (array) Arr::get($metrics, 'par_service', [])],
            ['title' => 'Actions hors delai', 'content' => $this->linesText(Arr::get($metrics, 'actions_hors_delai', [])), 'indicators' => ['total' => count((array) Arr::get($metrics, 'actions_hors_delai', []))]],
            ['title' => 'Recommandations IA', 'content' => 'Prioriser la revue des actions hors delai, documenter les blocages et confirmer les arbitrages dans le workflow avant diffusion.', 'indicators' => []],
        ];

        return [
            'title' => $this->title($request),
            'summary' => $summary,
            'html' => $this->htmlFromSections($sections),
            'sections' => $sections,
        ];
    }

    private function title(AiReportRequest $request): string
    {
        return match ($request->report_type) {
            AiReportRequest::TYPE_MONTHLY => 'Rapport mensuel IA',
            AiReportRequest::TYPE_QUARTERLY => 'Rapport trimestriel IA',
            AiReportRequest::TYPE_ANNUAL => 'Rapport annuel IA',
            default => 'Rapport IA',
        };
    }

    /**
     * @param  array<mixed>  $map
     */
    private function mapText(array $map): string
    {
        if ($map === []) {
            return 'Donnee non disponible.';
        }

        return collect($map)->map(fn (mixed $value, mixed $key): string => $key.' : '.$value)->implode(' ; ');
    }

    /**
     * @param  array<mixed>  $lines
     */
    private function linesText(array $lines): string
    {
        if ($lines === []) {
            return 'Aucune action hors delai dans le perimetre analyse.';
        }

        return collect($lines)
            ->map(fn (mixed $line): string => is_array($line) ? trim((string) (($line['code'] ?? '').' '.($line['libelle'] ?? ''))) : (string) $line)
            ->implode(' ; ');
    }

    /**
     * @param  list<array{title:string,content:string,indicators:array<string,mixed>}>  $sections
     */
    private function htmlFromSections(array $sections): string
    {
        return collect($sections)
            ->map(fn (array $section): string => '<section><h2>'.e($section['title']).'</h2><p>'.e($section['content']).'</p></section>')
            ->implode("\n");
    }
}
