<?php

namespace App\Services\Ai;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Services\Imports\SimpleSpreadsheet;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PtaExtractionService
{
    public function __construct(
        private readonly SimpleSpreadsheet $spreadsheet
    ) {}

    /**
     * @return array{created:int,confidence:float}
     */
    public function extract(AiImportBatch $batch): array
    {
        $batch->forceFill([
            'status' => AiImportBatch::STATUS_EXTRACTING,
            'error_message' => null,
        ])->save();

        try {
            $rows = $this->extractRows($batch);

            $batch->rows()->delete();
            foreach ($rows as $index => $row) {
                AiImportRow::query()->create([
                    'batch_id' => $batch->id,
                    'row_number' => (int) ($row['_row_number'] ?? ($index + 2)),
                    'raw_payload' => Arr::except($row, ['_row_number']),
                    'normalized_payload' => null,
                    'validation_errors' => null,
                    'status' => AiImportRow::STATUS_PENDING,
                ]);
            }

            $confidence = $this->confidenceFor($batch->file_type, count($rows));
            $batch->forceFill([
                'status' => AiImportBatch::STATUS_EXTRACTED,
                'confidence_score' => $confidence,
            ])->save();

            return ['created' => count($rows), 'confidence' => $confidence];
        } catch (Throwable $exception) {
            $batch->forceFill([
                'status' => AiImportBatch::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractRows(AiImportBatch $batch): array
    {
        $path = Storage::disk('local')->path($batch->file_path);
        $extension = strtolower((string) $batch->file_type);

        if (in_array($extension, ['csv', 'xlsx'], true)) {
            $workbook = $this->spreadsheet->read($path);

            return array_values($workbook['rows'] ?? []);
        }

        if (! Storage::disk('local')->exists($batch->file_path)) {
            throw new RuntimeException('Le fichier source est introuvable.');
        }

        return [[
            '_row_number' => 1,
            'source_document' => $batch->original_filename,
            'type_document' => $extension,
            'note_extraction' => 'Document non tabulaire accepte pour analyse manuelle; aucune donnee metier n est inventee.',
        ]];
    }

    private function confidenceFor(string $fileType, int $rowCount): float
    {
        if ($rowCount < 1) {
            return 0.0;
        }

        return in_array(strtolower($fileType), ['csv', 'xlsx'], true) ? 82.0 : 35.0;
    }
}
