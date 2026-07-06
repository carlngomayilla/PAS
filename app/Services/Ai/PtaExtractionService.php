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
    private ?string $lastWarning = null;

    public function __construct(
        private readonly SimpleSpreadsheet $spreadsheet,
        private readonly PtaDocumentTextExtractionService $textExtraction,
        private readonly PtaDocumentVisionSourceService $visionSources,
        private readonly PtaExternalAiExtractionService $externalAi,
        private readonly PtaDocumentStructureExtractorService $structureExtractor,
        private readonly PtaDocumentToImportGlobalMapperService $documentMapper
    ) {}

    /**
     * @return array{created:int,confidence:float,warning:?string}
     */
    public function extract(AiImportBatch $batch): array
    {
        $this->lastWarning = null;

        $batch->forceFill([
            'status' => AiImportBatch::STATUS_EXTRACTING,
            'error_message' => null,
        ])->save();

        try {
            $rows = $this->extractRows($batch);
            $warning = $this->lastWarning ?? $this->externalAi->lastFailureMessage();

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
                'error_message' => $warning,
            ])->save();

            return ['created' => count($rows), 'confidence' => $confidence, 'warning' => $warning];
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
            $rows = array_values($workbook['rows'] ?? []);
            $structured = $this->externalAi->extractFromRows($rows, $this->batchMetadata($batch, [
                'source_type' => $extension,
                'source_sheet' => $workbook['sheet_name'] ?? null,
                'source_sheets' => $workbook['sheet_names'] ?? [],
            ]));

            return $structured === null ? $rows : $this->rowsFromStructured($structured);
        }

        if (! Storage::disk('local')->exists($batch->file_path)) {
            throw new RuntimeException('Le fichier source est introuvable.');
        }

        if (in_array($extension, ['pdf', 'png', 'jpg', 'jpeg'], true)) {
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
        $metadata = $this->batchMetadata($batch, [
            'source_type' => $extension,
        ]);

        $structured = $this->extractWithVisionFirst($path, $extension, $metadata);
        if ($structured !== null) {
            return $this->rowsFromStructured($structured);
        }
        $this->rememberExternalAiWarning();

        if (in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
            throw new RuntimeException('Ce fichier image PTA necessite une IA vision operationnelle. Verifiez Ollama, AI_PTA_VISION_MODEL et AI_PTA_LLM_ENABLED, ou importez le modele Excel/source texte.');
        }

        $text = $this->textExtraction->extract($path, $extension);

        $structured = $this->externalAi->extractFromText($text, $metadata);
        if ($structured !== null) {
            return $this->rowsFromStructured($structured);
        }
        $this->rememberExternalAiWarning();

        $structured = $this->structureExtractor->extractFromText($text);
        $structured['document'] = array_replace($structured['document'] ?? [], $metadata);

        if (($structured['items'] ?? []) === []) {
            throw new RuntimeException('Aucune action PTA exploitable n a ete detectee dans le document.');
        }

        return $this->rowsFromStructured($structured);
    }

    private function rememberExternalAiWarning(): void
    {
        $message = $this->externalAi->lastFailureMessage();
        if ($this->lastWarning !== null || $message === null || trim($message) === '') {
            return;
        }

        $this->lastWarning = $message;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array{document:array<string,mixed>,rows:list<array<string,mixed>>,log:list<array<string,mixed>>}|null
     */
    private function extractWithVisionFirst(string $path, string $extension, array $metadata): ?array
    {
        if (! $this->externalAi->available()) {
            return null;
        }

        $sources = $this->visionSources->sources($path, $extension);
        if (($sources['paths'] ?? []) === []) {
            return null;
        }

        try {
            return $this->externalAi->extractFromImages($sources['paths'], array_replace($metadata, [
                'source_vision' => $sources['source'] ?? 'image',
            ]));
        } finally {
            $this->visionSources->cleanup($sources);
        }
    }

    /**
     * @param  array{document?:array<string,mixed>,items?:list<array<string,mixed>>,rows?:list<array<string,mixed>>,log?:list<array<string,mixed>>}  $structured
     * @return list<array<string, mixed>>
     */
    private function rowsFromStructured(array $structured): array
    {
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
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function batchMetadata(AiImportBatch $batch, array $extra = []): array
    {
        return array_replace(array_filter([
            'annee' => $batch->detected_year,
            'annee_debut_pas' => $batch->detected_year,
            'annee_fin_pas' => $batch->detected_year,
            'direction' => $batch->detected_direction,
            'service_unite' => $batch->detected_service,
            'source_document' => $batch->original_filename,
        ], static fn (mixed $value): bool => $value !== null && trim((string) $value) !== ''), array_filter(
            $extra,
            static fn (mixed $value): bool => ! (is_string($value) && trim($value) === '') && $value !== null
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
