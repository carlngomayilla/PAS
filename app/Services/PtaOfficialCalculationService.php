<?php

namespace App\Services;

use App\Models\Action;
use App\Models\SousAction;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Support\Collection;

class PtaOfficialCalculationService
{
    public const STATUS_TO_CONFIGURE = 'a_parametrer';

    public const STATUS_PENDING = 'en_attente';

    public const STATUS_IN_PROGRESS = 'en_cours';

    public const STATUS_DONE = 'realise';

    public const STATUS_LATE = 'en_retard';

    /**
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    public function actionResult(Action $action): array
    {
        $subActionResults = $this->subActionResults($action);
        $configuredSubActions = $subActionResults->where('is_configured', true);
        $target = max(0.0, (float) ($action->quantite_cible ?? 0));
        $realized = max(0.0, (float) ($action->quantite_realisee ?? 0));

        $actionTargets = collect();
        if ($target > 0.0) {
            $actionTargets->push($this->resultFromRawValues($target, $realized, 'action'));
        }

        if ($this->actionTracksDeliverableTarget($action)) {
            $actionTargets->push($this->resultFromDeliverable(
                $this->actionDeliverableCompleted($action),
                'action'
            ));
        }

        if ($actionTargets->isEmpty() && $configuredSubActions->isNotEmpty()) {
            return $this->targetWeighted($configuredSubActions, 'sous_actions');
        }

        $configuredTargets = $actionTargets
            ->concat($configuredSubActions)
            ->where('is_configured', true)
            ->values();

        if ($configuredTargets->count() === 1) {
            return $configuredTargets->first();
        }

        if ($configuredTargets->isNotEmpty()) {
            return $this->targetWeighted($configuredTargets, 'mixed_targets');
        }

        return $this->resultFromRawValues($target, $realized, 'action');
    }

    /**
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    public function subActionResult(SousAction $sousAction): array
    {
        $target = max(0.0, (float) ($sousAction->cible_prevue ?? 0));
        $realized = max(0.0, (float) ($sousAction->quantite_realisee ?? 0));
        $targets = collect();

        if ($target > 0.0) {
            $targets->push($this->resultFromRawValues($target, $realized, 'sous_action'));
        }

        if ($this->subActionTracksDeliverableTarget($sousAction)) {
            $targets->push($this->resultFromDeliverable(
                $this->subActionDeliverableCompleted($sousAction),
                'sous_action'
            ));
        }

        if ($targets->count() === 1) {
            return $targets->first();
        }

        if ($targets->isNotEmpty()) {
            return $this->targetWeighted($targets, 'sous_action');
        }

        return $this->resultFromRawValues($target, $realized, 'sous_action');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    public function targetWeighted(Collection $items, string $source = 'target_rollup'): array
    {
        $configured = $items->filter(
            fn (array $item): bool => (bool) ($item['is_configured'] ?? false)
                && (float) ($item['target'] ?? 0) > 0.0
        );

        $target = (float) $configured->sum(fn (array $item): float => max(0.0, (float) ($item['target'] ?? 0)));
        $realized = (float) $configured->sum(fn (array $item): float => max(0.0, (float) ($item['realized'] ?? 0)));

        return $this->resultFromRawValues($target, $realized, $source);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{weight:float,weighted_points:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    public function institutionWeighted(Collection $items, string $source = 'institution_rollup'): array
    {
        $configured = $items->filter(
            fn (array $item): bool => (bool) ($item['is_configured'] ?? false)
                && (float) ($item['weight'] ?? 0) > 0.0
                && ($item['rate'] ?? null) !== null
        );

        $weight = (float) $configured->sum(fn (array $item): float => max(0.0, (float) ($item['weight'] ?? 0)));

        if ($weight <= 0.0) {
            return [
                'weight' => 0.0,
                'weighted_points' => 0.0,
                'rate' => null,
                'display_rate' => 0.0,
                'is_configured' => false,
                'excluded' => true,
                'status' => self::STATUS_TO_CONFIGURE,
                'status_label' => $this->statusLabel(self::STATUS_TO_CONFIGURE),
                'source' => $source,
            ];
        }

        $weightedPoints = (float) $configured->sum(
            fn (array $item): float => (float) ($item['rate'] ?? 0) * max(0.0, (float) ($item['weight'] ?? 0))
        );
        $rate = round($weightedPoints / $weight, 2);

        return [
            'weight' => round($weight, 4),
            'weighted_points' => round($weightedPoints, 4),
            'rate' => $rate,
            'display_rate' => $this->displayRate($rate),
            'is_configured' => true,
            'excluded' => false,
            'status' => $this->statusForRate($rate),
            'status_label' => $this->statusLabel($this->statusForRate($rate)),
            'source' => $source,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    public function targetWeightedRows(Collection $rows, string $source = 'row_rollup'): array
    {
        return $this->targetWeighted(
            $rows->map(fn (array $row): array => [
                'target' => (float) ($row['calcul_cible'] ?? $row['cible_cumulee'] ?? $row['target'] ?? 0),
                'realized' => (float) ($row['calcul_realise'] ?? $row['realisation_cumulee'] ?? $row['realized'] ?? 0),
                'is_configured' => (bool) ($row['calcul_configured'] ?? $row['is_configured'] ?? false),
            ])->values(),
            $source
        );
    }

    public function statusForRate(?float $rate, bool $isLate = false): string
    {
        if ($rate === null) {
            return self::STATUS_TO_CONFIGURE;
        }

        if ($rate >= 100.0) {
            return self::STATUS_DONE;
        }

        if ($isLate) {
            return self::STATUS_LATE;
        }

        if ($rate <= 0.0) {
            return self::STATUS_PENDING;
        }

        return self::STATUS_IN_PROGRESS;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_TO_CONFIGURE => 'A parametrer',
            self::STATUS_PENDING => 'En attente',
            self::STATUS_IN_PROGRESS => 'En cours',
            self::STATUS_DONE => 'Realise',
            self::STATUS_LATE => 'En retard',
            default => 'En cours',
        };
    }

    public function displayRate(?float $rate): float
    {
        return round(min(100.0, max(0.0, (float) ($rate ?? 0.0))), 2);
    }

    /**
     * @return Collection<int, array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}>
     */
    private function subActionResults(Action $action): Collection
    {
        if (! $action->relationLoaded('sousActions')) {
            return collect();
        }

        return $action->sousActions
            ->map(fn (SousAction $sousAction): array => $this->subActionResult($sousAction))
            ->values();
    }

