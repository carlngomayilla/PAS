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
        private readonly SimpleSpreadsheet $spreadsheet,
        private readonly PtaDocumentTextExtractionService $textExtraction,
        private readonly PtaDocumentStructureExtractorService $structureExtractor,
        private readonly PtaDocumentToImportGlobalMapperService $documentMapper
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

        if ($extension === 'pdf') {
            return $this->extractDocumentRows($batch, $path, $extension);
        }

        throw new RuntimeException('Ce type de document necessite une extraction OCR/texte avant analyse PTA.');
    }

    private function confidenceFor(string $fileType, int $rowCount): float
    {
        if ($rowCount < 1) {
            return 0.0;
        }

        return in_array(strtolower($fileType), ['csv', 'xlsx'], true) ? 82.0 : 70.0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractDocumentRows(AiImportBatch $batch, string $path, string $extension): array
    {
        $text = $this->textExtraction->extract($path, $extension);
        $structured = $this->structureExtractor->extractFromText($text);
        $structured['document'] = array_replace(
            $structured['document'] ?? [],
            array_filter([
                'annee' => $batch->detected_year,
                'annee_debut_pas' => $batch->detected_year,
                'annee_fin_pas' => $batch->detected_year,
                'direction' => $batch->detected_direction,
                'service_unite' => $batch->detected_service,
                'source_document' => $batch->original_filename,
            ], static fn (mixed $value): bool => $value !== null && trim((string) $value) !== '')
        );

        if (($structured['items'] ?? []) === []) {
            throw new RuntimeException('Aucune action PTA exploitable n a ete detectee dans le document.');
        }

        $mapped = $this->documentMapper->map($structured);
        if (($mapped['rows'] ?? []) === []) {
            throw new RuntimeException('Aucune ligne IMPORT_GLOBAL n a pu etre produite depuis le document.');
        }

        return array_values(array_map(
            fn (array $row, int $index): array => $this->withGenericAliases($row, $index + 1),
            $mapped['rows'],
            array_keys($mapped['rows'])
        ));
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function withGenericAliases(array $row, int $rowNumber): array
    {
        return array_merge($row, [
            '_row_number' => $rowNumber,
            'exercice' => $row['annee_debut_pas'] ?? null,
            'axe_strategique' => $row['libelle_axe'] ?? null,
            'objectif_strategique' => $row['libelle_objectif_strategique'] ?? null,
            'programme' => $row['libelle_objectif_operationnel'] ?? null,
            'code_action' => $row['ordre_action'] ?? null,
            'service' => $row['service_unite'] ?? null,
            'responsable' => $row['rmo_raw'] ?? $row['codes_agents_rmo'] ?? null,
            'indicateur' => $row['justificatif_attendu'] ?? null,
            'cible' => $row['cible_minimum_execution'] ?? null,
            'date_debut' => $row['date_debut_action'] ?? null,
            'date_fin' => $row['date_fin_action'] ?? null,
            'echeance' => $row['date_echeance_objectif_operationnel'] ?? $row['date_fin_action'] ?? null,
            'ressources_requises' => $row['ressources_materielles'] ?? null,
            'risques_potentiels' => $row['risque'] ?? null,
            'budget_previsionnel' => $row['montant_financement'] ?? null,
            'source_financement' => $row['nature_financement'] ?? null,
        ]);
    }
}
