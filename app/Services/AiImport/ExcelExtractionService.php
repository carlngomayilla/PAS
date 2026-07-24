<?php

namespace App\Services\AiImport;

use App\Services\Imports\SimpleSpreadsheet;

class ExcelExtractionService
{
    public function __construct(
        private readonly SimpleSpreadsheet $spreadsheet
    ) {}

    /**
     * @return array{source_type:string,text:string,rows:list<array<string,mixed>>,metadata:array<string,mixed>}
     */
    public function extract(string $absolutePath): array
    {
        $sheet = $this->spreadsheet->read($absolutePath);
        $rows = array_values(array_filter($sheet['rows'] ?? [], static fn (mixed $row): bool => is_array($row)));

        return [
            'source_type' => 'spreadsheet',
            'text' => json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            'rows' => $rows,
            'metadata' => [
                'sheet_count' => $sheet['sheet_count'],
                'sheet_name' => $sheet['sheet_name'],
                'headers' => $sheet['headers'],
            ],
        ];
    }
}
