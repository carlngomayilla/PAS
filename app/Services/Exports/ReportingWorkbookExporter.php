<?php

namespace App\Services\Exports;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class ReportingWorkbookExporter
{
    public function create(array $payload): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required to build XLSX exports.');
        }

        $sheets = $this->buildSheets($payload);
        $tempPath = tempnam(sys_get_temp_dir(), 'anbg_xlsx_');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to allocate temporary file for XLSX export.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempPath);
            throw new RuntimeException('Unable to open XLSX archive for writing.');
        }

        $sheetNames = array_map(static fn (array $sheet): string => (string) $sheet['name'], $sheets);
        $drawingCount = count(array_filter($sheets, static fn (array $sheet): bool => ! empty($sheet['charts'] ?? [])));
        $chartCount = array_sum(array_map(static fn (array $sheet): int => count($sheet['charts'] ?? []), $sheets));

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheets), $drawingCount, $chartCount));
        $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
        $zip->addFromString('docProps/app.xml', $this->appPropertiesXml($sheetNames));
        $zip->addFromString('docProps/core.xml', $this->corePropertiesXml($payload['generatedAt'] ?? null));
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheetNames));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml(count($sheets)));
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        $drawingIndex = 1;
        $chartIndex = 1;
        foreach ($sheets as $index => $sheet) {
            $hasCharts = ! empty($sheet['charts'] ?? []);
            $zip->addFromString(
                'xl/worksheets/sheet'.($index + 1).'.xml',
                $this->sheetXml(
                    $sheet['rows'],
                    $sheet['merges'],
                    (int) $sheet['maxColumns'],
                    $sheet['widths'] ?? [],
                    $hasCharts ? 'rId1' : null
                )
            );

            if (! $hasCharts) {
                continue;
            }

            $charts = (array) ($sheet['charts'] ?? []);
            $zip->addFromString(
                'xl/worksheets/_rels/sheet'.($index + 1).'.xml.rels',
                $this->sheetRelationshipsXml($drawingIndex)
            );
            $zip->addFromString(
                'xl/drawings/drawing'.$drawingIndex.'.xml',
                $this->drawingXml($charts)
            );
            $zip->addFromString(
                'xl/drawings/_rels/drawing'.$drawingIndex.'.xml.rels',
                $this->drawingRelationshipsXml($chartIndex, count($charts))
            );

            foreach ($charts as $chart) {
                $zip->addFromString(
                    'xl/charts/chart'.$chartIndex.'.xml',
                    $this->chartXml($chart, $chartIndex)
                );
                $chartIndex++;
            }

            $drawingIndex++;
        }

        $zip->close();

        return $tempPath;
    }

    private function buildSheets(array $payload): array
    {
        return [
            $this->buildDetailSheet($payload),
            $this->buildGraphicSheet($payload),
        ];
    }

    private function buildDetailSheet(array $payload): array
    {
        $sections = $this->buildSections($payload);
        $maxColumns = max(13, $this->maxColumns($sections));
        $rows = [];
        $merges = [];
        $rowIndex = 1;

        $rows[] = $this->makeMergedRow($rowIndex, 'Reporting consolide ANBG', $maxColumns, 1);
        $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
        $rowIndex++;

        $generatedAt = $payload['generatedAt'] instanceof CarbonInterface
            ? $payload['generatedAt']->format('Y-m-d H:i:s')
            : (string) ($payload['generatedAt'] ?? '');
        $scope = $payload['scope'] ?? [];
        $meta = sprintf(
            'Genere le %s | Role: %s | Direction: %s | Service: %s',
            $generatedAt,
            (string) ($scope['role'] ?? '-'),
            (string) ($scope['direction_id'] ?? '-'),
            (string) ($scope['service_id'] ?? '-')
        );

        $rows[] = $this->makeMergedRow($rowIndex, $meta, $maxColumns, 2);
        $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
        $rowIndex += 2;

        foreach ($sections as $section) {
            $rows[] = $this->makeMergedRow($rowIndex, (string) $section['title'], $maxColumns, 3);
            $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
            $rowIndex++;

            $headers = (array) $section['headers'];
            $rows[] = $this->makeStandardRow($rowIndex, $headers, array_fill(0, count($headers), 'string'), 4);
            $rowIndex++;

            $types = (array) ($section['types'] ?? []);
            foreach ((array) $section['rows'] as $dataRowIndex => $dataRow) {
                $rows[] = $this->makeDataRow($rowIndex, is_array($dataRow) ? $dataRow : [], $types, ((int) $dataRowIndex % 2) === 0);
                $rowIndex++;
            }

            $rowIndex++;
        }

        return [
            'name' => 'Reporting',
            'rows' => $rows,
            'merges' => $merges,
            'maxColumns' => $maxColumns,
            'widths' => $this->defaultWidths($maxColumns),
        ];
    }

    private function buildGraphicSheet(array $payload): array
    {
        $maxColumns = 25;
        $rows = [];
        $merges = [];
        $rowIndex = 1;
        $chartsMeta = [];

        $generatedAt = $payload['generatedAt'] instanceof CarbonInterface
            ? $payload['generatedAt']->format('Y-m-d H:i:s')
            : (string) ($payload['generatedAt'] ?? '');
        $scope = $payload['scope'] ?? [];

        $rows[] = $this->makeMergedRow($rowIndex, 'Synthese graphique', $maxColumns, 1);
        $merges[] = 'A'.$rowIndex.':H'.$rowIndex;
        $rowIndex++;

        $rows[] = $this->makeMergedRow(
            $rowIndex,
            sprintf(
                'Genere le %s | Role: %s | Direction: %s | Service: %s',
                $generatedAt,
                (string) ($scope['role'] ?? '-'),
                (string) ($scope['direction_id'] ?? '-'),
                (string) ($scope['service_id'] ?? '-')
            ),
            $maxColumns,
            2
        );
        $merges[] = 'A'.$rowIndex.':H'.$rowIndex;
        $rowIndex += 2;

        $cardRanges = [[1, 2], [3, 4], [5, 6], [7, 8]];
        $cardsGroups = [
            [
                ['Actions', (int) ($payload['global']['actions_total'] ?? 0), 13, 14],
                ['Validees', (int) ($payload['global']['actions_validees'] ?? 0), 15, 16],
                ['KPI mesures', (int) ($payload['global']['kpi_mesures_total'] ?? 0), 17, 18],
                ['Obj. op.', (int) ($payload['global']['objectifs_operationnels_total'] ?? 0), 19, 20],
            ],
            [
                ['PAS', (int) ($payload['global']['pas_total'] ?? 0), 19, 20],
                ['PAO', (int) ($payload['global']['paos_total'] ?? 0), 13, 14],
                ['PTA', (int) ($payload['global']['ptas_total'] ?? 0), 15, 16],
                ['Retards', (int) ($payload['alertes']['actions_en_retard'] ?? 0), 17, 18],
            ],
        ];
        foreach ($cardsGroups as $cards) {
            $headerCells = [];
            $valueCells = [];

            foreach ($cards as $index => [$label, $value, $headerStyle, $valueStyle]) {
                [$startColumn, $endColumn] = $cardRanges[$index];
                $headerCells[] = [
                    'ref' => $this->columnName($startColumn).$rowIndex,
                    'type' => 'string',
                    'value' => (string) $label,
                    'style' => $headerStyle,
                ];
                $valueCells[] = [
                    'ref' => $this->columnName($startColumn).($rowIndex + 1),
                    'type' => 'integer',
                    'value' => (int) $value,
                    'style' => $valueStyle,
                ];
                $merges[] = $this->columnName($startColumn).$rowIndex.':'.$this->columnName($endColumn).$rowIndex;
                $merges[] = $this->columnName($startColumn).($rowIndex + 1).':'.$this->columnName($endColumn).($rowIndex + 1);
            }

            $rows[] = $this->makeRow($rowIndex, $headerCells, 19);
            $rows[] = $this->makeRow($rowIndex + 1, $valueCells, 28);
            $rowIndex += 3;
        }

        $charts = (array) ($payload['charts'] ?? []);
        $funnel = (array) ($charts['funnel'] ?? []);
        $funnelLabels = array_values((array) ($funnel['labels'] ?? ['PAS', 'PAO', 'PTA', 'Actions']));
        $funnelValues = array_map('intval', array_values((array) ($funnel['values'] ?? [
            (int) ($payload['global']['pas_total'] ?? 0),
            (int) ($payload['global']['paos_total'] ?? 0),
            (int) ($payload['global']['ptas_total'] ?? 0),
            (int) ($payload['global']['actions_total'] ?? 0),
        ])));
        $funnelBase = max(1, (int) ($funnelValues[0] ?? max($funnelValues ?: [1])));
        $funnelMax = max(1, max($funnelValues ?: [1]));

        $rows[] = $this->makeMergedRow($rowIndex, 'Funnel de pilotage', $maxColumns, 3);
        $merges[] = 'A'.$rowIndex.':H'.$rowIndex;
        $rowIndex++;
        $rows[] = $this->makeStandardRow($rowIndex, ['Etape', 'Volume', 'Intensite', 'Couverture'], ['string', 'string', 'string', 'string'], 4);
        $rowIndex++;
        $funnelStartRow = $rowIndex;
        foreach ($funnelLabels as $index => $label) {
            $value = (int) ($funnelValues[$index] ?? 0);
            $coverage = $funnelBase > 0 ? round(($value / $funnelBase) * 100, 2) : 0.0;
            $rows[] = $this->makeDataRow(
                $rowIndex,
                [(string) $label, $value, $this->asciiBar($value, $funnelMax, 24), $coverage],
                ['string', 'integer', 'string', 'percent'],
                ($index % 2) === 0
            );
            $rowIndex++;
        }
        $funnelEndRow = max($funnelStartRow, $rowIndex - 1);
        $rowIndex++;

        $alertRows = collect($payload['alertes'] ?? [])->map(fn ($value, $key): array => [$this->humanize((string) $key), (int) $value])->values()->all();
        $alertMax = max(1, max(array_map(static fn (array $row): int => (int) $row[1], $alertRows ?: [[0, 1]])));

        $rows[] = $this->makeMergedRow($rowIndex, 'Alertes de synthese', $maxColumns, 3);
        $merges[] = 'A'.$rowIndex.':H'.$rowIndex;
        $rowIndex++;
        $rows[] = $this->makeStandardRow($rowIndex, ['Alerte', 'Total', 'Intensite'], ['string', 'string', 'string'], 4);
        $rowIndex++;
        $alertsStartRow = $rowIndex;
        foreach ($alertRows as $index => $alertRow) {
            $rows[] = $this->makeDataRow(
                $rowIndex,
                [$alertRow[0], $alertRow[1], $this->asciiBar((float) $alertRow[1], (float) $alertMax, 20)],
                ['string', 'integer', 'string'],
                ($index % 2) === 0
            );
            $rowIndex++;
        }
        $alertsEndRow = max($alertsStartRow, $rowIndex - 1);
        $rowIndex++;

        $performance = (array) ($charts['performance_gauge'] ?? []);
        $performanceLabels = array_values((array) ($performance['labels'] ?? []));
        $performanceValues = array_map('floatval', array_values((array) ($performance['values'] ?? [])));

        $rows[] = $this->makeMergedRow($rowIndex, 'Performance moyenne', $maxColumns, 3);
        $merges[] = 'A'.$rowIndex.':H'.$rowIndex;
        $rowIndex++;
        $rows[] = $this->makeStandardRow($rowIndex, ['Unite', 'Performance', 'Barre'], ['string', 'string', 'string'], 4);
        $rowIndex++;
        $performanceStartRow = $rowIndex;
        foreach ($performanceLabels as $index => $label) {
            $value = (float) ($performanceValues[$index] ?? 0.0);
            $rows[] = $this->makeDataRow(
                $rowIndex,
                [(string) $label, $value, $this->asciiBar($value, 100, 22)],
                ['string', 'percent', 'string'],
                ($index % 2) === 0
            );
            $rowIndex++;
        }
        if ($performanceLabels === []) {
            $rows[] = $this->makeDataRow($rowIndex, ['Aucune donnee', 0, ''], ['string', 'percent', 'string'], true);
            $rowIndex++;
        }
        $performanceEndRow = max($performanceStartRow, $rowIndex - 1);
        $rowIndex++;
        $rows[] = $this->makeMergedRow($rowIndex, 'Vue interannuelle', $maxColumns, 3);
        $merges[] = 'A'.$rowIndex.':H'.$rowIndex;
        $rowIndex++;
        $rows[] = $this->makeStandardRow($rowIndex, ['Annee', 'Actions', 'Validees', 'Progression', 'Validation'], ['string', 'string', 'string', 'string', 'string'], 4);
        $rowIndex++;
        $interannualStartRow = $rowIndex;
        foreach (array_values((array) ($payload['interannualComparison'] ?? [])) as $index => $comparisonRow) {
            $rows[] = $this->makeDataRow(
                $rowIndex,
                [
                    (int) ($comparisonRow['annee'] ?? 0),
                    (int) ($comparisonRow['actions_total'] ?? 0),
                    (int) ($comparisonRow['actions_validees'] ?? 0),
                    (float) ($comparisonRow['progression_moyenne'] ?? 0),
                    (float) ($comparisonRow['taux_validation'] ?? 0),
                ],
                ['integer', 'integer', 'integer', 'percent', 'percent'],
                ($index % 2) === 0
            );
            $rowIndex++;
        }
        if (($payload['interannualComparison'] ?? []) === []) {
            $rows[] = $this->makeDataRow($rowIndex, [0, 0, 0, 0, 0], ['integer', 'integer', 'integer', 'percent', 'percent'], true);
            $rowIndex++;
        }
        $interannualEndRow = max($interannualStartRow, $rowIndex - 1);
        $rowIndex++;

        $topRiskRows = array_slice((array) (($charts['top_risks']['rows'] ?? [])), 0, 6);
        $riskMax = max(1.0, max(array_map(static fn (array $row): float => (float) ($row['score'] ?? 0), $topRiskRows ?: [['score' => 1]])));

        $rows[] = $this->makeMergedRow($rowIndex, 'Top risques', $maxColumns, 3);
        $merges[] = 'A'.$rowIndex.':H'.$rowIndex;
        $rowIndex++;
        $rows[] = $this->makeStandardRow($rowIndex, ['Action', 'Score', 'Statut', 'Echeance', 'Intensite'], ['string', 'string', 'string', 'string', 'string'], 4);
        $rowIndex++;
        foreach ($topRiskRows as $index => $riskRow) {
            $score = (float) ($riskRow['score'] ?? 0.0);
            $rows[] = $this->makeDataRow(
                $rowIndex,
                [(string) ($riskRow['action'] ?? ''), $score, (string) ($riskRow['statut'] ?? ''), (string) ($riskRow['echeance'] ?? ''), $this->asciiBar($score, $riskMax, 18)],
                ['string', 'decimal', 'string', 'string', 'string'],
                ($index % 2) === 0
            );
            $rowIndex++;
        }
        if ($topRiskRows === []) {
            $rows[] = $this->makeDataRow($rowIndex, ['Aucun risque', 0, '', '', ''], ['string', 'decimal', 'string', 'string', 'string'], true);
        }

        $sheetName = 'Synthese graphique';
        $chartsMeta[] = [
            'title' => 'Funnel de pilotage',
            'type' => 'column',
            'color' => '3996D3',
            'anchor' => ['from_col' => 9, 'from_row' => 1, 'to_col' => 16, 'to_row' => 16],
            'categories' => [
                'formula' => $this->rangeFormula($sheetName, 1, $funnelStartRow, $funnelEndRow),
                'cache' => $funnelLabels,
            ],
            'values' => [
                'formula' => $this->rangeFormula($sheetName, 2, $funnelStartRow, $funnelEndRow),
                'cache' => $funnelValues,
            ],
        ];
        $chartsMeta[] = [
            'title' => 'Alertes de synthese',
            'type' => 'bar',
            'color' => 'F9B13C',
            'anchor' => ['from_col' => 9, 'from_row' => 18, 'to_col' => 16, 'to_row' => 33],
            'categories' => [
                'formula' => $this->rangeFormula($sheetName, 1, $alertsStartRow, $alertsEndRow),
                'cache' => array_map(static fn (array $row): string => (string) $row[0], $alertRows),
            ],
            'values' => [
                'formula' => $this->rangeFormula($sheetName, 2, $alertsStartRow, $alertsEndRow),
                'cache' => array_map(static fn (array $row): int => (int) $row[1], $alertRows),
            ],
        ];
        $chartsMeta[] = [
            'title' => 'Performance moyenne',
            'type' => 'bar',
            'color' => '8FC043',
            'anchor' => ['from_col' => 17, 'from_row' => 1, 'to_col' => 24, 'to_row' => 16],
            'categories' => [
                'formula' => $this->rangeFormula($sheetName, 1, $performanceStartRow, $performanceEndRow),
                'cache' => $performanceLabels !== [] ? $performanceLabels : ['Aucune donnee'],
            ],
            'values' => [
                'formula' => $this->rangeFormula($sheetName, 2, $performanceStartRow, $performanceEndRow),
                'cache' => $performanceValues !== [] ? $performanceValues : [0],
            ],
        ];
        $chartsMeta[] = [
            'title' => 'Validation interannuelle',
            'type' => 'line',
            'color' => '1C203D',
            'anchor' => ['from_col' => 17, 'from_row' => 18, 'to_col' => 24, 'to_row' => 33],
            'categories' => [
                'formula' => $this->rangeFormula($sheetName, 1, $interannualStartRow, $interannualEndRow),
                'cache' => array_map(static fn (array $row): string => (string) ($row['annee'] ?? 0), (array) ($payload['interannualComparison'] ?? [])),
            ],
            'values' => [
                'formula' => $this->rangeFormula($sheetName, 5, $interannualStartRow, $interannualEndRow),
                'cache' => array_map(static fn (array $row): float => (float) ($row['taux_validation'] ?? 0), (array) ($payload['interannualComparison'] ?? [])),
            ],
        ];

        return [
            'name' => 'Synthese graphique',
            'rows' => $rows,
            'merges' => $merges,
            'maxColumns' => $maxColumns,
            'widths' => [1 => 24, 2 => 14, 3 => 30, 4 => 16, 5 => 18, 6 => 14, 7 => 14, 8 => 18, 9 => 4, 10 => 11, 11 => 11, 12 => 11, 13 => 11, 14 => 11, 15 => 11, 16 => 11, 17 => 4, 18 => 11, 19 => 11, 20 => 11, 21 => 11, 22 => 11, 23 => 11, 24 => 11, 25 => 11],
            'charts' => $chartsMeta,
        ];
    }

    private function buildSections(array $payload): array
    {
        $sections = [];
        $sections[] = [
            'title' => 'Indicateurs globaux',
            'headers' => ['Indicateur', 'Valeur'],
            'types' => ['string', 'string'],
            'rows' => collect($payload['global'] ?? [])->map(fn ($value, $key): array => [(string) $key, (string) $value])->values()->all(),
        ];

        $statusRows = [];
        foreach (($payload['statuts'] ?? []) as $module => $rows) {
            foreach ($rows as $status => $total) {
                $statusRows[] = [(string) $module, (string) $status, (int) $total];
            }
        }
        $sections[] = [
            'title' => 'Statuts',
            'headers' => ['Module', 'Statut', 'Total'],
            'types' => ['string', 'string', 'integer'],
            'rows' => $statusRows,
        ];

        $sections[] = [
            'title' => 'Alertes de synthese',
            'headers' => ['Alerte', 'Total'],
            'types' => ['string', 'integer'],
            'rows' => collect($payload['alertes'] ?? [])->map(fn ($count, $label): array => [(string) $label, (int) $count])->values()->all(),
        ];

        $sections[] = [
            'title' => 'Vue consolidee du PAS',
            'headers' => ['PAS', 'Periode', 'Axes', 'Objectifs', 'PAO', 'PTA', 'Actions', 'Validees', 'Progression moyenne', 'Taux realisation'],
            'types' => ['string', 'string', 'integer', 'integer', 'integer', 'integer', 'integer', 'integer', 'percent', 'percent'],
            'rows' => collect($payload['pasConsolidation'] ?? [])->map(fn (array $row): array => [(string) ($row['titre'] ?? ''), (string) ($row['periode'] ?? ''), (int) ($row['axes_total'] ?? 0), (int) ($row['objectifs_total'] ?? 0), (int) ($row['paos_total'] ?? 0), (int) ($row['ptas_total'] ?? 0), (int) ($row['actions_total'] ?? 0), (int) ($row['actions_validees'] ?? 0), (float) ($row['progression_moyenne'] ?? 0), (float) ($row['taux_realisation'] ?? 0)])->all(),
        ];

        $sections[] = [
            'title' => 'Comparaison interannuelle',
            'headers' => ['Annee', 'PAO', 'PTA', 'Actions', 'Actions validees', 'Actions en retard', 'Progression moyenne', 'Taux validation'],
            'types' => ['integer', 'integer', 'integer', 'integer', 'integer', 'integer', 'percent', 'percent'],
            'rows' => collect($payload['interannualComparison'] ?? [])->map(fn (array $row): array => [(int) ($row['annee'] ?? 0), (int) ($row['paos_total'] ?? 0), (int) ($row['ptas_total'] ?? 0), (int) ($row['actions_total'] ?? 0), (int) ($row['actions_validees'] ?? 0), (int) ($row['actions_retard'] ?? 0), (float) ($row['progression_moyenne'] ?? 0), (float) ($row['taux_validation'] ?? 0)])->all(),
        ];
        $sections[] = [
            'title' => 'Details - Actions en retard',
            'headers' => ['ID', 'Libelle', 'Echeance', 'Statut', 'PTA', 'Responsable'],
            'types' => ['integer', 'string', 'string', 'string', 'string', 'string'],
            'rows' => collect($payload['details']['actions_retard'] ?? [])->map(fn ($action): array => [(int) $action->id, (string) $action->libelle, optional($action->date_echeance)->format('Y-m-d') ?? '', (string) $action->statut_dynamique, (string) ($action->pta?->titre ?? ''), (string) ($action->responsable?->name ?? '')])->all(),
        ];

        $sections[] = [
            'title' => 'Details - KPI sous seuil',
            'headers' => ['Mesure ID', 'KPI', 'Periode', 'Valeur', 'Seuil', 'Action'],
            'types' => ['integer', 'string', 'string', 'decimal', 'decimal', 'string'],
            'rows' => collect($payload['details']['kpi_sous_seuil'] ?? [])->map(fn ($mesure): array => [(int) $mesure->id, (string) ($mesure->kpi?->libelle ?? ''), (string) $mesure->periode, (float) ($mesure->valeur ?? 0), (float) ($mesure->kpi?->seuil_alerte ?? 0), (string) ($mesure->kpi?->action?->libelle ?? '')])->all(),
        ];

        $sections[] = [
            'title' => 'Structure des rapports - Tableau strategique',
            'headers' => ['Axe strategique', 'Objectif strategique', 'Objectif operationnel', 'Description actions detaillees', 'RMO', 'Cible', 'Debut', 'Fin', 'Etat de realisation', 'Progression', 'Ressources requises', 'Indicateurs de performance', 'Risques potentiels'],
            'types' => array_fill(0, 13, 'string'),
            'rows' => collect($payload['details']['structure_rapports'] ?? [])->map(fn (array $row): array => [(string) ($row['axe_strategique'] ?? ''), (string) ($row['objectif_strategique'] ?? ''), (string) ($row['objectif_operationnel'] ?? ''), (string) ($row['description_actions_detaillees'] ?? ''), (string) ($row['rmo'] ?? ''), (string) ($row['cible'] ?? ''), (string) ($row['debut'] ?? ''), (string) ($row['fin'] ?? ''), (string) ($row['etat_realisation'] ?? ''), (string) ($row['progression'] ?? ''), (string) ($row['ressources_requises'] ?? ''), (string) ($row['indicateurs_performance'] ?? ''), (string) ($row['risques_potentiels'] ?? '')])->all(),
        ];

        return $sections;
    }

    private function maxColumns(array $sections): int
    {
        $max = 1;
        foreach ($sections as $section) {
            $max = max($max, count((array) ($section['headers'] ?? [])));
            foreach ((array) ($section['rows'] ?? []) as $row) {
                $max = max($max, count((array) $row));
            }
        }

        return $max;
    }

    private function makeMergedRow(int $rowIndex, string $value, int $maxColumns, int $style): array
    {
        return [
            'index' => $rowIndex,
            'height' => $style === 1 ? 28 : 22,
            'cells' => [[
                'ref' => 'A'.$rowIndex,
                'type' => 'string',
                'value' => $value,
                'style' => $style,
            ]],
        ];
    }

    private function makeStandardRow(int $rowIndex, array $values, array $types, int $style): array
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $cells[] = [
                'ref' => $this->columnName($index + 1).$rowIndex,
                'type' => $types[$index] ?? 'string',
                'value' => $value,
                'style' => $style,
            ];
        }

        return $this->makeRow($rowIndex, $cells, 20);
    }

    private function makeDataRow(int $rowIndex, array $values, array $types, bool $isOdd): array
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $type = $types[$index] ?? 'string';
            $style = match ($type) {
                'integer' => $isOdd ? 7 : 8,
                'decimal' => $isOdd ? 9 : 10,
                'percent' => $isOdd ? 11 : 12,
                default => $isOdd ? 5 : 6,
            };
            $cells[] = [
                'ref' => $this->columnName($index + 1).$rowIndex,
                'type' => $type,
                'value' => $value,
                'style' => $style,
            ];
        }

        return $this->makeRow($rowIndex, $cells, 18);
    }

    private function makeRow(int $rowIndex, array $cells, int $height = 18): array
    {
        return ['index' => $rowIndex, 'height' => $height, 'cells' => $cells];
    }
    private function sheetXml(array $rows, array $merges, int $maxColumns, array $widths = [], ?string $drawingRelationshipId = null): string
    {
        $sheetRows = '';
        $lastRow = 1;
        foreach ($rows as $row) {
            $lastRow = max($lastRow, (int) ($row['index'] ?? 1));
            $cells = '';
            foreach ($row['cells'] as $cell) {
                $cells .= $this->cellXml($cell);
            }
            $height = isset($row['height']) ? ' ht="'.$row['height'].'" customHeight="1"' : '';
            $sheetRows .= '<row r="'.$row['index'].'"'.$height.'>'.$cells.'</row>';
        }

        $columns = '';
        for ($index = 1; $index <= $maxColumns; $index++) {
            $width = $widths[$index] ?? match (true) {
                $index === 1 => 26,
                $index <= 3 => 20,
                $index >= 10 => 24,
                default => 16,
            };
            $columns .= '<col min="'.$index.'" max="'.$index.'" width="'.$width.'" customWidth="1"/>';
        }

        $mergeXml = '';
        if ($merges !== []) {
            $mergeXml = '<mergeCells count="'.count($merges).'">';
            foreach ($merges as $merge) {
                $mergeXml .= '<mergeCell ref="'.$merge.'"/>';
            }
            $mergeXml .= '</mergeCells>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<dimension ref="A1:'.$this->columnName($maxColumns).$lastRow.'"/>'
            .'<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="18"/>'
            .'<cols>'.$columns.'</cols>'
            .'<sheetData>'.$sheetRows.'</sheetData>'
            .$mergeXml
            .($drawingRelationshipId !== null ? '<drawing r:id="'.$drawingRelationshipId.'"/>' : '')
            .'</worksheet>';
    }

    private function cellXml(array $cell): string
    {
        $ref = (string) $cell['ref'];
        $style = (int) ($cell['style'] ?? 0);
        $type = (string) ($cell['type'] ?? 'string');
        $value = $cell['value'] ?? '';

        if (($type === 'integer' || $type === 'decimal' || $type === 'percent') && is_numeric($value)) {
            $number = (float) $value;
            if ($type === 'percent') {
                $number /= 100;
            }
            return '<c r="'.$ref.'" s="'.$style.'"><v>'.$this->normalizeNumber($number).'</v></c>';
        }

        $escaped = htmlspecialchars((string) $value, ENT_XML1);
        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
    }

    private function normalizeNumber(float $value): string
    {
        $normalized = number_format($value, 6, '.', '');
        return rtrim(rtrim($normalized, '0'), '.');
    }

    private function defaultWidths(int $maxColumns): array
    {
        $widths = [];
        for ($index = 1; $index <= $maxColumns; $index++) {
            $widths[$index] = match (true) {
                $index === 1 => 26,
                $index <= 3 => 20,
                $index >= 10 => 24,
                default => 16,
            };
        }
        return $widths;
    }

    private function contentTypesXml(int $sheetCount, int $drawingCount = 0, int $chartCount = 0): string
    {
        $overrides = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        for ($index = 1; $index <= $drawingCount; $index++) {
            $overrides .= '<Override PartName="/xl/drawings/drawing'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }
        for ($index = 1; $index <= $chartCount; $index++) {
            $overrides .= '<Override PartName="/xl/charts/chart'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
        }
        $overrides .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .$overrides
            .'</Types>';
    }

    private function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function sheetRelationshipsXml(int $drawingIndex): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing'.$drawingIndex.'.xml"/>'
            .'</Relationships>';
    }

    private function drawingXml(array $charts): string
    {
        $anchors = '';
        foreach (array_values($charts) as $index => $chart) {
            $anchor = $chart['anchor'] ?? [];
            $fromCol = (int) ($anchor['from_col'] ?? 0);
            $fromRow = (int) ($anchor['from_row'] ?? 0);
            $toCol = (int) ($anchor['to_col'] ?? ($fromCol + 7));
            $toRow = (int) ($anchor['to_row'] ?? ($fromRow + 14));
            $frameId = $index + 2;

            $anchors .= '<xdr:twoCellAnchor>'
                .'<xdr:from><xdr:col>'.$fromCol.'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$fromRow.'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
                .'<xdr:to><xdr:col>'.$toCol.'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$toRow.'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
                .'<xdr:graphicFrame macro="">'
                .'<xdr:nvGraphicFramePr><xdr:cNvPr id="'.$frameId.'" name="Graphique '.($index + 1).'"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
                .'<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
                .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
                .'<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId'.($index + 1).'"/>'
                .'</a:graphicData></a:graphic>'
                .'</xdr:graphicFrame>'
                .'<xdr:clientData/>'
                .'</xdr:twoCellAnchor>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .$anchors
            .'</xdr:wsDr>';
    }

    private function drawingRelationshipsXml(int $startChartIndex, int $count): string
    {
        $relationships = '';
        for ($offset = 0; $offset < $count; $offset++) {
            $chartIndex = $startChartIndex + $offset;
            $relationships .= '<Relationship Id="rId'.($offset + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart'.$chartIndex.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships
            .'</Relationships>';
    }

    private function chartXml(array $chart, int $chartIndex): string
    {
        $type = (string) ($chart['type'] ?? 'column');
        $title = (string) ($chart['title'] ?? 'Graphique');
        $color = strtoupper((string) ($chart['color'] ?? '3996D3'));
        $categoryFormula = (string) ($chart['categories']['formula'] ?? '');
        $valueFormula = (string) ($chart['values']['formula'] ?? '');
        $categoryCache = array_values((array) ($chart['categories']['cache'] ?? []));
        $valueCache = array_values((array) ($chart['values']['cache'] ?? []));
        $catAxisId = 510000 + ($chartIndex * 10);
        $valAxisId = $catAxisId + 1;

        $series = '<c:ser>'
            .'<c:idx val="0"/><c:order val="0"/>'
            .'<c:tx><c:v>'.htmlspecialchars($title, ENT_XML1).'</c:v></c:tx>'
            .'<c:cat><c:strRef><c:f>'.$categoryFormula.'</c:f>'.$this->stringCacheXml($categoryCache).'</c:strRef></c:cat>'
            .'<c:val><c:numRef><c:f>'.$valueFormula.'</c:f>'.$this->numberCacheXml($valueCache).'</c:numRef></c:val>'
            .'<c:spPr>'.$this->solidFillXml($color).'<a:ln w="19050">'.$this->solidFillXml($color).'</a:ln></c:spPr>'
            .'</c:ser>';

        if ($type === 'line') {
            $plot = '<c:lineChart><c:grouping val="standard"/>'.$series.'<c:marker val="1"/><c:smooth val="0"/><c:axId val="'.$catAxisId.'"/><c:axId val="'.$valAxisId.'"/></c:lineChart>';
        } else {
            $plot = '<c:barChart><c:barDir val="'.($type === 'bar' ? 'bar' : 'col').'"/><c:grouping val="clustered"/><c:varyColors val="0"/>'.$series.'<c:gapWidth val="55"/><c:axId val="'.$catAxisId.'"/><c:axId val="'.$valAxisId.'"/></c:barChart>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<c:lang val="fr-FR"/>'
            .'<c:chart>'
            .$this->chartTitleXml($title)
            .'<c:plotArea><c:layout/>'
            .$plot
            .$this->categoryAxisXml($catAxisId, $valAxisId, $type === 'bar')
            .$this->valueAxisXml($valAxisId, $catAxisId, $type === 'bar')
            .'</c:plotArea>'
            .'<c:legend><c:legendPos val="r"/><c:layout/></c:legend>'
            .'<c:plotVisOnly val="1"/>'
            .'</c:chart>'
            .'</c:chartSpace>';
    }
    private function appPropertiesXml(array $sheetNames): string
    {
        $parts = '';
        foreach ($sheetNames as $sheetName) {
            $parts .= '<vt:lpstr>'.htmlspecialchars($sheetName, ENT_XML1).'</vt:lpstr>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>ANBG Reporting Export</Application>'
            .'<DocSecurity>0</DocSecurity>'
            .'<ScaleCrop>false</ScaleCrop>'
            .'<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>'.count($sheetNames).'</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            .'<TitlesOfParts><vt:vector size="'.count($sheetNames).'" baseType="lpstr">'.$parts.'</vt:vector></TitlesOfParts>'
            .'<Company>ANBG</Company>'
            .'</Properties>';
    }

    private function corePropertiesXml(CarbonInterface|string|null $generatedAt): string
    {
        $timestamp = $generatedAt instanceof CarbonInterface
            ? $generatedAt->copy()->utc()->format('Y-m-d\TH:i:s\Z')
            : now()->utc()->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>Reporting consolide ANBG</dc:title>'
            .'<dc:creator>ANBG</dc:creator>'
            .'<cp:lastModifiedBy>ANBG</cp:lastModifiedBy>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function workbookXml(array $sheetNames): string
    {
        $sheetsXml = '';
        foreach ($sheetNames as $index => $sheetName) {
            $sheetsXml .= '<sheet name="'.htmlspecialchars($sheetName, ENT_XML1).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetsXml.'</sheets>'
            .'</workbook>';
    }

    private function workbookRelationshipsXml(int $sheetCount): string
    {
        $relationships = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $relationships .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
        }
        $relationships .= '<Relationship Id="rId'.($sheetCount + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships
            .'</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="0.00"/><numFmt numFmtId="165" formatCode="0.00%"/></numFmts>'
            .'<fonts count="6">'
            .'<font><sz val="11"/><color rgb="FF1C203D"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF1C203D"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="18"/><color rgb="FF1C203D"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            .'</fonts>'
            .'<fills count="11">'
            .'<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1C203D"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8F3FB"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF3996D3"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF8FC043"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEEF6E1"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF9B13C"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF0DF"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD9E4EE"/></left><right style="thin"><color rgb="FFD9E4EE"/></right><top style="thin"><color rgb="FFD9E4EE"/></top><bottom style="thin"><color rgb="FFD9E4EE"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="21">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="1" fontId="0" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            .'<xf numFmtId="1" fontId="0" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            .'<xf numFmtId="164" fontId="0" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            .'<xf numFmtId="164" fontId="0" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            .'<xf numFmtId="165" fontId="0" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            .'<xf numFmtId="165" fontId="0" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="1" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="1" fontId="4" fillId="8" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="1" fontId="4" fillId="10" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="1" fontId="5" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function chartTitleXml(string $title): string
    {
        $escaped = htmlspecialchars($title, ENT_XML1);

        return '<c:title><c:tx><c:rich>'
            .'<a:bodyPr/><a:lstStyle/>'
            .'<a:p><a:pPr><a:defRPr/></a:pPr><a:r><a:rPr lang="fr-FR" sz="1200" b="1"/><a:t>'.$escaped.'</a:t></a:r><a:endParaRPr lang="fr-FR"/></a:p>'
            .'</c:rich></c:tx><c:layout/></c:title>';
    }

    private function categoryAxisXml(int $axisId, int $crossAxisId, bool $horizontal = false): string
    {
        return '<c:catAx>'
            .'<c:axId val="'.$axisId.'"/>'
            .'<c:scaling><c:orientation val="minMax"/></c:scaling>'
            .'<c:delete val="0"/>'
            .'<c:axPos val="'.($horizontal ? 'l' : 'b').'"/>'
            .'<c:numFmt formatCode="General" sourceLinked="1"/>'
            .'<c:majorTickMark val="out"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
            .'<c:crossAx val="'.$crossAxisId.'"/><c:crosses val="autoZero"/>'
            .'<c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/>'
            .'</c:catAx>';
    }

    private function valueAxisXml(int $axisId, int $crossAxisId, bool $horizontal = false): string
    {
        return '<c:valAx>'
            .'<c:axId val="'.$axisId.'"/>'
            .'<c:scaling><c:orientation val="minMax"/></c:scaling>'
            .'<c:delete val="0"/>'
            .'<c:axPos val="'.($horizontal ? 'b' : 'l').'"/>'
            .'<c:majorGridlines/><c:numFmt formatCode="General" sourceLinked="1"/>'
            .'<c:majorTickMark val="out"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
            .'<c:crossAx val="'.$crossAxisId.'"/><c:crosses val="autoZero"/><c:crossBetween val="between"/>'
            .'</c:valAx>';
    }

    private function stringCacheXml(array $values): string
    {
        $points = '';
        foreach (array_values($values) as $index => $value) {
            $points .= '<c:pt idx="'.$index.'"><c:v>'.htmlspecialchars((string) $value, ENT_XML1).'</c:v></c:pt>';
        }

        return '<c:strCache><c:ptCount val="'.count($values).'"/>'.$points.'</c:strCache>';
    }

    private function numberCacheXml(array $values): string
    {
        $points = '';
        foreach (array_values($values) as $index => $value) {
            $points .= '<c:pt idx="'.$index.'"><c:v>'.$this->normalizeNumber((float) $value).'</c:v></c:pt>';
        }

        return '<c:numCache><c:formatCode>General</c:formatCode><c:ptCount val="'.count($values).'"/>'.$points.'</c:numCache>';
    }

    private function solidFillXml(string $rgb): string
    {
        return '<a:solidFill><a:srgbClr val="'.htmlspecialchars($rgb, ENT_XML1).'"/></a:solidFill>';
    }

    private function rangeFormula(string $sheetName, int $columnIndex, int $startRow, int $endRow): string
    {
        return '\''.str_replace('\'', '\'\'', $sheetName).'\'!$'.$this->columnName($columnIndex).'$'.$startRow.':$'.$this->columnName($columnIndex).'$'.$endRow;
    }

    private function humanize(string $value): string
    {
        return Str::headline(str_replace(['_', '-'], ' ', $value));
    }

    private function asciiBar(float $value, float $max, int $segments = 20): string
    {
        if ($segments <= 0) {
            return '';
        }
        if ($max <= 0) {
            return str_repeat('-', $segments);
        }
        $filled = (int) round(max(0, min(1, $value / $max)) * $segments);
        return str_repeat('#', $filled).str_repeat('-', max(0, $segments - $filled));
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }
        return $name;
    }
}
