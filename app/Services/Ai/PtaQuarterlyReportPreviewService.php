<?php

namespace App\Services\Ai;

use App\Models\AiGeneratedReport;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class PtaQuarterlyReportPreviewService
{
    /**
     * @return array<string, mixed>|null
     */
    public function build(AiGeneratedReport $report): ?array
    {
        $snapshot = $report->metrics_snapshot;
        $analysis = is_array($snapshot) ? Arr::get($snapshot, 'pta_analyse') : null;

        if ($report->report_type !== AiGeneratedReport::TYPE_PTA_QUARTERLY || ! is_array($analysis)) {
            return null;
        }

        $summary = is_array($analysis['synthese'] ?? null) ? $analysis['synthese'] : [];
        $axes = $this->rows($analysis, 'axes');
        $services = $this->rows($analysis, 'services');
        $matrix = $this->rows($analysis, 'matrice_services_axes');
        $monthly = $this->rows($analysis, 'evolution_mensuelle');
        $axisMonthly = $this->rows($analysis, 'evolution_mensuelle_axes');
        $comparison = $this->rows($analysis, 'comparaison_indicateurs');
        $gaps = is_array($analysis['ecarts'] ?? null) ? $analysis['ecarts'] : [];

        return [
            'title' => 'Apercu du modele Word trimestriel',
            'template' => basename((string) config('ai_training.reports.pta_quarterly_template_path', 'rapport_pta_trimestriel_2026.docx')),
            'period' => [
                'label' => (string) Arr::get($analysis, 'periode.libelle', 'Periode non renseignee'),
                'end_label' => $this->dateLabel(Arr::get($analysis, 'periode.fin'), 'Date de cloture non renseignee'),
            ],
            'cards' => [
                ['label' => 'Actions prevues', 'value' => $this->asNumber($summary['actions_prevues'] ?? 0)],
                ['label' => 'Actions realisees', 'value' => $this->asNumber($summary['actions_realisees'] ?? 0)],
                ['label' => 'Taux realisation', 'value' => $this->asPercent($summary['taux_realisation'] ?? 0)],
                ['label' => 'Taux avancement', 'value' => $this->asPercent($summary['taux_global_avancement'] ?? 0)],
            ],
            'tables' => [
                $this->summaryTable($summary, $axes),
                $this->indicatorComparisonTable($comparison),
                $this->serviceAxisMatrixTable($axes, $matrix),
                $this->serviceRatesTable($services),
                $this->monthlyEvolutionTable($monthly),
                $this->axisMonthlyEvolutionTable($axisMonthly),
            ],
            'charts' => $this->charts($analysis, $axes, $services, $monthly),
            'gap_sections' => $this->gapSections($gaps),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function indicatorComparisonTable(array $rows): array
    {
        return $this->table(
            'Comparaison entre avancement global et realisation des actions echues',
            ['Indicateur', 'Nombre realise', 'Base de calcul', 'Taux', 'Formule', 'Interpretation'],
            collect($rows)->map(fn (array $row): array => [
                (string) ($row['indicateur'] ?? '-'),
                $this->asNumber($row['realisees'] ?? 0),
                $this->asNumber($row['base'] ?? 0),
                $this->asPercent($row['taux'] ?? 0),
                (string) ($row['formule'] ?? '-'),
                (string) ($row['interpretation'] ?? '-'),
            ])->values()->all()
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $axes
     * @return array<string, mixed>
     */
    private function summaryTable(array $summary, array $axes): array
    {
        $rows = collect($axes)
            ->values()
            ->map(fn (array $axis, int $index): array => [
                (string) ($index + 1),
                (string) ($axis['libelle'] ?? 'Non renseigné'),
                $this->asNumber($axis['actions_prevues'] ?? 0),
                $this->asNumber($axis['actions_realisees'] ?? 0),
                $this->asNumber($axis['actions_en_retard_non_realisees'] ?? 0),
                $this->asNumber($axis['actions_non_demarrees'] ?? 0),
                $this->asNumber($axis['actions_echues'] ?? 0),
                $this->asPercent($axis['taux_global_avancement'] ?? $axis['taux_realisation'] ?? 0),
            ])
            ->all();

        return $this->table(
            '1-Progression globale du PTA de la Direction Generale',
            [
                'Axe',
                'Axes strategiques de la Direction Generale',
                'Actions prevues',
                'Actions realisees',
                'Retard/non realisees',
                'Non demarrees',
                'Echues',
                'Taux avancement',
            ],
            $rows,
            [
                'T',
                'TOTAL',
                $this->asNumber($summary['actions_prevues'] ?? 0),
                $this->asNumber($summary['actions_realisees'] ?? 0),
                $this->asNumber($summary['actions_en_retard_non_realisees'] ?? 0),
                $this->asNumber($summary['actions_non_demarrees'] ?? 0),
                $this->asNumber($summary['actions_echues'] ?? 0),
                $this->asPercent($summary['taux_global_avancement'] ?? $summary['taux_realisation'] ?? 0),
            ]
        );
    }

    /**
     * @param  list<array<string, mixed>>  $axes
     * @param  list<array<string, mixed>>  $matrix
     * @return array<string, mixed>
     */
    private function serviceAxisMatrixTable(array $axes, array $matrix): array
    {
        if ($matrix === []) {
            return $this->axisRatesTable($axes);
        }

        $axisLabels = collect($axes)
            ->map(fn (array $axis): string => (string) ($axis['libelle'] ?? 'Sans axe strategique'))
            ->unique()
            ->values()
            ->all();

        $headers = array_merge(['PTA / Service'], array_map(
            static fn (string $axis): string => $axis.' - taux / poids',
            $axisLabels
        ));

        $rows = collect($matrix)
            ->map(function (array $line) use ($axisLabels): array {
                $cells = [(string) ($line['service'] ?? 'Non renseigné')];
                $axisCells = is_array($line['axes'] ?? null) ? $line['axes'] : [];

                foreach ($axisLabels as $axisLabel) {
                    $cell = is_array($axisCells[$axisLabel] ?? null) ? $axisCells[$axisLabel] : [];
                    $cells[] = $this->asPercent($cell['taux_realisation'] ?? 0).' / '.(string) ($cell['poids'] ?? '0/0');
                }

                return $cells;
            })
            ->values()
            ->all();

        return $this->table('TAUX DE REALISATION DES AXES GLOBAUX', $headers, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $axes
     * @return array<string, mixed>
     */
    private function axisRatesTable(array $axes): array
    {
        return $this->table(
            'TAUX DE REALISATION DES AXES GLOBAUX',
            ['Axe strategique', 'Actions prevues', 'Actions echues', 'Taux de realisation', 'Statut'],
            collect($axes)
                ->map(fn (array $axis): array => [
                    (string) ($axis['libelle'] ?? 'Non renseigné'),
                    $this->asNumber($axis['actions_prevues'] ?? 0),
                    $this->asNumber($axis['actions_echues'] ?? 0),
                    $this->asPercent($axis['taux_realisation'] ?? 0),
                    $this->statusFromRate($axis['taux_realisation'] ?? 0),
                ])
                ->values()
                ->all()
        );
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @return array<string, mixed>
     */
    private function serviceRatesTable(array $services): array
    {
        return $this->table(
            'Taux de realisation du PTA de la Direction Generale',
            ['PTA', 'Taux de realisation', 'Nombre d actions echues', 'Statut'],
            collect($services)
                ->map(fn (array $service): array => [
                    (string) ($service['libelle'] ?? 'Non renseigné'),
                    $this->asPercent($service['taux_realisation'] ?? 0),
                    $this->asNumber($service['actions_echues'] ?? 0),
                    $this->statusFromRate($service['taux_realisation'] ?? 0),
                ])
                ->values()
                ->all()
        );
    }

    /**
     * @param  list<array<string, mixed>>  $monthly
     * @return array<string, mixed>
     */
    private function monthlyEvolutionTable(array $monthly): array
    {
        return $this->table(
            'Evolution du taux de realisation du PTA sur la periode',
            ['Mois', 'Actions echues', 'Actions realisees', 'Taux de realisation', 'Variation', 'Tendance'],
            collect($monthly)
                ->map(fn (array $row): array => [
                    (string) ($row['mois'] ?? 'Non renseigné'),
                    $this->asNumber($row['actions_echues'] ?? 0),
                    $this->asNumber($row['actions_realisees'] ?? 0),
                    $this->asPercent($row['taux_realisation'] ?? 0),
                    (($row['variation'] ?? 0) > 0 ? '+' : '').(string) ($row['variation'] ?? 0).' point(s)',
                    (string) ($row['tendance'] ?? 'Stagnation'),
                ])
                ->values()
                ->all()
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function axisMonthlyEvolutionTable(array $rows): array
    {
        $months = collect($rows)->first()['mois'] ?? [];

        return $this->table(
            'Evolution mensuelle des axes strategiques',
            array_merge(['Axe'], collect($months)->pluck('mois')->map(fn ($month): string => (string) $month)->all(), ['Evolution']),
            collect($rows)->map(function (array $row): array {
                return array_merge(
                    [(string) ($row['axe'] ?? 'Non renseigné')],
                    collect($row['mois'] ?? [])->map(fn (array $month): string => $this->asPercent($month['taux'] ?? 0))->all(),
                    [(string) ($row['evolution'] ?? 0).' point(s)']
                );
            })->values()->all()
        );
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  list<array<string, mixed>>  $axes
     * @param  list<array<string, mixed>>  $services
     * @param  list<array<string, mixed>>  $monthly
     * @return list<array<string, mixed>>
     */
    private function charts(array $analysis, array $axes, array $services, array $monthly): array
    {
        $charts = is_array($analysis['graphiques'] ?? null) ? $analysis['graphiques'] : [];

        return [
            $this->chart(
                'Progression des axes du PTA sur la periode',
                is_array($charts['taux_axes'] ?? null) ? $charts['taux_axes'] : $this->seriesFromRows($axes, 'libelle', 'taux_realisation')
            ),
            $this->chart(
                'Evolution du taux de realisation des axes strategiques du DG',
                is_array($charts['taux_services'] ?? null) ? $charts['taux_services'] : $this->seriesFromRows($services, 'libelle', 'taux_realisation')
            ),
            $this->chart(
                'EVOLUTION DU TAUX DE REALISATION DU PTA DU DG',
                is_array($charts['evolution_trimestre'] ?? null) ? $charts['evolution_trimestre'] : $this->seriesFromRows($monthly, 'mois', 'taux_realisation')
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $gaps
     * @return list<array<string, mixed>>
     */
    private function gapSections(array $gaps): array
    {
        return [
            $this->gapSection('Actions non realisees dans le trimestre', $this->gapRows($gaps, 'actions_non_realisees')),
            $this->gapSection('Actions partiellement realisees', $this->gapRows($gaps, 'actions_partielles')),
            $this->gapSection('Activites reportees', $this->gapRows($gaps, 'actions_reportees')),
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  list<string>|null  $footer
     * @return array<string, mixed>
     */
    private function table(string $title, array $headers, array $rows, ?array $footer = null): array
    {
        return [
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows,
            'footer' => $footer,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function rows(array $source, string $key): array
    {
        $rows = $source[$key] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{labels:list<string>,values:list<float>}
     */
    private function seriesFromRows(array $rows, string $labelKey, string $valueKey): array
    {
        return [
            'labels' => collect($rows)->map(fn (array $row): string => (string) ($row[$labelKey] ?? 'Non renseigné'))->values()->all(),
            'values' => collect($rows)->map(fn (array $row): float => (float) ($row[$valueKey] ?? 0))->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $series
     * @return array<string, mixed>
     */
    private function chart(string $title, array $series): array
    {
        $labels = is_array($series['labels'] ?? null) ? array_values($series['labels']) : [];
        $values = is_array($series['values'] ?? null) ? array_values($series['values']) : [];
        $points = [];

        foreach ($labels as $index => $label) {
            $value = (float) ($values[$index] ?? 0);
            $points[] = [
                'label' => (string) $label,
                'value' => $value,
                'display' => $this->asPercent($value),
                'width' => max(0, min(100, $value)),
            ];
        }

        return [
            'title' => $title,
            'points' => $points,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function gapSection(string $title, array $rows): array
    {
        return [
            'title' => $title,
            'rows' => collect($rows)
                ->map(fn (array $row): array => [
                    'libelle' => (string) ($row['libelle'] ?? 'Non renseigné'),
                    'responsable' => (string) ($row['responsable'] ?? 'Non renseigné'),
                    'date_fin' => (string) ($row['date_fin'] ?? '-'),
                    'statut' => (string) ($row['statut'] ?? '-'),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $gaps
     * @return list<array<string, mixed>>
     */
    private function gapRows(array $gaps, string $key): array
    {
        return $this->rows($gaps, $key);
    }

    private function asNumber(mixed $value): string
    {
        return number_format((float) $value, 0, '.', ' ');
    }

    private function asPercent(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').' %';
    }

    private function statusFromRate(mixed $rate): string
    {
        $rate = (float) $rate;

        return match (true) {
            $rate >= 80 => 'Tres satisfaisant',
            $rate >= 60 => 'Satisfaisant',
            $rate >= 40 => 'Moyen',
            $rate >= 20 => 'Faible',
            default => 'Critique',
        };
    }

    private function dateLabel(mixed $value, string $fallback): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
