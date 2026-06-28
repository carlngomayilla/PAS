<?php

namespace App\Services\Ai;

use App\Models\Action;
use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PtaFinalImportService
{
    public function __construct(
        private readonly PtaImportValidationService $validation,
        private readonly PtaReferenceResolver $references
    ) {}

    /**
     * @return array{imported:int,ignored:int}
     */
    public function import(AiImportBatch $batch, ?User $actor = null): array
    {
        $stats = $this->validation->validateBatch($batch);
        if ($stats['invalid'] > 0) {
            throw new RuntimeException('Import final bloque : des lignes invalides existent encore.');
        }

        return DB::transaction(function () use ($batch, $actor): array {
            $imported = 0;
            $ignored = 0;

            foreach ($batch->rows()->get() as $row) {
                if ($row->status === AiImportRow::STATUS_IGNORED) {
                    $ignored++;

                    continue;
                }

                if (! in_array($row->status, [AiImportRow::STATUS_VALID, AiImportRow::STATUS_CORRECTED], true)) {
                    continue;
                }

                $payload = $row->normalized_payload ?? [];
                $pta = $this->references->findOrCreatePta($payload, $actor);
                $responsable = $this->references->findResponsible((string) ($payload['responsable'] ?? ''));
                $code = trim((string) ($payload['code_action'] ?? '')) ?: 'AI-PTA-'.$batch->id.'-'.$row->row_number;
                $dateFin = $this->validation->parseDate($payload['date_fin'] ?? null)
                    ?? $this->validation->parseDate($payload['echeance'] ?? null);
                $cible = $payload['cible'] ?? null;
                $quantiteCible = is_numeric($cible) ? (float) $cible : null;
                $budget = $payload['budget_previsionnel'] ?? null;

                $action = Action::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'exercice_id' => $pta->exercice_id,
                        'pta_id' => $pta->id,
                        'pao_id' => $pta->pao_id,
                        'libelle' => trim((string) $payload['libelle_action']),
                        'description' => $payload['description_action'] ?: $payload['observations'] ?: null,
                        'type_action' => $quantiteCible !== null ? Action::TYPE_QUANTITATIVE : Action::TYPE_NON_QUANTITATIVE,
                        'type_cible' => $quantiteCible !== null ? 'quantitative' : 'qualitative',
                        'intitule_cible' => $payload['indicateur'] ?: $payload['cible'] ?: null,
                        'unite_cible' => $payload['unite'] ?: null,
                        'quantite_cible' => $quantiteCible,
                        'priorite' => $payload['priorite'] ?: 'moyenne',
                        'date_debut' => $this->validation->parseDate($payload['date_debut'] ?? null)?->toDateString(),
                        'date_fin' => $dateFin?->toDateString(),
                        'date_echeance' => $this->validation->parseDate($payload['echeance'] ?? null)?->toDateString(),
                        'responsable_id' => $responsable?->id,
                        'contexte_action' => Action::CONTEXT_OPERATIONNEL,
                        'origine_action' => Action::ORIGIN_PTA,
                        'statut' => trim((string) ($payload['statut_initial'] ?? '')) ?: 'non_demarre',
                        'financement_requis' => is_numeric($budget) && (float) $budget > 0,
                        'source_financement' => $payload['source_financement'] ?: null,
                        'montant_estime' => is_numeric($budget) ? (float) $budget : null,
                    ]
                );

                $payload['imported_action_id'] = $action->id;
                $row->forceFill([
                    'normalized_payload' => $payload,
                    'status' => AiImportRow::STATUS_IMPORTED,
                ])->save();
                $imported++;
            }

            $batch->forceFill(['status' => AiImportBatch::STATUS_IMPORTED])->save();

            return ['imported' => $imported, 'ignored' => $ignored];
        });
    }
}
