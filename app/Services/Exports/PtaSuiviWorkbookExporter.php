<?php

namespace App\Services\Exports;

use App\Support\Zip\SimpleZipWriter;
use Carbon\CarbonInterface;
use RuntimeException;

class PtaSuiviWorkbookExporter
{
    public function __construct(
        private readonly SimpleZipWriter $zipWriter = new SimpleZipWriter
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'pta_suivi_xlsx_');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to allocate temporary file for PTA XLSX export.');
        }

        $rows = $this->rows($payload);
        $entries = [
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => $this->rootRelationshipsXml(),
            'docProps/app.xml' => $this->appPropertiesXml(),
            'docProps/core.xml' => $this->corePropertiesXml($payload['generatedAt'] ?? null, (string) ($payload['title'] ?? 'Suivi PTA')),
            'xl/workbook.xml' => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationshipsXml(),
            'xl/styles.xml' => $this->stylesXml(),
            'xl/worksheets/sheet1.xml' => $this->sheetXml($rows),
        ];

        try {
            $this->zipWriter->write($tempPath, $entries);
        } catch (\Throwable $exception) {
            @unlink($tempPath);

            throw $exception;
        }

        return $tempPath;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{cells:list<string>,style:int}>
     */
    private function rows(array $payload): array
    {
        $rows = [];
        $rows[] = ['cells' => [(string) ($payload['title'] ?? 'SUIVI PTA')], 'style' => 1];
        $rows[] = ['cells' => [(string) ($payload['scopeLabel'] ?? '')], 'style' => 2];
        $rows[] = ['cells' => [], 'style' => 0];

        $headers = [
            'N',
            'Actions',
            'Indicateurs de mesure',
            'Responsable',
            'Ratio',
            'Cible',
            'Realise',
            'Taux (%)',
            'Progression',
            'Performance en fonction de la cible',
            'Ecart',
            'Echeance',
            'Retard',
            'Statut action',
            'Statut de suivi',
            'Statut delai',
            'Preuve',
            'Observations',
        ];

        $counter = 1;
        foreach (collect($payload['groups'] ?? []) as $pasGroup) {
            $pasGroup = (array) $pasGroup;
            $rows[] = ['cells' => [
                (string) ($pasGroup['code'] ?? 'PAS'),
                'AXE STRATEGIQUE / OBJECTIFS',
                '',
                '',
                '',
                '',
                '',
                'Performance PAS',
                number_format((float) ($pasGroup['performance'] ?? 0), 2).'%',
            ], 'style' => 3];

            foreach (collect($pasGroup['axes'] ?? []) as $axisGroup) {
                $axisGroup = (array) $axisGroup;
                $rows[] = ['cells' => ['', 'Axe strategique', (string) ($axisGroup['label'] ?? '-'), '', '', '', '', '', number_format((float) ($axisGroup['performance'] ?? 0), 2).'%'], 'style' => 4];

                foreach (collect($axisGroup['objectifs'] ?? []) as $strategicGroup) {
                    $strategicGroup = (array) $strategicGroup;
                    $rows[] = ['cells' => ['', 'Objectif strategique', (string) ($strategicGroup['label'] ?? '-'), '', '', '', '', '', number_format((float) ($strategicGroup['performance'] ?? 0), 2).'%'], 'style' => 5];

                    foreach (collect($strategicGroup['objectifs_operationnels'] ?? []) as $operationalGroup) {
                        $operationalGroup = (array) $operationalGroup;
                        $rows[] = ['cells' => [
                            '',
                            'Objectif operationnel',
                            (string) ($operationalGroup['label'] ?? '-'),
                            '',
                            '',
                            '',
                            '',
                            'Performance',
                            number_format((float) ($operationalGroup['performance'] ?? 0), 2).'%',
                        ], 'style' => 6];
                        $rows[] = ['cells' => $headers, 'style' => 7];

                        foreach (collect($operationalGroup['actions'] ?? []) as $actionRow) {
                            $actionRow = (array) $actionRow;
                            $rows[] = ['cells' => [
                                (string) $counter++,
                                (string) ($actionRow['libelle'] ?? '-'),
                                (string) ($actionRow['indicateur'] ?? '-'),
                                (string) ($actionRow['responsable'] ?? '-'),
                                (string) ($actionRow['ratio'] ?? '-'),
                                (string) ($actionRow['cible'] ?? '-'),
                                (string) ($actionRow['realise'] ?? '-'),
                                (string) ($actionRow['taux_realisation_label'] ?? '-'),
                                (string) ($actionRow['taux_realisation_label'] ?? '-'),
                                (string) ($actionRow['performance_label'] ?? '-'),
                                (string) ($actionRow['ecart_label'] ?? '-'),
                                (string) ($actionRow['echeance_label'] ?? '-'),
                                (string) ($actionRow['retard_label'] ?? (((int) ($actionRow['retard_jours'] ?? 0)).' j')),
                                (string) ($actionRow['statut_action_label'] ?? '-'),
                                (string) ($actionRow['statut_suivi_label'] ?? '-'),
                                (string) ($actionRow['statut_delai_label'] ?? '-'),
                                (bool) ($actionRow['has_preuve'] ?? false)
                                    ? 'Visualiser la preuve ('.((int) ($actionRow['preuve_count'] ?? 0)).')'
                                    : 'Aucune preuve',
                                (string) ($actionRow['observations'] ?? '-'),
                            ], 'style' => 0];
                        }

                        $rows[] = ['cells' => [], 'style' => 0];
                    }
                }
            }
        }

        if (count($rows) === 3) {
            $rows[] = ['cells' => ['Aucune action disponible pour les filtres actifs.'], 'style' => 2];
        }

        return $rows;
    }

    /**
     * @param  list<array{cells:list<string>,style:int}>  $rows
     */
    private function sheetXml(array $rows): string
    {
        $xmlRows = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $cells = [];
            foreach ($row['cells'] as $columnIndex => $value) {
                $cellRef = $this->columnName($columnIndex + 1).$rowNumber;
                $style = (int) $row['style'];
                $cells[] = '<c r="'.$cellRef.'" t="inlineStr" s="'.$style.'"><is><t>'.$this->xml($value).'</t></is></c>';
            }

            $xmlRows[] = '<row r="'.$rowNumber.'">'.implode('', $cells).'</row>';
        }

        $cols = [];
        $widths = [8, 38, 34, 24, 12, 20, 20, 14, 16, 20, 12, 14, 10, 16, 18, 16, 20, 58];
        foreach ($widths as $index => $width) {
            $col = $index + 1;
            $cols[] = '<col min="'.$col.'" max="'.$col.'" width="'.$width.'" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<cols>'.implode('', $cols).'</cols>'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .'</worksheet>';
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
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

    private function workbookRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Suivi PTA" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function appPropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>ANBG PAS</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop><TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Suivi PTA</vt:lpstr></vt:vector></TitlesOfParts>'
            .'</Properties>';
    }

    private function corePropertiesXml(mixed $generatedAt, string $title): string
    {
        $date = $generatedAt instanceof CarbonInterface ? $generatedAt->toIso8601String() : now()->toIso8601String();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.$this->xml($title).'</dc:title><dc:creator>ANBG PAS</dc:creator><cp:lastModifiedBy>ANBG PAS</cp:lastModifiedBy>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$date.'</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">'.$date.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="4"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            .'<fills count="7"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F2F57"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F2F57"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1E5FA8"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD8ECFF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="0" fontId="2" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
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

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
