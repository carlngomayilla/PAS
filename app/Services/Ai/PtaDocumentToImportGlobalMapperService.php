<?php

namespace App\Services\Ai;

class PtaDocumentToImportGlobalMapperService
{
    public function __construct(
        private readonly PtaAiImportNormalizerService $normalizer,
        private readonly PtaImportTemplateAnalyzerService $template
    ) {}

    /**
     * @param  array{document?:array<string,mixed>,items?:list<array<string,mixed>>,rows?:list<array<string,mixed>>,log?:list<array<string,mixed>>}  $structured
     * @return array{columns:list<string>,rows:list<array<string,mixed>>,valid:int,invalid:int,quality:list<array<string,mixed>>}
     */
    public function map(array $structured): array
    {
        $document = $structured['document'] ?? [];
        $items = $this->itemsFromStructured($structured);
        $rows = [];
        $quality = [];
        $valid = 0;
        $invalid = 0;

        foreach ($this->normalizer->normalizeItems($document, $items) as $result) {
            $rows[] = $result['row'];
            $quality[] = [
                'valid' => $result['validation']['valid'],
                'score' => $result['validation']['score'],
                'errors' => $result['validation']['errors'],
                'warnings' => $result['validation']['warnings'],
                'agent_resolution' => $result['agent_resolution'],
            ];

            if ($result['validation']['valid']) {
                $valid++;
            } else {
                $invalid++;
            }
        }

        return [
            'columns' => $this->template->columns(),
            'rows' => $rows,
            'valid' => $valid,
            'invalid' => $invalid,
            'quality' => $quality,
        ];
    }

    /**
     * @param  array{items?:list<array<string,mixed>>,rows?:list<array<string,mixed>>,log?:list<array<string,mixed>>}  $structured
     * @return list<array<string,mixed>>
     */
    private function itemsFromStructured(array $structured): array
    {
        $items = $structured['items'] ?? $structured['rows'] ?? [];
        $items = array_values(array_filter(
            is_array($items) ? $items : [],
            static fn (mixed $item): bool => is_array($item)
        ));

        $log = array_values(array_filter(
            is_array($structured['log'] ?? null) ? $structured['log'] : [],
            static fn (mixed $item): bool => is_array($item)
        ));

        return $log === [] ? $items : $this->mergeLogIntoItems($items, $log);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  list<array<string,mixed>>  $log
     * @return list<array<string,mixed>>
     */
    private function mergeLogIntoItems(array $items, array $log): array
    {
        $logsByLine = [];
        foreach ($log as $index => $logRow) {
            $line = $this->lineNumber($logRow);
            if ($line !== null) {
                $logsByLine[$line] = $logRow;
            }

            $log[$index] = $logRow;
        }

        foreach ($items as $index => $item) {
            $line = $this->lineNumber($item) ?? ($index + 1);
            $logRow = $logsByLine[$line] ?? $log[$index] ?? [];
            $items[$index] = $this->mergeLogRow($logRow, $item);
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $logRow
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function mergeLogRow(array $logRow, array $item): array
    {
        foreach ($item as $key => $value) {
            if ($this->blank($value) && array_key_exists((string) $key, $logRow)) {
                continue;
            }

            $logRow[(string) $key] = $value;
        }

        return $logRow;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function lineNumber(array $row): ?int
    {
        $value = $row['ligne_import'] ?? $row['row_number'] ?? $row['_row_number'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function blank(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }
}
