<?php

namespace App\Services\Exports;

use App\Support\Zip\SimpleZipWriter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class ReportingCsvExporter
{
    public function __construct(
        private readonly SimpleZipWriter $zipWriter = new SimpleZipWriter()
    ) {
    }

    public function create(array $payload): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'anbg_csv_');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to allocate temporary file for CSV export.');
        }

        $entries = [
            '00_index_direction_service.csv' => $this->csv(
                ['Direction', 'Service', 'Total actions', 'Terminees', 'En cours', 'En retard', 'Performance (%)'],
                $this->summaryRows($payload)
            ),
            '01_strategie.csv' => $this->csv(
                ['N° Axe', 'Axe strategique', 'N° Objectif', 'Objectif strategique', 'Echeance'],
                $this->strategyRows($payload)
            ),
            '02_pao.csv' => $this->csv(
                ['Direction', 'Service', 'Axe strategique', 'Objectif strategique', 'Objectif operationnel', 'Action', 'Responsable', 'Echeance'],
                $this->paoRows($payload)
            ),
            '03_actions.csv' => $this->csv(
                ['Direction', 'Service', 'Description action', 'RMO', 'Cible', 'Debut', 'Fin', 'Statut', 'Ressources', 'Taux (%)', 'Justificatif', 'Risque'],
                $this->actionRowsForCsv($payload)
            ),
            '04_kpi.csv' => $this->csv(
                ['Direction', 'Service', 'Action', 'RMO', 'KPI Performance (%)', 'KPI Qualite (%)', 'KPI Delai (%)', 'KPI Risque (%)', 'KPI Conformite (%)', 'KPI Global (%)'],
                $this->kpiRows($payload)
            ),
            '05_synthese.csv' => $this->csv(
                ['Direction', 'Service', 'Total actions', 'Terminees', 'En cours', 'En retard', 'Performance (%)'],
                $this->summaryRows($payload)
            ),
            '06_alertes.csv' => $this->csv(
                ['Action', 'Indicateur', 'Valeur', 'Seuil', 'Statut', 'Action corrective'],
                $this->alertRows($payload)
            ),
            '07_risques.csv' => $this->csv(
                ['Direction', 'Service', 'Action', 'Risque', 'Niveau', 'Impact', 'Solution', 'Responsable'],
                $this->riskRows($payload)
            ),
            '08_rmo_performance.csv' => $this->csv(
                ['Direction', 'Service', 'RMO', 'Nombre d actions', 'Performance moyenne (%)'],
                $this->rmoPerformanceRows($payload)
            ),
            '09_justificatifs.csv' => $this->csv(
                ['Direction', 'Service', 'Action', 'RMO', 'Justificatif', 'Statut validation', 'Date'],
                $this->justificatifRows($payload)
            ),
        ];

        foreach ($this->serviceCsvEntries($payload) as $name => $contents) {
            $entries[$name] = $contents;
        }

        try {
            $this->zipWriter->write($tempPath, $entries);
        } catch (\Throwable $exception) {
            @unlink($tempPath);

            throw $exception;
        }

        return $tempPath;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to open temporary CSV stream.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ';');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? '' : $contents;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function strategyRows(array $payload): array
    {
        return $this->actionRows($payload)
            ->map(fn (array $row): array => [
                (string) (($row['axe_numero'] ?? '') !== '' ? $row['axe_numero'] : '-'),
                (string) ($row['axe_strategique'] ?? $row['axe'] ?? '-'),
                (string) (($row['objectif_strategique_numero'] ?? '') !== '' ? $row['objectif_strategique_numero'] : '-'),
                (string) ($row['objectif_strategique'] ?? $row['objectif'] ?? '-'),
                (string) ($row['echeance_strategique'] ?? ''),
            ])
            ->unique(fn (array $row): string => implode('|', $row))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function paoRows(array $payload): array
    {
        return $this->actionRows($payload)
            ->map(fn (array $row): array => [
                (string) ($row['direction_label'] ?? '-'),
                (string) ($row['service_label'] ?? '-'),
                (string) ($row['axe_strategique'] ?? $row['axe'] ?? '-'),
                (string) ($row['objectif_strategique'] ?? $row['objectif'] ?? '-'),
                (string) ($row['objectif_operationnel'] ?? '-'),
                (string) ($row['action'] ?? '-'),
                (string) ($row['rmo'] ?? $row['responsable'] ?? '-'),
                (string) ($row['echeance'] ?? $row['fin'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function summaryRows(array $payload): array
    {
        return $this->serviceReports($payload)
            ->flatMap(function (array $direction): array {
                $directionLabel = $this->entityLabel($direction, 'Direction');

                return collect($direction['services'] ?? [])
                    ->map(function (array $service) use ($directionLabel): array {
                        $summary = (array) ($service['summary'] ?? []);

                        return [
                            $directionLabel,
                            $this->entityLabel($service, 'Service'),
                            (int) ($summary['actions_total'] ?? 0),
                            (int) ($summary['actions_terminees'] ?? 0),
                            (int) ($summary['actions_en_cours'] ?? 0),
                            (int) ($summary['actions_retard'] ?? 0),
                            (float) ($summary['taux_realisation'] ?? 0),
                        ];
                    })
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function alertRows(array $payload): array
    {
        $kpiRows = collect($payload['details']['kpi_sous_seuil'] ?? [])
            ->map(fn ($mesure): array => [
                (string) ($mesure->kpi?->action?->libelle ?? '-'),
                (string) ($mesure->kpi?->libelle ?? '-'),
                (float) ($mesure->valeur ?? 0),
                (float) ($mesure->kpi?->seuil_alerte ?? 0),
                'Alerte',
                'Verifier la mesure, documenter l ecart et proposer une action corrective.',
            ]);

        $lateRows = collect($payload['details']['actions_retard'] ?? [])
            ->map(fn ($action): array => [
                (string) ($action->libelle ?? '-'),
                'Retard action',
                (float) ($action->progression_reelle ?? 0),
                100.0,
                'En retard',
                'Replanifier, lever les blocages et mettre a jour la progression.',
            ]);

        return $kpiRows->merge($lateRows)->values()->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function actionRowsForCsv(array $payload): array
    {
        return $this->actionRows($payload)
            ->map(fn (array $row): array => [
                (string) ($row['direction_label'] ?? '-'),
                (string) ($row['service_label'] ?? '-'),
                (string) ($row['description_action'] ?? $row['action'] ?? '-'),
                (string) ($row['rmo'] ?? $row['responsable'] ?? '-'),
                (string) ($row['cible'] ?? '-'),
                (string) ($row['debut'] ?? ''),
                (string) ($row['fin'] ?? ''),
                (string) ($row['statut'] ?? '-'),
                (string) ($row['ressources_requises'] ?? '-'),
                (float) ($row['progression_value'] ?? 0),
                (string) ($row['justificatif'] ?? '-'),
                (string) ($row['risque_identifie'] ?? '-'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function kpiRows(array $payload): array
    {
        return $this->actionRows($payload)
            ->map(fn (array $row): array => [
                (string) ($row['direction_label'] ?? '-'),
                (string) ($row['service_label'] ?? '-'),
                (string) ($row['action'] ?? '-'),
                (string) ($row['rmo'] ?? $row['responsable'] ?? '-'),
                (float) ($row['kpi_performance_value'] ?? 0),
                (float) ($row['kpi_qualite_value'] ?? 0),
                (float) ($row['kpi_delai_value'] ?? 0),
                (float) ($row['kpi_risque_value'] ?? 0),
                (float) ($row['kpi_conformite_value'] ?? 0),
                (float) ($row['kpi_global_value'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function riskRows(array $payload): array
    {
        return $this->actionRows($payload)
            ->filter(fn (array $row): bool => trim((string) ($row['risque_identifie'] ?? '')) !== '')
            ->map(fn (array $row): array => [
                (string) ($row['direction_label'] ?? '-'),
                (string) ($row['service_label'] ?? '-'),
                (string) ($row['action'] ?? '-'),
                (string) ($row['risque_identifie'] ?? '-'),
                (string) ($row['niveau_risque'] ?? '-'),
                (float) ($row['kpi_risque_value'] ?? 0),
                (string) ($row['mesure_mitigation'] ?? '-'),
                (string) ($row['rmo'] ?? $row['responsable'] ?? '-'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function rmoPerformanceRows(array $payload): array
    {
        return $this->actionRows($payload)
            ->groupBy(fn (array $row): string => implode('|', [
                (string) ($row['direction_label'] ?? '-'),
                (string) ($row['service_label'] ?? '-'),
                (string) ($row['rmo'] ?? $row['responsable'] ?? 'Non renseigne'),
            ]))
            ->map(function (Collection $rows, string $key): array {
                [$directionLabel, $serviceLabel, $rmo] = array_pad(explode('|', $key, 3), 3, 'Non renseigne');

                return [
                    $directionLabel !== '' ? $directionLabel : '-',
                    $serviceLabel !== '' ? $serviceLabel : '-',
                    $rmo !== '' ? $rmo : 'Non renseigne',
                    $rows->count(),
                    round((float) $rows->avg(fn (array $row): float => (float) ($row['kpi_performance_value'] ?? 0)), 2),
                ];
            })
            ->sortBy(fn (array $row): string => (string) $row[0].'|'.(string) $row[1].'|'.sprintf('%09.2f', 10000 - (float) $row[4]))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function justificatifRows(array $payload): array
    {
        return $this->actionRows($payload)
            ->flatMap(function (array $row): array {
                $justificatifs = (array) ($row['justificatifs'] ?? []);
                if ($justificatifs === []) {
                    return [[
                        (string) ($row['direction_label'] ?? '-'),
                        (string) ($row['service_label'] ?? '-'),
                        (string) ($row['action'] ?? '-'),
                        (string) ($row['rmo'] ?? $row['responsable'] ?? '-'),
                        '-',
                        (string) ($row['statut_validation'] ?? '-'),
                        '',
                    ]];
                }

                return collect($justificatifs)
                    ->map(fn (array $justificatif): array => [
                        (string) ($row['direction_label'] ?? '-'),
                        (string) ($row['service_label'] ?? '-'),
                        (string) ($row['action'] ?? '-'),
                        (string) ($row['rmo'] ?? $row['responsable'] ?? '-'),
                        (string) ($justificatif['nom'] ?? '-'),
                        (string) ($row['statut_validation'] ?? '-'),
                        (string) ($justificatif['date'] ?? ''),
                    ])
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function serviceCsvEntries(array $payload): array
    {
        $date = $payload['generatedAt'] instanceof CarbonInterface
            ? $payload['generatedAt']->format('Ymd_His')
            : now()->format('Ymd_His');
        $entries = [];

        foreach ($this->serviceReports($payload) as $direction) {
            foreach ((array) ($direction['services'] ?? []) as $service) {
                $rows = collect($service['actions'] ?? [])
                    ->map(fn (array $row): array => [
                        (string) ($row['axe_strategique'] ?? $row['axe'] ?? '-'),
                        (string) ($row['objectif_strategique'] ?? $row['objectif'] ?? '-'),
                        (string) ($row['objectif_operationnel'] ?? '-'),
                        (string) ($row['action'] ?? '-'),
                        (string) ($row['kpi'] ?? '-'),
                        (string) ($row['prevu'] ?? '-'),
                        (string) ($row['realise'] ?? '-'),
                        (string) ($row['taux'] ?? '-'),
                        (string) ($row['statut'] ?? '-'),
                    ])
                    ->values()
                    ->all();

                $filename = sprintf(
                    'services/RAPPORT_REPORTING_%s_%s_%s.csv',
                    $this->filenameToken($this->entityLabel($direction, 'Direction')),
                    $this->filenameToken($this->entityLabel((array) $service, 'Service')),
                    $date
                );
                $entries[$filename] = $this->csv(
                    ['Axe strategique', 'Objectif strategique', 'Objectif operationnel', 'Action', 'KPI', 'Prevu', 'Realise', 'Taux', 'Statut'],
                    $rows
                );
            }
        }

        return $entries;
    }

    private function serviceReports(array $payload): Collection
    {
        return collect($payload['details']['direction_service_report'] ?? []);
    }

    private function actionRows(array $payload): Collection
    {
        return $this->serviceReports($payload)
            ->flatMap(function (array $direction): array {
                $directionLabel = $this->entityLabel($direction, 'Direction');
                $directionResponsable = (string) ($direction['responsable'] ?? '-');

                return collect($direction['services'] ?? [])
                    ->flatMap(function (array $service) use ($directionLabel, $directionResponsable): array {
                        $serviceLabel = $this->entityLabel($service, 'Service');
                        $serviceResponsable = (string) ($service['responsable'] ?? '-');

                        return collect($service['actions'] ?? [])
                            ->map(fn (array $row): array => array_merge($row, [
                                'direction_label' => $directionLabel,
                                'direction_responsable' => $directionResponsable,
                                'service_label' => $serviceLabel,
                                'service_responsable' => $serviceResponsable,
                            ]))
                            ->all();
                    })
                    ->all();
            })
            ->values();
    }

    private function entityLabel(array $entity, string $fallback): string
    {
        $code = trim((string) ($entity['code'] ?? ''));
        $name = trim((string) ($entity['libelle'] ?? $fallback));

        return $code !== '' ? $code.' - '.$name : $name;
    }

    private function filenameToken(string $value): string
    {
        $token = (string) Str::of($value)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9]+/', '_')
            ->trim('_');

        return $token !== '' ? $token : 'GLOBAL';
    }
}
