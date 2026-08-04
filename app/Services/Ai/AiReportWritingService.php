<?php

namespace App\Services\Ai;

use App\Models\AiGeneratedReport;
use App\Models\User;
use App\Services\OpenAi\OpenAiClientService;
use Illuminate\Support\Carbon;
use RuntimeException;

class AiReportWritingService
{
    public function __construct(
        private readonly PtaQuarterlyNarrativeBuilder $ptaNarratives,
        private readonly OpenAiClientService $openAi,
        private readonly ReportTemplateConformityService $conformity
    ) {}

    /**
     * @param  array<string,mixed>  $metrics
     * @return array{content:string,model:string,input_tokens:int,output_tokens:int,total_cost_usd:float,conformity:array<string,mixed>}
     */
    public function generate(string $title, string $reportType, array $metrics, ?User $user = null): array
    {
        if (! $this->openAi->available()) {
            throw new RuntimeException('La generation IA requiert une cle OPENAI_API_KEY valide. Aucun autre fournisseur ni brouillon automatique ne sera utilise.');
        }

        $template = $this->conformity->template($reportType);
        $response = $this->openAi->createStructuredResponse(
            'anbg_report_'.$reportType,
            $this->openAiPrompt($title, $reportType, $metrics, $template),
            $this->openAiSchema($template),
            $user,
            'ai_reporting',
            highCapability: true
        );
        $sections = collect($response['data']['sections'] ?? [])
            ->map(fn (mixed $section): array => is_array($section) ? [
                'key' => (string) ($section['key'] ?? ''),
                'title' => (string) ($section['title'] ?? ''),
                'content' => (string) ($section['content'] ?? ''),
            ] : ['key' => '', 'title' => '', 'content' => ''])
            ->all();
        $content = $this->conformity->compose($title, $sections);
        $inspection = $this->conformity->inspect($reportType, $content);

        if ($inspection['status'] !== AiGeneratedReport::CONFORMITY_CONFORMING) {
            throw new RuntimeException('OpenAI a produit un rapport non conforme au modele : '.implode(' ', $inspection['issues']));
        }

        return [
            'content' => $content,
            'model' => $response['model'],
            'input_tokens' => $response['input_tokens'],
            'output_tokens' => $response['output_tokens'],
            'total_cost_usd' => $response['total_cost_usd'],
            'conformity' => $inspection,
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public function draft(string $title, string $reportType, array $metrics): string
    {
        if ($reportType === AiGeneratedReport::TYPE_PTA_QUARTERLY && is_array($metrics['pta_analyse'] ?? null)) {
            return $this->draftPtaQuarterly($title, $metrics);
        }

        $totals = $metrics['totaux'] ?? [];
        $actions = $totals['actions'] ?? 'Donnee non disponible';
        $running = $totals['actions_en_cours'] ?? 'Donnee non disponible';
        $closed = $totals['actions_cloturees'] ?? 'Donnee non disponible';
        $late = $totals['actions_hors_delai'] ?? 'Donnee non disponible';
        $progress = $totals['progression_moyenne'] ?? 'Donnee non disponible';
        $budget = $totals['budget_previsionnel'] ?? 'Donnee non disponible';

        return implode("\n\n", [
            '# '.$title,
            'Type de rapport : '.(AiGeneratedReport::reportTypes()[$reportType] ?? $reportType),
            'Resume executif : le portefeuille analyse contient '.$actions.' action(s), dont '.$running.' en cours, '.$closed.' cloturee(s) et '.$late.' hors delai.',
            'Methodologie : les chiffres proviennent exclusivement du snapshot Laravel joint au rapport. Aucune valeur externe n est ajoutee.',
            'Situation globale : la progression moyenne observee est de '.$progress.' %. Le budget previsionnel total renseigne est de '.$budget.'.',
            'Analyse par statut : '.$this->formatMap($metrics['par_statut'] ?? []),
            'Analyse par direction : '.$this->formatMap($metrics['par_direction'] ?? []),
            'Analyse par service : '.$this->formatMap($metrics['par_service'] ?? []),
            'Actions hors delai : '.$this->formatLines($metrics['actions_hors_delai'] ?? []),
            'Risques et alertes : '.$this->formatSimpleList($metrics['alertes'] ?? []),
            'Recommandations : prioriser les actions hors delai, documenter les difficultes et valider les corrections dans le circuit metier avant diffusion.',
            'Conclusion : ce brouillon doit etre relu, ajuste puis valide par un utilisateur habilite avant export officiel.',
            'Annexe chiffree : '.json_encode($metrics['totaux'] ?? [], JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $template
     */
    private function openAiPrompt(string $title, string $reportType, array $metrics, array $template): string
    {
        return implode("\n\n", [
            'Tu rediges un rapport institutionnel professionnel pour l ANBG.',
            'Utilise exclusivement les donnees du snapshot Laravel. N invente aucun chiffre, nom, date, resultat ou causalite. Signale explicitement toute donnee absente.',
            'Respecte exactement les sections, leurs cles et leur ordre. Chaque section doit etre detaillee, humaine, factuelle et orientee decision.',
            'Titre='.$title,
            'Type='.$reportType,
            'Modele='.json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'SNAPSHOT_LARAVEL='.json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param  array<string,mixed>  $template
     * @return array<string,mixed>
     */
    private function openAiSchema(array $template): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['sections'],
            'properties' => [
                'sections' => [
                    'type' => 'array',
                    'minItems' => count($template['sections']),
                    'maxItems' => count($template['sections']),
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'title', 'content'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function formatMap(array $map): string
    {
        if ($map === []) {
            return 'Donnee non disponible.';
        }

        return collect($map)
            ->map(fn (mixed $value, string $key): string => $key.' : '.$value)
            ->implode(' ; ');
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function formatLines(array $lines): string
    {
        if ($lines === []) {
            return 'Aucune action hors delai dans le snapshot.';
        }

        return collect($lines)
            ->map(fn (array $line): string => trim(($line['code'] ?? '').' '.($line['libelle'] ?? '').' - '.($line['date_fin'] ?? '')))
            ->implode(' ; ');
    }

    /**
     * @param  list<string>  $items
     */
    private function formatSimpleList(array $items): string
    {
        return $items === [] ? 'Aucune alerte dans le snapshot.' : implode(' ; ', $items);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function draftPtaQuarterly(string $title, array $metrics): string
    {
        $analysis = $metrics['pta_analyse'] ?? [];
        $summary = $analysis['synthese'] ?? [];
        $period = $analysis['periode']['libelle'] ?? 'Periode non renseignee';
        $periodMonths = $this->periodMonthsLabel($analysis);
        $year = $this->reportYear($analysis);
        $axes = is_array($analysis['axes'] ?? null) ? $analysis['axes'] : [];
        $services = is_array($analysis['services'] ?? null) ? $analysis['services'] : [];
        $monthly = is_array($analysis['evolution_mensuelle'] ?? null) ? $analysis['evolution_mensuelle'] : [];
        $narrative = $this->ptaNarratives->build($analysis);

        return implode("\n\n", array_filter([
            '# RAPPORT TRIMESTRIEL '.$year,
            $periodMonths,
            'SUIVI ET EVALUATION',
            'Titre du rapport : '.$title,
            'Source des donnees : snapshot Laravel calcule sur les actions PTA visibles dans le perimetre selectionne.',
            'Sommaire',
            '1-PROGRESSION GLOBALE DU PTA DE LA DIRECTION GENERALE',
            '2- Taux de realisation des axes strategiques de la Direction Generale',
            '3- Evolution des taux de realisation des axes strategiques de la DG',
            '4- Taux de realisation du PTA de la Direction Generale au '.$this->periodEndLabel($analysis),
            '5- Evolution du taux de realisation du PTA de la Direction Generale sur la periode '.$period,
            '6- Analyse des ecarts constates',
            '7. Mesures correctives proposees',
            '1-Progression globale du PTA de la Direction Generale.',
            $narrative['progression_globale'],
            implode("\n", $narrative['axes']),
            '2-Taux de realisation des axes strategiques de la Direction Generale.',
            'TAUX DE REALISATION DES AXES GLOBAUX : '.$this->formatQuarterRows($axes, 'libelle', 'taux_realisation'),
            $narrative['taux_axes'],
            '3-Evolution des taux de realisation des axes strategiques de la DG',
            $narrative['evolution_axes'],
            '4- Taux de realisation du PTA de la Direction Generale au '.$this->periodEndLabel($analysis),
            $this->formatQuarterRows($services, 'libelle', 'taux_realisation'),
            $narrative['taux_pta'],
            '5-Evolution du taux de realisation du PTA de la Direction Generale sur la periode '.$period,
            $this->formatQuarterRows($monthly, 'mois', 'taux_realisation'),
            $narrative['evolution_pta'],
            '6-Analyse des ecarts constates.',
            implode("\n", $narrative['ecarts']),
            $this->formatQuarterGaps($analysis['ecarts'] ?? []),
            $narrative['causes_ecarts'],
            '7. Mesures correctives proposees :',
            $narrative['mesures_correctives_intro'],
            $this->formatSimpleList($narrative['mesures_correctives']),
            'Le Gestionnaire Suivi-Evaluation Senior',
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $axes
     */
    private function formatAxisNarrative(array $axes): string
    {
        if ($axes === []) {
            return 'Aucun axe strategique n est disponible dans le snapshot.';
        }

        return collect($axes)
            ->map(function (array $axis, int $index): string {
                return 'L axe strategique N '.($index + 1).' : "'.($axis['libelle'] ?? 'Non renseigné').'" compte '.($axis['actions_prevues'] ?? 0).' action(s) prevue(s), '.($axis['actions_realisees'] ?? 0).' action(s) realisee(s), '.($axis['actions_en_retard_non_realisees'] ?? 0).' action(s) en retard ou non realisee(s), et affiche un taux de realisation de '.($axis['taux_realisation'] ?? 0).' %.';
            })
            ->implode("\n");
    }

    /**
     * @param  list<array<string, mixed>>  $axes
     * @param  list<array<string, mixed>>  $monthly
     */
    private function formatEvolutionNarrative(array $axes, array $monthly): string
    {
        $monthText = $this->formatQuarterRows($monthly, 'mois', 'taux_realisation');
        $axisText = collect($axes)
            ->map(fn (array $axis): string => ($axis['libelle'] ?? 'Non renseigné').' : '.($axis['taux_realisation'] ?? 0).' %')
            ->implode(' ; ');

        return 'Sur la periode analysee, les taux disponibles par axe sont les suivants : '.($axisText !== '' ? $axisText : 'donnee non disponible').'. Evolution mensuelle consolidee : '.$monthText;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function countLabel(array $rows, string $label): string
    {
        $count = count($rows);

        return $count.' '.$label.($count > 1 ? 's' : '');
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function periodMonthsLabel(array $analysis): string
    {
        $start = $analysis['periode']['debut'] ?? null;
        $end = $analysis['periode']['fin'] ?? null;

        if (! is_string($start) || ! is_string($end)) {
            return 'PERIODE NON RENSEIGNEE';
        }

        try {
            $cursor = Carbon::parse($start)->startOfMonth();
            $last = Carbon::parse($end)->startOfMonth();
            $months = [];
            while ($cursor->lte($last)) {
                $months[] = $cursor->locale('fr')->translatedFormat('F');
                $cursor->addMonth();
            }

            return mb_strtoupper(implode('-', $months));
        } catch (\Throwable) {
            return 'PERIODE NON RENSEIGNEE';
        }
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function reportYear(array $analysis): string
    {
        $end = (string) ($analysis['periode']['fin'] ?? '');

        return preg_match('/20\d{2}/', $end, $matches) === 1 ? $matches[0] : now()->format('Y');
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function periodEndLabel(array $analysis): string
    {
        $end = $analysis['periode']['fin'] ?? null;
        if (! is_string($end)) {
            return 'la date de cloture';
        }

        try {
            return Carbon::parse($end)->format('d/m/Y');
        } catch (\Throwable) {
            return 'la date de cloture';
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function formatQuarterRows(array $rows, string $labelKey, string $rateKey): string
    {
        if ($rows === []) {
            return 'Donnee non disponible.';
        }

        return collect($rows)
            ->map(fn (array $row): string => ($row[$labelKey] ?? 'Non renseigné').' : '.($row[$rateKey] ?? 0).' %')
            ->implode(' ; ');
    }

    /**
     * @param  array<string, mixed>  $gaps
     */
    private function formatQuarterGaps(array $gaps): string
    {
        $parts = [];
        foreach ([
            'actions_non_realisees' => 'Actions non realisees',
            'actions_partielles' => 'Actions partiellement realisees',
            'actions_reportees' => 'Actions reportees',
        ] as $key => $label) {
            $rows = is_array($gaps[$key] ?? null) ? $gaps[$key] : [];
            $parts[] = $label.' : '.$this->formatLines($rows);
        }

        return implode(' ', $parts);
    }
}