    /**
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    private function resultFromRawValues(float $target, float $realized, string $source): array
    {
        $target = round(max(0.0, $target), 4);
        $realized = round(max(0.0, $realized), 4);

        if ($target <= 0.0) {
            return [
                'target' => 0.0,
                'realized' => $realized,
                'rate' => null,
                'display_rate' => 0.0,
                'is_configured' => false,
                'excluded' => true,
                'status' => self::STATUS_TO_CONFIGURE,
                'status_label' => $this->statusLabel(self::STATUS_TO_CONFIGURE),
                'source' => $source,
            ];
        }

        $rate = round(($realized / $target) * 100, 2);
        $status = $this->statusForRate($rate);

        return [
            'target' => $target,
            'realized' => $realized,
            'rate' => $rate,
            'display_rate' => $this->displayRate($rate),
            'is_configured' => true,
            'excluded' => false,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'source' => $source,
        ];
    }

    /**
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    private function resultFromDeliverable(bool $completed, string $source): array
    {
        return $this->resultFromRawValues(1.0, $completed ? 1.0 : 0.0, $source);
    }

    private function actionTracksDeliverableTarget(Action $action): bool
    {
        $typeAction = trim((string) ($action->type_action ?? ''));
        $modeEvaluation = trim((string) ($action->mode_evaluation ?? ''));
        $typeCible = trim((string) ($action->type_cible ?? ''));

        $hasExplicitDeliverable = $this->filledText($action->livrable_attendu ?? null)
            || $this->filledText($action->intitule_cible ?? null);

        $isDeliverableMode = in_array($typeAction, [
            Action::TYPE_NON_QUANTITATIVE,
            Action::TYPE_MIXTE,
            Action::TYPE_COMPOSEE,
        ], true)
            || in_array($modeEvaluation, [
                Action::MODE_SANS_QUANTITE,
                Action::MODE_MIXTE,
                Action::MODE_SOUS_ACTIONS,
            ], true)
            || in_array($typeCible, ['qualitative', 'qualitatif', 'mixte'], true);

        return $hasExplicitDeliverable
            || ($isDeliverableMode && (
                $this->filledText($action->resultat_attendu ?? null)
                || $this->filledText($action->criteres_validation ?? null)
            ));
    }

    private function subActionTracksDeliverableTarget(SousAction $sousAction): bool
    {
        $type = trim((string) ($sousAction->sub_action_type ?? ''));
        $isDeliverableType = in_array($type, [
            SousAction::TYPE_NON_QUANTITATIVE,
            SousAction::TYPE_MIXTE,
        ], true) || max(0.0, (float) ($sousAction->cible_prevue ?? 0)) <= 0.0;

        return $isDeliverableType && (
            $this->filledText($sousAction->resultat_attendu ?? null)
            || $this->filledText($sousAction->description ?? null)
        );
    }

    private function actionDeliverableCompleted(Action $action): bool
    {
        if (in_array((string) ($action->statut_validation ?? ''), [
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ], true)) {
            return true;
        }

        if (in_array((string) ($action->statut_dynamique ?? $action->statut ?? ''), [
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
            ActionTrackingService::STATUS_CLOTUREE,
            'effectuee',
            'realise',
            'realisee',
            'termine',
            'terminee',
            'cloturee',
        ], true)) {
            return true;
        }

        if ($this->filledText($action->rapport_final ?? null) || $this->filledText($action->resultat_cloture ?? null)) {
            return true;
        }

        if ($action->relationLoaded('justificatifs')) {
            return $action->justificatifs->isNotEmpty();
        }

        return $action->exists && $action->justificatifs()->exists();
    }

    private function subActionDeliverableCompleted(SousAction $sousAction): bool
    {
        if ((bool) ($sousAction->est_effectuee ?? false)) {
            return true;
        }

        if ((string) ($sousAction->validation_status ?? '') === SousAction::VALIDATION_VALIDEE) {
            return true;
        }

        if ($sousAction->completed_at !== null || $sousAction->date_realisation !== null) {
            return true;
        }

        if (in_array((string) ($sousAction->statut ?? ''), [
            'effectuee',
            'realise',
            'realisee',
            'termine',
            'terminee',
        ], true)) {
            return true;
        }

        if ($this->filledText($sousAction->resultat_obtenu ?? null)) {
            return true;
        }

        if ($sousAction->relationLoaded('justificatifs')) {
            return $sousAction->justificatifs->isNotEmpty();
        }

        return $sousAction->exists && $sousAction->justificatifs()->exists();
    }

    private function filledText(mixed $value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }
}
