<?php

namespace App\Services\Ai;

use App\Models\Action;
use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Models\SousAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PtaFinalImportService
{
    public function __construct(
        private readonly PtaImportValidationService $validation,
        private readonly PtaReferenceResolver $references,
        private readonly PtaActionParameterizationService $parameterizer
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
                $parameterization = $this->parameterizer->parameterize($payload);
                $typeCode = $this->typeCode($payload['type_action'] ?? $parameterization['type_action']);
                $modelType = $this->parameterizer->modelType($typeCode);
                $mode = $this->parameterizer->modelMode($typeCode);
                $pta = $this->references->findOrCreatePta($payload, $actor);
                $responsable = $this->references->findResponsible((string) ($payload['responsable'] ?? ''));
                $code = trim((string) ($payload['code_action'] ?? '')) ?: 'AI-PTA-'.$batch->id.'-'.$row->row_number;
                $dateFin = $this->validation->parseDate($payload['date_fin'] ?? null)
                    ?? $this->validation->parseDate($payload['echeance'] ?? null);
                $quantiteCible = $this->numberFrom($payload['quantite_cible'] ?? $parameterization['quantite_cible']);
                $budget = $payload['budget_previsionnel'] ?? null;
                $thresholdMode = $this->thresholdMode($payload['seuil_mode'] ?? $parameterization['seuil_mode']);
                $riskPotential = trim((string) ($payload['risques_potentiels'] ?? $payload['risque'] ?? ''));
                $resources = trim((string) ($payload['ressources_requises'] ?? ''));
                $subActionsText = trim((string) ($payload['sous_actions'] ?? $this->parameterizer->stringifySubActions($parameterization['sous_actions']) ?? ''));

                $action = Action::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'exercice_id' => $pta->exercice_id,
                        'pta_id' => $pta->id,
                        'pao_id' => $pta->pao_id,
                        'libelle' => trim((string) $payload['libelle_action']),
                        'description' => $payload['description_action'] ?: $payload['observations'] ?: null,
                        'statut_parametrage' => 'parametre',
                        'nombre_sous_actions_prevu' => (int) ($payload['nombre_sous_actions'] ?? $parameterization['nombre_sous_actions'] ?? 0),
                        'mode_evaluation' => $mode,
                        'type_action' => $modelType,
                        'requires_comment' => $this->boolFrom($payload['commentaire_obligatoire'] ?? $parameterization['commentaire_obligatoire'], false),
                        'allows_difficulty' => $this->boolFrom($payload['champ_difficulte'] ?? $parameterization['champ_difficulte'], true),
                        'type_cible' => $typeCode === 'Q' ? 'quantitative' : 'qualitative',
                        'intitule_cible' => $payload['indicateur'] ?: $payload['cible'] ?: null,
                        'unite_cible' => $typeCode === 'Q' ? ($payload['unite_cible'] ?? $payload['unite'] ?? $parameterization['unite_cible']) : null,
                        'quantite_cible' => $typeCode === 'Q' ? $quantiteCible : null,
                        'seuil_minimum' => $this->numberFrom($payload['cible_minimum_execution'] ?? $parameterization['cible_minimum_execution']) ?? 100,
                        'seuil_mode' => $thresholdMode,
                        'seuil_t1' => $thresholdMode === 'trimestriel' ? $this->numberFrom($payload['seuil_t1'] ?? $parameterization['seuil_t1']) : null,
                        'seuil_t2' => $thresholdMode === 'trimestriel' ? $this->numberFrom($payload['seuil_t2'] ?? $parameterization['seuil_t2']) : null,
                        'seuil_t3' => $thresholdMode === 'trimestriel' ? $this->numberFrom($payload['seuil_t3'] ?? $parameterization['seuil_t3']) : null,
                        'seuil_t4' => $thresholdMode === 'trimestriel' ? $this->numberFrom($payload['seuil_t4'] ?? $parameterization['seuil_t4']) : null,
                        'methode_calcul' => match ($mode) {
                            Action::MODE_QUANTITATIF => 'cumulative_quantity',
                            Action::MODE_SOUS_ACTIONS => 'sum_sous_actions',
                            default => 'binary_completion',
                        },
                        'justificatif_obligatoire' => ! $this->blank($payload['indicateur'] ?? null),
                        'livrable_attendu' => $payload['livrables_attendus'] ?? $payload['indicateur'] ?? null,
                        'priorite' => $payload['priorite'] ?: 'moyenne',
                        'date_debut' => $this->validation->parseDate($payload['date_debut'] ?? null)?->toDateString(),
                        'date_fin' => $dateFin?->toDateString(),
                        'date_echeance' => $this->validation->parseDate($payload['echeance'] ?? null)?->toDateString(),
                        'responsable_id' => $responsable?->id,
                        'contexte_action' => Action::CONTEXT_OPERATIONNEL,
                        'origine_action' => Action::ORIGIN_PTA,
                        'statut' => trim((string) ($payload['statut_initial'] ?? '')) ?: 'non_demarre',
                        'risque_lie' => $riskPotential !== '' ? 'oui' : null,
                        'risques' => $riskPotential !== '' ? $riskPotential : null,
                        'risque_potentiel' => $riskPotential !== '' ? $riskPotential : null,
                        'niveau_risque' => $this->riskLevel($payload['niveau_risque'] ?? $parameterization['niveau_risque']),
                        'ressources_materielles' => $resources !== '' ? $resources : null,
                        'ressources_details' => $resources !== '' ? $resources : null,
                        'financement_requis' => is_numeric($budget) && (float) $budget > 0,
                        'source_financement' => $payload['source_financement'] ?: null,
                        'montant_estime' => is_numeric($budget) ? (float) $budget : null,
                    ]
                );

                if ($typeCode === 'M') {
                    $this->syncSuggestedSubActions($action, $subActionsText, $responsable);
                }

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

    private function typeCode(mixed $value): string
    {
        $key = strtolower(trim(str_replace(['-', '_'], ' ', (string) $value)));

        return match ($key) {
            'q', 'quantitative' => 'Q',
            'm', 'mixte', 'composee', 'compose', 'composite', 'sous actions' => 'M',
            default => 'NQ',
        };
    }

    private function thresholdMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, ['unique', 'trimestriel'], true) ? $mode : 'unique';
    }

    private function riskLevel(mixed $value): ?string
    {
        $risk = strtolower(str_replace(['é', 'è'], ['e', 'e'], trim((string) $value)));

        return in_array($risk, ['faible', 'modere', 'eleve', 'critique'], true) ? $risk : null;
    }

    private function numberFrom(mixed $value): ?float
    {
        if ($this->blank($value)) {
            return null;
        }

        $number = str_replace(['%', ' ', "\u{00A0}"], '', (string) $value);
        $number = str_replace(',', '.', $number);

        return is_numeric($number) ? (float) $number : null;
    }

    private function boolFrom(mixed $value, bool $default): bool
    {
        if ($this->blank($value)) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function syncSuggestedSubActions(Action $action, string $subActionsText, ?User $responsable): void
    {
        $items = $this->parseSubActions($subActionsText);
        if ($items === []) {
            return;
        }

        $agentId = (int) ($responsable?->id ?? $action->responsable_id ?? 0);
        if ($agentId <= 0) {
            return;
        }

        SousAction::query()->where('action_id', (int) $action->id)->delete();

        $weight = round(100 / max(1, count($items)), 2);
        foreach ($items as $label) {
            $action->sousActions()->create([
                'agent_id' => $agentId,
                'libelle' => $label,
                'sub_action_type' => SousAction::TYPE_NON_QUANTITATIVE,
                'weight' => $weight,
                'requires_proof' => true,
                'requires_comment' => false,
                'allows_difficulty' => true,
                'date_debut' => optional($action->date_debut)->format('Y-m-d') ?? now()->toDateString(),
                'date_fin' => optional($action->date_fin)->format('Y-m-d') ?? optional($action->date_debut)->format('Y-m-d') ?? now()->toDateString(),
                'statut' => 'non_demarre',
                'est_effectuee' => false,
                'taux_execution' => 0,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function parseSubActions(string $value): array
    {
        $items = [];
        foreach (explode(';', $value) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $label = trim(explode('|', $chunk)[0] ?? '');
            $label = preg_replace('/^\d+[\).]\s*/', '', $label) ?: $label;
            if ($label !== '') {
                $items[] = $label;
            }
        }

        return array_values(array_unique($items));
    }

    private function blank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }
}
