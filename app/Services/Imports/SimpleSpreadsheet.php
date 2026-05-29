<?php

namespace App\Services\Imports;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

class SimpleSpreadsheet
{
    /**
     * @return array{sheet_count:int,sheet_name:string,headers:list<string>,rows:list<array<string,mixed>>}
     */
    public function read(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $extension = strtolower(pathinfo($file instanceof UploadedFile ? $file->getClientOriginalName() : $path, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            return $this->readCsv((string) $path);
        }

        if ($extension === 'xlsx') {
            return $this->readXlsx((string) $path);
        }

        throw new RuntimeException('Le fichier doit etre au format .xlsx ou .csv.');
    }

    /**
     * @param list<string> $headers
     * @param list<array<string,mixed>> $rows
     */
    public function downloadCsv(string $filename, array $headers, array $rows): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers, ';');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $header): mixed => $row[$header] ?? '', $headers), ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param list<string> $headers
     * @param list<array<string,mixed>> $rows
     */
    public function downloadXlsx(string $filename, array $headers, array $rows, string $sheetName = 'IMPORT_GLOBAL'): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! class_exists(ZipArchive::class)) {
            return $this->downloadCsv(str_replace('.xlsx', '.csv', $filename), $headers, $rows);
        }

        $path = tempnam(sys_get_temp_dir(), 'planning-import-');
        if ($path === false) {
            throw new RuntimeException('Impossible de preparer le fichier Excel.');
        }

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->relsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($headers, $rows));
        $zip->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @return array{sheet_count:int,sheet_name:string,headers:list<string>,rows:list<array<string,mixed>>}
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Impossible de lire le fichier.');
        }

        $headers = [];
        $rows = [];
        $line = 0;
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $line++;
            if ($line === 1) {
                $headers = array_map([$this, 'normalizeHeader'], $data);
                continue;
            }
            if ($this->isEmptyRow($data)) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $data[$index] ?? null;
            }
            $row['_row_number'] = $line;
            $rows[] = $row;
        }
        fclose($handle);

        return ['sheet_count' => 1, 'sheet_name' => 'IMPORT_GLOBAL', 'headers' => $headers, 'rows' => $rows];
    }

    private const SS_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @return array{sheet_count:int,sheet_name:string,headers:list<string>,rows:list<array<string,mixed>>}
     */
    private function readXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException("L'extension PHP Zip est requise pour lire les fichiers .xlsx.");
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Fichier Excel invalide.');
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false) {
            $zip->close();
            throw new RuntimeException('Le classeur ne contient pas de workbook.xml.');
        }

        $workbook = simplexml_load_string($workbookXml);
        if ($workbook === false) {
            $zip->close();
            throw new RuntimeException('workbook.xml illisible.');
        }

        // children(ns) gere a la fois le namespace par defaut (xmlns="...")
        // et les namespaces prefixes (xmlns:x="..."), ce qui rend le parser
        // tolerant aux fichiers generes par ClosedXML / OpenXML SDK / OneDrive.
        $sheetsContainer = $workbook->children(self::SS_NAMESPACE)->sheets ?? null;
        $sheetNodes = $sheetsContainer !== null ? $sheetsContainer->children(self::SS_NAMESPACE)->sheet : null;
        $sheetCount = $sheetNodes !== null ? count($sheetNodes) : 0;
        if ($sheetCount < 1) {
            $zip->close();
            throw new RuntimeException('Le classeur ne contient aucune feuille.');
        }
        $sheetName = (string) ($sheetNodes[0]->attributes()['name'] ?? 'IMPORT_GLOBAL');

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $shared = simplexml_load_string($sharedXml);
            if ($shared !== false) {
                foreach ($shared->children(self::SS_NAMESPACE)->si ?? [] as $si) {
                    $parts = [];
                    $tNodes = $si->children(self::SS_NAMESPACE)->t;
                    if ($tNodes !== null && count($tNodes) > 0) {
                        $parts[] = (string) $tNodes[0];
                    }
                    foreach ($si->children(self::SS_NAMESPACE)->r ?? [] as $run) {
                        $parts[] = (string) ($run->children(self::SS_NAMESPACE)->t ?? '');
                    }
                    $sharedStrings[] = implode('', $parts);
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) {
            throw new RuntimeException('La premiere feuille Excel est introuvable.');
        }

        $xml = simplexml_load_string($sheetXml);
        if ($xml === false) {
            throw new RuntimeException('La premiere feuille Excel est illisible.');
        }

        $matrix = [];
        $sheetData = $xml->children(self::SS_NAMESPACE)->sheetData ?? null;
        $rowNodes = $sheetData !== null ? $sheetData->children(self::SS_NAMESPACE)->row : [];
        foreach ($rowNodes ?? [] as $row) {
            $rowNumber = (int) ($row->attributes()['r'] ?? 0);
            foreach ($row->children(self::SS_NAMESPACE)->c ?? [] as $cell) {
                $ref = (string) ($cell->attributes()['r'] ?? '');
                $columnIndex = $this->columnIndex($ref);
                $matrix[$rowNumber][$columnIndex] = $this->cellValue($cell, $sharedStrings);
            }
        }

        ksort($matrix);
        $headerRow = $matrix[array_key_first($matrix)] ?? [];
        ksort($headerRow);
        $headers = array_map([$this, 'normalizeHeader'], array_values($headerRow));
        $rows = [];

        foreach ($matrix as $rowNumber => $values) {
            if ($rowNumber === array_key_first($matrix)) {
                continue;
            }
            ksort($values);
            if ($this->isEmptyRow($values)) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $values[$index + 1] ?? null;
            }
            $row['_row_number'] = $rowNumber;
            $rows[] = $row;
        }

        return ['sheet_count' => $sheetCount, 'sheet_name' => $sheetName, 'headers' => $headers, 'rows' => $rows];
    }

    private function cellValue(\SimpleXMLElement $cell, array $sharedStrings): ?string
    {
        $type = (string) ($cell->attributes()['t'] ?? '');
        $cellChildren = $cell->children(self::SS_NAMESPACE);

        if ($type === 'inlineStr') {
            $is = $cellChildren->is ?? null;
            $inline = $is !== null ? (string) ($is->children(self::SS_NAMESPACE)->t ?? '') : '';

            return trim($inline);
        }

        $value = (string) ($cellChildren->v ?? '');
        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        return trim($value);
    }

    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?: 'A';
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }

    private function normalizeHeader(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function isEmptyRow(array $row): bool
    {
        return collect($row)->every(fn ($value): bool => trim((string) $value) === '');
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    private function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.htmlspecialchars($sheetName, ENT_XML1).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }

    private function sheetXml(array $headers, array $rows): string
    {
        $allRows = [array_combine($headers, $headers)];
        foreach ($rows as $row) {
            $allRows[] = $row;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($allRows as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $xml .= '<row r="'.$excelRow.'">';
            foreach ($headers as $columnIndex => $header) {
                $cell = $this->columnLetters($columnIndex + 1).$excelRow;
                $value = htmlspecialchars((string) ($row[$header] ?? ''), ENT_XML1);
                $xml .= '<c r="'.$cell.'" t="inlineStr"><is><t>'.$value.'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function columnLetters(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)).$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }
}
