<?php

namespace App\Exports;

use App\Models\AiImportBatch;
use App\Services\Ai\PtaNormalizationService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PtaNormalizedWorkbookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly AiImportBatch $batch
    ) {}

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new ArraySheetExport('PTA normalise', $this->normalizedRows()),
            new PtaImportErrorsExport($this->batch),
            new PtaImportMetadataExport($this->batch),
        ];
    }

    /**
     * @return list<list<mixed>>
     */
    private function normalizedRows(): array
    {
        $headers = array_map(
            static fn (string $field): string => str_replace('_', ' ', ucfirst($field)),
            PtaNormalizationService::FIELDS
        );

        $rows = [$headers];
        foreach ($this->batch->rows()->get() as $row) {
            $payload = $row->normalized_payload ?? [];
            $rows[] = array_map(
                static fn (string $field): mixed => $payload[$field] ?? null,
                PtaNormalizationService::FIELDS
            );
        }

        return $rows;
    }
}
