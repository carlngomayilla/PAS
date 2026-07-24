<?php

namespace App\Services\AiImport;

use App\Models\AiImportRow;
use App\Models\AiImportSession;
use App\Models\User;
use App\Services\Ai\PtaFinalImportService;
use RuntimeException;

class ImportExecutionService
{
    public function __construct(
        private readonly DocumentExtractionService $documents,
        private readonly ImportValidationService $validation,
        private readonly PtaFinalImportService $ptaFinalImport
    ) {}

    /**
     * @return array{imported:int,ignored:int}
     */
    public function execute(AiImportSession $session, ?User $actor = null): array
    {
        $stats = $this->validation->validateSession($session);
        if ($stats['blocked'] > 0) {
            throw new RuntimeException('Import final bloque : des lignes restent a corriger ou a valider.');
        }

        $notReady = $session->rows()
            ->where('statut_import', '!=', AiImportRow::IMPORT_READY)
            ->exists();
        if ($notReady) {
            throw new RuntimeException('Import final bloque : seules les lignes pret_a_importer peuvent etre importees.');
        }

        $result = $this->ptaFinalImport->import($this->documents->legacyBatchFor($session), $actor);

        $session->forceFill([
            'status' => AiImportSession::STATUS_IMPORTED,
            'total_rows_validated' => $session->rows()->count(),
            'completed_at' => now(),
        ])->save();

        return $result;
    }
}
