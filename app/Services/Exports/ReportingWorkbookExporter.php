<?php

namespace App\Services\Exports;

use Carbon\CarbonInterface;
use App\Support\Zip\SimpleZipWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class ReportingWorkbookExporter
{
    public function __construct(
        private readonly SimpleZipWriter $zipWriter = new SimpleZipWriter()
    ) {
    }

    public function create(array $payload): string
    {
        $sheets = $this->buildSheets($payload);
        $logoContents = $this->logoContents();
        $includeLogo = $logoContents !== null;
        $tempPath = tempnam(sys_get_temp_dir(), 'anbg_xlsx_');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to allocate temporary file for XLSX export.');
        }

        $sheetNames = array_map(static fn (array $sheet): string => (string) $sheet['name'], $sheets);
        $drawingCount = count(array_filter($sheets, static fn (array $sheet): bool => $includeLogo || ! empty($sheet['charts'] ?? [])));
        $chartCount = array_sum(array_map(static fn (array $sheet): int => count($sheet['charts'] ?? []), $sheets));

        $entries = [
            '[Content_Types].xml' => $this->contentTypesXml(count($sheets), $drawingCount, $chartCount, $includeLogo),
            '_rels/.rels' => $this->rootRelationshipsXml(),
            'docProps/app.xml' => $this->appPropertiesXml($sheetNames),
            'docProps/core.xml' => $this->corePropertiesXml(
                $payload['generatedAt'] ?? null,
                (string) (($payload['export_template']['title'] ?? 'Reporting consolide ANBG'))
            ),
            'xl/workbook.xml' => $this->workbookXml($sheetNames),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationshipsXml(count($sheets)),
            'xl/styles.xml' => $this->stylesXml(),
        ];
        if ($includeLogo) {
            $entries['xl/media/logo.png'] = $logoContents;
        }

        $drawingIndex = 1;
        $chartIndex = 1;
        foreach ($sheets as $index => $sheet) {
            $hasCharts = ! empty($sheet['charts'] ?? []);
            $hasDrawing = $includeLogo || $hasCharts;
            $entries['xl/worksheets/sheet'.($index + 1).'.xml'] = $this->sheetXml(
                $sheet['rows'],
                $sheet['merges'],
                (int) $sheet['maxColumns'],
                $sheet['widths'] ?? [],
                $hasDrawing ? 'rId1' : null,
                (bool) ($sheet['freeze_header'] ?? false),
                $sheet['auto_filter_ref'] ?? null
            );

            if (! $hasDrawing) {
                continue;
            }

            $charts = (array) ($sheet['charts'] ?? []);
            $entries['xl/worksheets/_rels/sheet'.($index + 1).'.xml.rels'] = $this->sheetRelationshipsXml($drawingIndex);
            $entries['xl/drawings/drawing'.$drawingIndex.'.xml'] = $this->drawingXml($charts, $includeLogo);
            $entries['xl/drawings/_rels/drawing'.$drawingIndex.'.xml.rels'] = $this->drawingRelationshipsXml($chartIndex, count($charts), $includeLogo);

            foreach ($charts as $chart) {
                $entries['xl/charts/chart'.$chartIndex.'.xml'] = $this->chartXml($chart, $chartIndex);
                $chartIndex++;
            }

            $drawingIndex++;
        }

        try {
            $this->zipWriter->write($tempPath, $entries);
        } catch (\Throwable $exception) {
            @unlink($tempPath);

            throw $exception;
        }

        return $tempPath;
    }

    private function buildSheets(array $payload): array
    {
        $sheets = [
            $this->buildStrategySheet($payload),
            $this->buildPaoSheet($payload),
            $this->buildActionDetailsFinalSheet($payload),
            $this->buildKpiFinalSheet($payload),
            $this->buildSyntheticReportingSheet($payload),
            $this->buildAlertsFinalSheet($payload),
            $this->buildRisksFinalSheet($payload),
            $this->buildRmoPerformanceSheet($payload),
            $this->buildJustificatifsSheet($payload),
        ];

        return array_merge($sheets, $this->buildInstitutionalServiceSheets($payload));
    }

    private function buildDetailSheet(array $payload): array
    {
        $sections = $this->buildSections($payload);
        $maxColumns = max(13, $this->maxColumns($sections));
        $rows = [];
        $merges = [];
        $rowIndex = 1;
        $exportTemplate = (array) ($payload['export_template'] ?? []);
        $title = (string) ($exportTemplate['title'] ?? 'Reporting consolide ANBG');
        $subtitle = trim((string) ($exportTemplate['subtitle'] ?? ''));
        $officialPolicy = (array) ($payload['officialPolicy'] ?? []);
        $officialBaseLabel = (string) ($officialPolicy['threshold_label'] ?? 'Toutes les actions visibles');
        $officialBaseText = 'Base statistique : '.$officialBaseLabel;
        $layout = (array) (($payload['export_template']['layout'] ?? []));
        $firstHeaderRow = null;

        $rows[] = $this->makeMergedRow($rowIndex, $title, $maxColumns, 1);
        $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
        $rowIndex++;

        if ($subtitle !== '') {
            $rows[] = $this->makeMergedRow($rowIndex, $subtitle, $maxColumns, 2);
            $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
            $rowIndex++;
        }

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
        $rowIndex++;

        $rows[] = $this->makeMergedRow($rowIndex, $officialBaseText, $maxColumns, 2);
        $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
        $rowIndex += 2;

        foreach ($sections as $section) {
            $sectionTitle = (string) $section['title'];
            $rows[] = $this->makeMergedRow($rowIndex, $sectionTitle, $maxColumns, 3);
            $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
            $rowIndex++;

            $headers = (array) $section['headers'];
            $firstHeaderRow ??= $rowIndex;
            $rows[] = $this->makeStandardRow($rowIndex, $headers, array_fill(0, count($headers), 'string'), 4);
            $rowIndex++;

            $types = (array) ($section['types'] ?? []);
            foreach ((array) $section['rows'] as $dataRowIndex => $dataRow) {
                $rows[] = $this->makeDataRow($rowIndex, is_array($dataRow) ? $dataRow : [], $types, ((int) $dataRowIndex % 2) === 0);
                $rowIndex++;
            }

            $rowIndex++;
        }

        $lastDataRow = max(1, $rowIndex - 2);

        return [
            'name' => (string) ($layout['excel_detail_sheet_name'] ?? 'Reporting'),
            'rows' => $rows,
            'merges' => $merges,
            'maxColumns' => $maxColumns,
            'widths' => $this->defaultWidths($maxColumns),
            'freeze_header' => (bool) ($layout['excel_freeze_header'] ?? true),
            'auto_filter_ref' => (($layout['excel_auto_filter'] ?? true) && $firstHeaderRow !== null)
                ? 'A'.$firstHeaderRow.':'.$this->columnName($maxColumns).$lastDataRow
                : null,
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
        $officialPolicy = (array) ($payload['officialPolicy'] ?? []);
        $officialBaseLabel = (string) ($officialPolicy['threshold_label'] ?? 'Toutes les actions visibles');
        $officialBaseText = 'Base statistique : '.$officialBaseLabel;
        $layout = (array) (($payload['export_template']['layout'] ?? []));

        $rows[] = $this->makeMergedRow($rowIndex, (string) ($layout['excel_graph_sheet_name'] ?? 'Synthese graphique'), $maxColumns, 1);
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
        $rowIndex++;

        $rows[] = $this->makeMergedRow($rowIndex, 'Lecture statistique unifiee', $maxColumns, 2);
        $merges[] = 'A'.$rowIndex.':H'.$rowIndex;
        $rowIndex++;

        $rows[] = $this->makeMergedRow($rowIndex, $officialBaseText, $maxColumns, 2);
        $merges[] = 'A'.$rowIndex.':H'.$rowIndex;
        $rowIndex += 2;

        $cardRanges = [[1, 2], [3, 4], [5, 6], [7, 8]];
        $kpiSummary = (array) ($payload['kpiSummary'] ?? []);
        $cardsGroups = [
            [
                ['Actions', (int) ($payload['global']['actions_total'] ?? 0), 13, 14],
                ['Validees', (int) ($payload['global']['actions_validees'] ?? 0), 15, 16],
                ['Mesures d indicateur', (int) ($payload['global']['kpi_mesures_total'] ?? 0), 17, 18],
                ['Obj. op.', (int) ($payload['global']['objectifs_operationnels_total'] ?? 0), 19, 20],
            ],
            [
                ['PAS', (int) ($payload['global']['pas_total'] ?? 0), 19, 20],
                ['PAO', (int) ($payload['global']['paos_total'] ?? 0), 13, 14],
                ['PTA', (int) ($payload['global']['ptas_total'] ?? 0), 15, 16],
                ['Retards', (int) ($payload['alertes']['actions_en_retard'] ?? 0), 17, 18],
            ],
            [
                ['Indicateur global', (int) round((float) ($kpiSummary['global'] ?? 0)), 13, 14],
                ['Qualite', (int) round((float) ($kpiSummary['qualite'] ?? 0)), 15, 16],
                ['Risque', (int) round((float) ($kpiSummary['risque'] ?? 0)), 17, 18],
                ['Progression', (int) round((float) ($kpiSummary['progression'] ?? 0)), 19, 20],
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

        $sheetName = (string) ($layout['excel_graph_sheet_name'] ?? 'Synthese graphique');
        $chartsMeta[] = [
            'title' => 'Funnel de pilotage',
            'type' => 'column',
            'color' => '1E3A8A',
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
            'color' => 'EF4444',
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
            'color' => '10B981',
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
            'color' => '3B82F6',
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
            'name' => $sheetName,
            'rows' => $rows,
            'merges' => $merges,
            'maxColumns' => $maxColumns,
            'widths' => [1 => 24, 2 => 14, 3 => 30, 4 => 16, 5 => 18, 6 => 14, 7 => 14, 8 => 18, 9 => 4, 10 => 11, 11 => 11, 12 => 11, 13 => 11, 14 => 11, 15 => 11, 16 => 11, 17 => 4, 18 => 11, 19 => 11, 20 => 11, 21 => 11, 22 => 11, 23 => 11, 24 => 11, 25 => 11],
            'charts' => $chartsMeta,
            'freeze_header' => false,
            'auto_filter_ref' => null,
        ];
    }

    private function buildStrategySheet(array $payload): array
    {
        return $this->buildTableSheet(
            'STRATEGIE',
            'STRATEGIE',
            $this->standardReportMetaRows($payload, 'Tableau 1 : Axes & Objectifs strategiques'),
            ['N° Axe', 'Axe strategique', 'N° Objectif', 'Objectif strategique', 'Echeance'],
            ['string', 'string', 'string', 'string', 'string'],
            $this->strategySheetRows($payload),
            [1 => 16, 2 => 42, 3 => 22, 4 => 48, 5 => 18]
        );
    }

    private function buildPaoSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'PAO',
            'PAO',
            $this->standardReportMetaRows($payload, 'Tableau 2 : Objectifs operationnels & Actions'),
            ['Direction', 'Service', 'Axe strategique', 'Objectif strategique', 'Objectif operationnel', 'Action', 'Responsable', 'Echeance'],
            ['string', 'string', 'string', 'string', 'string', 'string', 'string', 'string'],
            $this->paoSheetRows($payload),
            [1 => 36, 2 => 36, 3 => 34, 4 => 38, 5 => 42, 6 => 42, 7 => 28, 8 => 18]
        );
    }

    private function buildActionDetailsFinalSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'ACTIONS',
            'ACTIONS',
            $this->standardReportMetaRows($payload, 'Tableau 3 : Actions detaillees'),
            ['Direction', 'Service', 'Description action', 'RMO', 'Cible', 'Debut', 'Fin', 'Statut', 'Ressources', 'Taux (%)', 'Justificatif', 'Risque'],
            ['string', 'string', 'string', 'string', 'string', 'string', 'string', 'string', 'string', 'percent', 'string', 'string'],
            $this->actionFinalSheetRows($payload),
            [1 => 36, 2 => 36, 3 => 48, 4 => 26, 5 => 36, 6 => 16, 7 => 16, 8 => 18, 9 => 34, 10 => 18, 11 => 42, 12 => 42]
        );
    }

    private function buildKpiFinalSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'KPI',
            'KPI',
            $this->standardReportMetaRows($payload, 'Tableau 4 : KPI par action'),
            ['Direction', 'Service', 'Action', 'RMO', 'KPI Performance (%)', 'KPI Qualite (%)', 'KPI Delai (%)', 'KPI Risque (%)', 'KPI Conformite (%)', 'KPI Global (%)'],
            ['string', 'string', 'string', 'string', 'percent', 'percent', 'percent', 'percent', 'percent', 'percent'],
            $this->kpiFinalSheetRows($payload),
            [1 => 36, 2 => 36, 3 => 42, 4 => 26, 5 => 20, 6 => 18, 7 => 18, 8 => 18, 9 => 20, 10 => 18]
        );
    }

    private function buildSyntheticReportingSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'SYNTHÈSE',
            'SYNTHÈSE',
            $this->standardReportMetaRows($payload, 'Tableau 5 : Reporting synthetique'),
            ['Direction', 'Service', 'Total actions', 'Terminees', 'En cours', 'En retard', 'Performance (%)'],
            ['string', 'string', 'integer', 'integer', 'integer', 'integer', 'percent'],
            $this->syntheticReportingRows($payload),
            [1 => 36, 2 => 36, 3 => 18, 4 => 18, 5 => 18, 6 => 18, 7 => 20]
        );
    }

    private function buildAlertsFinalSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'ALERTES',
            'ALERTES',
            $this->standardReportMetaRows($payload, 'Tableau 6 : Alertes KPI sous seuil'),
            ['Action', 'Indicateur', 'Valeur', 'Seuil', 'Statut', 'Action corrective'],
            ['string', 'string', 'decimal', 'decimal', 'string', 'string'],
            $this->alertFinalSheetRows($payload),
            [1 => 42, 2 => 36, 3 => 14, 4 => 14, 5 => 18, 6 => 48]
        );
    }

    private function buildRisksFinalSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'RISQUES',
            'RISQUES',
            $this->standardReportMetaRows($payload, 'Tableau 7 : Risques'),
            ['Direction', 'Service', 'Action', 'Risque', 'Niveau', 'Impact', 'Solution', 'Responsable'],
            ['string', 'string', 'string', 'string', 'string', 'decimal', 'string', 'string'],
            $this->riskFinalSheetRows($payload),
            [1 => 36, 2 => 36, 3 => 42, 4 => 44, 5 => 18, 6 => 14, 7 => 44, 8 => 28]
        );
    }

    private function buildRmoPerformanceSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'RMO_PERFORMANCE',
            'RMO_PERFORMANCE',
            $this->standardReportMetaRows($payload, 'Tableau 8 : Performance par RMO'),
            ['Direction', 'Service', 'RMO', 'Nombre d actions', 'Performance moyenne (%)'],
            ['string', 'string', 'string', 'integer', 'percent'],
            $this->rmoPerformanceRows($payload),
            [1 => 36, 2 => 36, 3 => 34, 4 => 20, 5 => 24]
        );
    }

    private function buildJustificatifsSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'JUSTIFICATIFS',
            'JUSTIFICATIFS',
            $this->standardReportMetaRows($payload, 'Tableau 9 : Suivi des justificatifs'),
            ['Direction', 'Service', 'Action', 'RMO', 'Justificatif', 'Statut validation', 'Date'],
            ['string', 'string', 'string', 'string', 'string', 'string', 'string'],
            $this->justificatifSheetRows($payload),
            [1 => 36, 2 => 36, 3 => 42, 4 => 28, 5 => 42, 6 => 22, 7 => 18]
        );
    }

    private function buildInstitutionalSummarySheet(array $payload): array
    {
        $exportTemplate = (array) ($payload['export_template'] ?? []);
        $title = (string) ($exportTemplate['title'] ?? 'RAPPORT DE REPORTING');
        $subtitle = trim((string) ($exportTemplate['subtitle'] ?? ''));
        $officialPolicy = (array) ($payload['officialPolicy'] ?? []);
        $metaRows = array_filter([
            'RAPPORT DE REPORTING',
            $subtitle !== '' ? $subtitle : null,
            'Base statistique : '.(string) ($officialPolicy['threshold_label'] ?? 'Toutes les actions visibles'),
            'Synthese des indicateurs',
        ]);

        return $this->buildTableSheet(
            (string) (($payload['export_template']['layout']['excel_summary_sheet_name'] ?? null) ?: 'Synthese'),
            $title,
            $metaRows,
            ['Direction / Service', 'Nombre total d actions', 'Nombre d actions terminees', 'Nombre d actions en cours', 'Nombre d actions en retard', '% de realisation', '% de retard'],
            ['string', 'integer', 'integer', 'integer', 'integer', 'percent', 'percent'],
            $this->summarySheetRows($payload),
            [1 => 42, 2 => 18, 3 => 22, 4 => 22, 5 => 22, 6 => 18, 7 => 18]
        );
    }

    private function buildInstitutionalAlertsSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'Alertes',
            'Alertes',
            ['RAPPORT DE REPORTING', 'Alertes KPI et retards operationnels'],
            ['Action', 'Indicateur', 'Periode', 'Valeur', 'Seuil', 'Statut', 'Mesure corrective recommandee'],
            ['string', 'string', 'string', 'decimal', 'decimal', 'string', 'string'],
            $this->alertSheetRows($payload),
            [1 => 36, 2 => 32, 3 => 18, 4 => 14, 5 => 14, 6 => 18, 7 => 42]
        );
    }

    private function buildInstitutionalActionsSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'Actions detaillees',
            'Actions detaillees',
            ['RAPPORT DE REPORTING', 'Hierarchie PAS -> Objectif -> Action'],
            ['Axe strategique', 'Objectif strategique', 'Objectif operationnel', 'Action', 'Responsable', 'Debut', 'Fin', 'Statut', 'Progression'],
            ['string', 'string', 'string', 'string', 'string', 'string', 'string', 'string', 'percent'],
            $this->actionDetailSheetRows($payload),
            [1 => 34, 2 => 34, 3 => 34, 4 => 36, 5 => 24, 6 => 14, 7 => 14, 8 => 18, 9 => 14]
        );
    }

    private function buildInstitutionalKpiSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'KPI',
            'KPI',
            ['RAPPORT DE REPORTING', 'Indicateurs de performance'],
            ['Action', 'Indicateur', 'Type', 'Valeur', 'Seuil', 'Statut'],
            ['string', 'string', 'string', 'decimal', 'decimal', 'string'],
            $this->kpiSheetRows($payload),
            [1 => 38, 2 => 34, 3 => 18, 4 => 14, 5 => 14, 6 => 18]
        );
    }

    private function buildInstitutionalRisksSheet(array $payload): array
    {
        return $this->buildTableSheet(
            'Risques',
            'Risques',
            ['RAPPORT DE REPORTING', 'Risques potentiels et mitigation'],
            ['Action', 'Risque identifie', 'Niveau de risque', 'Mesure de mitigation', 'Responsable', 'Statut de suivi'],
            ['string', 'string', 'string', 'string', 'string', 'string'],
            $this->riskSheetRows($payload),
            [1 => 34, 2 => 42, 3 => 18, 4 => 42, 5 => 24, 6 => 20]
        );
    }

    /**
     * @return list<string>
     */
    private function standardReportMetaRows(array $payload, string $tableLabel): array
    {
        $exportTemplate = (array) ($payload['export_template'] ?? []);
        $officialPolicy = (array) ($payload['officialPolicy'] ?? []);
        $subtitle = trim((string) ($exportTemplate['subtitle'] ?? ''));

        return array_values(array_filter([
            (string) ($exportTemplate['title'] ?? 'RAPPORT DE REPORTING'),
            $subtitle !== '' ? $subtitle : null,
            'Base statistique : '.(string) ($officialPolicy['threshold_label'] ?? 'Toutes les actions visibles'),
            $tableLabel,
        ], static fn (?string $row): bool => $row !== null && trim($row) !== ''));
    }

    private function buildTableSheet(
        string $name,
        string $title,
        array $metaRows,
        array $headers,
        array $types,
        array $dataRows,
        array $widths
    ): array {
        $maxColumns = max(1, count($headers));
        $rows = [];
        $merges = [];
        $rowIndex = 1;

        $rows[] = $this->makeMergedRow($rowIndex, $title, $maxColumns, 1);
        $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
        $rowIndex++;

        foreach ($metaRows as $meta) {
            $rows[] = $this->makeMergedRow($rowIndex, (string) $meta, $maxColumns, 2);
            $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
            $rowIndex++;
        }

        $rowIndex++;
        $headerRow = $rowIndex;
        $rows[] = $this->makeStandardRow($rowIndex, $headers, array_fill(0, count($headers), 'string'), 4);
        $rowIndex++;

        foreach ($dataRows as $index => $dataRow) {
            $rows[] = $this->makeDataRow($rowIndex, is_array($dataRow) ? $dataRow : [], $types, ((int) $index % 2) === 0);
            $rowIndex++;
        }

        if ($dataRows === []) {
            $rows[] = $this->makeDataRow($rowIndex, array_pad(['Aucune donnee'], $maxColumns, ''), array_fill(0, $maxColumns, 'string'), true);
            $rowIndex++;
        }

        $lastDataRow = max($headerRow, $rowIndex - 1);

        return [
            'name' => $name,
            'rows' => $rows,
            'merges' => $merges,
            'maxColumns' => $maxColumns,
            'widths' => $widths,
            'charts' => [],
            'freeze_header' => true,
            'auto_filter_ref' => 'A'.$headerRow.':'.$this->columnName($maxColumns).$lastDataRow,
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function strategySheetRows(array $payload): array
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
    private function paoSheetRows(array $payload): array
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
    private function actionFinalSheetRows(array $payload): array
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
    private function kpiFinalSheetRows(array $payload): array
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
    private function syntheticReportingRows(array $payload): array
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
    private function alertFinalSheetRows(array $payload): array
    {
        $rows = collect($payload['details']['kpi_sous_seuil'] ?? [])
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

        return $rows->merge($lateRows)->values()->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function riskFinalSheetRows(array $payload): array
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
    private function justificatifSheetRows(array $payload): array
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
     * @return array<int, array<int, mixed>>
     */
    private function summarySheetRows(array $payload): array
    {
        return $this->serviceReports($payload)
            ->flatMap(function (array $direction): array {
                return collect($direction['services'] ?? [])
                    ->map(function (array $service) use ($direction): array {
                        $summary = (array) ($service['summary'] ?? []);
                        $directionLabel = $this->entityLabel((array) $direction, 'Direction');
                        $serviceLabel = $this->entityLabel($service, 'Service');

                        return [
                            $directionLabel.' / '.$serviceLabel,
                            (int) ($summary['actions_total'] ?? 0),
                            (int) ($summary['actions_terminees'] ?? 0),
                            (int) ($summary['actions_en_cours'] ?? 0),
                            (int) ($summary['actions_retard'] ?? 0),
                            (float) ($summary['taux_realisation'] ?? 0),
                            (float) ($summary['taux_retard'] ?? 0),
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
    private function alertSheetRows(array $payload): array
    {
        $rows = collect($payload['details']['kpi_sous_seuil'] ?? [])
            ->map(fn ($mesure): array => [
                (string) ($mesure->kpi?->action?->libelle ?? '-'),
                (string) ($mesure->kpi?->libelle ?? '-'),
                (string) ($mesure->periode ?? '-'),
                (float) ($mesure->valeur ?? 0),
                (float) ($mesure->kpi?->seuil_alerte ?? 0),
                'Alerte',
                'Verifier la mesure, documenter l ecart et proposer une action corrective.',
            ]);

        $lateRows = collect($payload['details']['actions_retard'] ?? [])
            ->map(fn ($action): array => [
                (string) ($action->libelle ?? '-'),
                'Retard action',
                optional($action->date_echeance)->format('Y-m-d') ?? '-',
                (float) ($action->progression_reelle ?? 0),
                100.0,
                'En retard',
                'Replanifier, lever les blocages et mettre a jour la progression.',
            ]);

        return $rows->merge($lateRows)->values()->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function actionDetailSheetRows(array $payload): array
    {
        return $this->actionRows($payload)
            ->map(fn (array $row): array => [
                (string) ($row['axe_strategique'] ?? $row['axe'] ?? '-'),
                (string) ($row['objectif_strategique'] ?? $row['objectif'] ?? '-'),
                (string) ($row['objectif_operationnel'] ?? '-'),
                (string) ($row['action'] ?? '-'),
                (string) ($row['responsable'] ?? '-'),
                (string) ($row['debut'] ?? ''),
                (string) ($row['fin'] ?? ''),
                (string) ($row['statut'] ?? '-'),
                (float) ($row['progression_value'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function kpiSheetRows(array $payload): array
    {
        return $this->actionRows($payload)
            ->flatMap(function (array $row): array {
                return collect($row['kpi_rows'] ?? [])
                    ->map(fn (array $kpiRow): array => [
                        (string) ($kpiRow['action'] ?? $row['action'] ?? '-'),
                        (string) ($kpiRow['indicateur'] ?? '-'),
                        (string) ($kpiRow['type'] ?? '-'),
                        ($kpiRow['valeur'] ?? null) !== null ? (float) $kpiRow['valeur'] : 0.0,
                        ($kpiRow['seuil'] ?? null) !== null ? (float) $kpiRow['seuil'] : 0.0,
                        (string) ($kpiRow['statut'] ?? 'Non renseigne'),
                    ])
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function riskSheetRows(array $payload): array
    {
        return $this->actionRows($payload)
            ->filter(fn (array $row): bool => trim((string) ($row['risque_identifie'] ?? '')) !== '')
            ->map(fn (array $row): array => [
                (string) ($row['action'] ?? '-'),
                (string) ($row['risque_identifie'] ?? '-'),
                (string) ($row['niveau_risque'] ?? '-'),
                (string) ($row['mesure_mitigation'] ?? '-'),
                (string) ($row['responsable'] ?? '-'),
                (string) ($row['statut'] ?? '-'),
            ])
            ->values()
            ->all();
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildInstitutionalServiceSheets(array $payload): array
    {
        $reports = collect($payload['details']['direction_service_report'] ?? []);
        if ($reports->isEmpty()) {
            return [];
        }

        $generatedAt = $payload['generatedAt'] instanceof CarbonInterface
            ? $payload['generatedAt']->format('Y-m-d H:i:s')
            : (string) ($payload['generatedAt'] ?? '');
        $usedSheetNames = [];
        $sheets = [];

        foreach ($reports as $direction) {
            $direction = (array) $direction;
            $directionCode = (string) ($direction['code'] ?? '');
            $directionName = (string) ($direction['libelle'] ?? 'Direction');
            $directionLabel = trim($directionCode !== '' ? $directionCode.' - '.$directionName : $directionName);

            foreach ((array) ($direction['services'] ?? []) as $service) {
                $service = (array) $service;
                $serviceCode = (string) ($service['code'] ?? '');
                $serviceName = (string) ($service['libelle'] ?? 'Service');
                $serviceLabel = trim($serviceCode !== '' ? $serviceCode.' - '.$serviceName : $serviceName);
                $summary = (array) ($service['summary'] ?? []);
                $actions = array_values((array) ($service['actions'] ?? []));
                $maxColumns = 9;
                $rows = [];
                $merges = [];
                $rowIndex = 1;

                $rows[] = $this->makeMergedRow($rowIndex, 'ANBG - RAPPORT PAS PAR DIRECTION ET SERVICE', $maxColumns, 1);
                $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
                $rowIndex++;

                $rows[] = $this->makeMergedRow($rowIndex, 'Direction : '.$directionLabel.' | Responsable : '.(string) ($direction['responsable'] ?? '-'), $maxColumns, 2);
                $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
                $rowIndex++;

                $rows[] = $this->makeMergedRow($rowIndex, 'Service : '.$serviceLabel.' | Responsable : '.(string) ($service['responsable'] ?? '-'), $maxColumns, 2);
                $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
                $rowIndex++;

                $rows[] = $this->makeMergedRow($rowIndex, 'Genere le '.$generatedAt, $maxColumns, 2);
                $merges[] = 'A'.$rowIndex.':'.$this->columnName($maxColumns).$rowIndex;
                $rowIndex += 2;

                $rows[] = $this->makeStandardRow($rowIndex, ['Actions', 'Validees', 'Retards', 'Progression', 'Indicateur global', 'Conformite'], ['string', 'string', 'string', 'string', 'string', 'string'], 4);
                $rowIndex++;
                $rows[] = $this->makeDataRow($rowIndex, [
                    (int) ($summary['actions_total'] ?? 0),
                    (int) ($summary['actions_validees'] ?? 0),
                    (int) ($summary['actions_retard'] ?? 0),
                    (float) ($summary['progression_moyenne'] ?? 0),
                    (float) ($summary['kpi_global'] ?? 0),
                    (float) ($summary['kpi_conformite'] ?? 0),
                ], ['integer', 'integer', 'integer', 'percent', 'decimal', 'decimal'], true);
                $rowIndex += 2;

                $headerRow = $rowIndex;
                $rows[] = $this->makeStandardRow($rowIndex, ['Axe strategique', 'Objectif strategique', 'Objectif operationnel', 'Action', 'KPI', 'Prevu', 'Realise', 'Taux', 'Statut'], ['string', 'string', 'string', 'string', 'string', 'string', 'string', 'string', 'string'], 4);
                $rowIndex++;

                foreach ($actions as $index => $action) {
                    $action = (array) $action;
                    $rows[] = $this->makeDataRow($rowIndex, [
                        (string) ($action['axe_strategique'] ?? $action['axe'] ?? '-'),
                        (string) ($action['objectif_strategique'] ?? $action['objectif'] ?? '-'),
                        (string) ($action['objectif_operationnel'] ?? '-'),
                        (string) ($action['action'] ?? '-'),
                        (string) ($action['kpi'] ?? '-'),
                        (string) ($action['prevu'] ?? '-'),
                        (string) ($action['realise'] ?? '-'),
                        (float) ($action['progression_value'] ?? 0),
                        (string) ($action['statut'] ?? '-'),
                    ], ['string', 'string', 'string', 'string', 'string', 'string', 'string', 'percent', 'string'], ($index % 2) === 0);
                    $rowIndex++;
                }

                if ($actions === []) {
                    $rows[] = $this->makeDataRow($rowIndex, ['Aucune action', '', '', '', '', '', '', 0, ''], ['string', 'string', 'string', 'string', 'string', 'string', 'string', 'percent', 'string'], true);
                    $rowIndex++;
                }

                $lastDataRow = max($headerRow, $rowIndex - 1);
                $sheets[] = [
                    'name' => $this->uniqueSheetName($directionLabel.' - '.$serviceLabel, $usedSheetNames),
                    'rows' => $rows,
                    'merges' => $merges,
                    'maxColumns' => $maxColumns,
                    'widths' => [1 => 30, 2 => 30, 3 => 30, 4 => 34, 5 => 28, 6 => 30, 7 => 30, 8 => 14, 9 => 20],
                    'charts' => [],
                    'freeze_header' => true,
                    'auto_filter_ref' => 'A'.$headerRow.':'.$this->columnName($maxColumns).$lastDataRow,
                ];
            }
        }

        return $sheets;
    }

    private function buildSections(array $payload): array
    {
        $officialPolicy = (array) ($payload['officialPolicy'] ?? []);
        $officialBaseLabel = (string) ($officialPolicy['threshold_label'] ?? 'Toutes les actions visibles');
        $sections = [];
        $sections[] = [
            'title' => 'Repères de lecture',
            'headers' => ['Bloc', 'Usage'],
            'types' => ['string', 'string'],
            'rows' => [
                ['Indicateurs globaux', 'Volumes et perimetre courant'],
                ['Statuts', 'Lecture operationnelle des modules'],
                ['Alertes de synthese', 'Ecarts et urgences en cours'],
                ['Synthese des indicateurs', 'Base statistique : '.$officialBaseLabel],
                ['Vue du PAS', 'Transformation strategique consolidee'],
                ['Comparaison interannuelle', 'Comparaison des exercices'],
                ['Details - Actions en retard', 'Actions a traiter immediatement'],
                ['Details - indicateurs sous seuil', 'Mesures en ecart avec suivi metier'],
                ['Rapports par direction/service', 'Index hierarchique des feuilles detaillees'],
            ],
        ];

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
            'title' => 'Synthese des indicateurs',
            'headers' => ['Indicateur delai', 'Indicateur performance', 'Indicateur conformite', 'Indicateur qualite', 'Indicateur risque', 'Indicateur global', 'Progression moyenne'],
            'types' => ['decimal', 'decimal', 'decimal', 'decimal', 'decimal', 'decimal', 'percent'],
            'rows' => [[
                (float) ($payload['kpiSummary']['delai'] ?? 0),
                (float) ($payload['kpiSummary']['performance'] ?? 0),
                (float) ($payload['kpiSummary']['conformite'] ?? 0),
                (float) ($payload['kpiSummary']['qualite'] ?? 0),
                (float) ($payload['kpiSummary']['risque'] ?? 0),
                (float) ($payload['kpiSummary']['global'] ?? 0),
                (float) ($payload['kpiSummary']['progression'] ?? 0),
            ]],
        ];

        $sections[] = [
            'title' => 'Vue du PAS',
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
            'headers' => ['ID', 'Libelle', 'Echeance', 'Statut', 'PTA', 'Responsable', 'Indicateur global', 'Indicateur qualite', 'Indicateur risque'],
            'types' => ['integer', 'string', 'string', 'string', 'string', 'string', 'decimal', 'decimal', 'decimal'],
            'rows' => collect($payload['details']['actions_retard'] ?? [])->map(fn ($action): array => [(int) $action->id, (string) $action->libelle, optional($action->date_echeance)->format('Y-m-d') ?? '', (string) $action->statut_dynamique, (string) ($action->pta?->titre ?? ''), (string) ($action->responsable?->name ?? ''), (float) ($action->actionKpi?->kpi_global ?? 0), (float) ($action->actionKpi?->kpi_qualite ?? 0), (float) ($action->actionKpi?->kpi_risque ?? 0)])->all(),
        ];

        $sections[] = [
            'title' => 'Details - indicateurs sous seuil',
            'headers' => ['Mesure ID', 'Indicateur', 'Periode', 'Valeur', 'Seuil', 'Action'],
            'types' => ['integer', 'string', 'string', 'decimal', 'decimal', 'string'],
            'rows' => collect($payload['details']['kpi_sous_seuil'] ?? [])->map(fn ($mesure): array => [(int) $mesure->id, (string) ($mesure->kpi?->libelle ?? ''), (string) $mesure->periode, (float) ($mesure->valeur ?? 0), (float) ($mesure->kpi?->seuil_alerte ?? 0), (string) ($mesure->kpi?->action?->libelle ?? '')])->all(),
        ];

        $sections[] = [
            'title' => 'Rapports par direction/service',
            'headers' => ['Direction', 'Responsable direction', 'Service', 'Responsable service', 'Actions', 'Validees', 'Retards', 'Progression', 'Indicateur global', 'Conformite'],
            'types' => ['string', 'string', 'string', 'string', 'integer', 'integer', 'integer', 'percent', 'decimal', 'decimal'],
            'rows' => collect($payload['details']['direction_service_report'] ?? [])
                ->flatMap(function (array $direction): array {
                    return collect($direction['services'] ?? [])
                        ->map(function (array $service) use ($direction): array {
                            $summary = (array) ($service['summary'] ?? []);

                            return [
                                trim((string) (($direction['code'] ?? '') !== '' ? ($direction['code'].' - '.($direction['libelle'] ?? '')) : ($direction['libelle'] ?? ''))),
                                (string) ($direction['responsable'] ?? '-'),
                                trim((string) (($service['code'] ?? '') !== '' ? ($service['code'].' - '.($service['libelle'] ?? '')) : ($service['libelle'] ?? ''))),
                                (string) ($service['responsable'] ?? '-'),
                                (int) ($summary['actions_total'] ?? 0),
                                (int) ($summary['actions_validees'] ?? 0),
                                (int) ($summary['actions_retard'] ?? 0),
                                (float) ($summary['progression_moyenne'] ?? 0),
                                (float) ($summary['kpi_global'] ?? 0),
                                (float) ($summary['kpi_conformite'] ?? 0),
                            ];
                        })
                        ->all();
                })
                ->values()
                ->all(),
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
    private function sheetXml(
        array $rows,
        array $merges,
        int $maxColumns,
        array $widths = [],
        ?string $drawingRelationshipId = null,
        bool $freezeHeader = false,
        ?string $autoFilterRef = null
    ): string
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

        $sheetViewXml = $freezeHeader
            ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/></sheetView></sheetViews>'
            : '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<dimension ref="A1:'.$this->columnName($maxColumns).$lastRow.'"/>'
            .$sheetViewXml
            .'<sheetFormatPr defaultRowHeight="18"/>'
            .'<cols>'.$columns.'</cols>'
            .'<sheetData>'.$sheetRows.'</sheetData>'
            .($autoFilterRef !== null ? '<autoFilter ref="'.$autoFilterRef.'"/>' : '')
            .$mergeXml
            .'<pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.2" footer="0.2"/>'
            .'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
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

    private function uniqueSheetName(string $rawName, array &$usedSheetNames): string
    {
        $name = (string) Str::of($rawName)
            ->ascii()
            ->replaceMatches('/[\\[\\]\\:\\*\\?\\/\\\\]+/', ' ')
            ->replaceMatches('/\\s+/', ' ')
            ->trim();
        if ($name === '') {
            $name = 'Service';
        }

        $base = Str::limit($name, 31, '');
        $candidate = $base;
        $suffix = 2;
        while (in_array($candidate, $usedSheetNames, true)) {
            $suffixText = ' '.$suffix;
            $candidate = Str::limit($base, 31 - strlen($suffixText), '').$suffixText;
            $suffix++;
        }

        $usedSheetNames[] = $candidate;

        return $candidate;
    }

    private function logoContents(): ?string
    {
        $path = public_path('images/logo-wordmark.png');
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function contentTypesXml(int $sheetCount, int $drawingCount = 0, int $chartCount = 0, bool $includeLogo = false): string
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
            .($includeLogo ? '<Default Extension="png" ContentType="image/png"/>' : '')
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

    private function drawingXml(array $charts, bool $includeLogo = false): string
    {
        $anchors = '';
        if ($includeLogo) {
            $anchors .= '<xdr:oneCellAnchor editAs="oneCell">'
                .'<xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
                .'<xdr:ext cx="1200000" cy="420000"/>'
                .'<xdr:pic>'
                .'<xdr:nvPicPr><xdr:cNvPr id="1" name="Logo ANBG"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
                .'<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                .'<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="1200000" cy="420000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
                .'</xdr:pic>'
                .'<xdr:clientData/>'
                .'</xdr:oneCellAnchor>';
        }

        $relationshipOffset = $includeLogo ? 2 : 1;
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
                .'<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId'.($index + $relationshipOffset).'"/>'
                .'</a:graphicData></a:graphic>'
                .'</xdr:graphicFrame>'
                .'<xdr:clientData/>'
                .'</xdr:twoCellAnchor>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$anchors
            .'</xdr:wsDr>';
    }

    private function drawingRelationshipsXml(int $startChartIndex, int $count, bool $includeLogo = false): string
    {
        $relationships = '';
        if ($includeLogo) {
            $relationships .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.png"/>';
        }

        for ($chartOffset = 0; $chartOffset < $count; $chartOffset++) {
            $chartIndex = $startChartIndex + $chartOffset;
            $relationships .= '<Relationship Id="rId'.($chartOffset + 1 + ($includeLogo ? 1 : 0)).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart'.$chartIndex.'.xml"/>';
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
        $color = strtoupper((string) ($chart['color'] ?? '1E3A8A'));
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

    private function corePropertiesXml(CarbonInterface|string|null $generatedAt, string $title): string
    {
        $timestamp = $generatedAt instanceof CarbonInterface
            ? $generatedAt->copy()->utc()->format('Y-m-d\TH:i:s\Z')
            : now()->utc()->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.htmlspecialchars($title, ENT_XML1).'</dc:title>'
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
            .'<font><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="18"/><color rgb="FF1F2937"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            .'</fonts>'
            .'<fills count="11">'
            .'<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A8A"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF3B82F6"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF8FBFF"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF10B981"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFECFDF5"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF59E0B"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFFBEB"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFBFDBFE"/></left><right style="thin"><color rgb="FFBFDBFE"/></right><top style="thin"><color rgb="FFBFDBFE"/></top><bottom style="thin"><color rgb="FFBFDBFE"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="21">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
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

