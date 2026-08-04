<?php

namespace App\Services;

use App\Enums\StatutEcheance;
use App\Enums\StatutRealisation;
use App\Enums\StatutRetard;
use App\Enums\TypeIndicateur;
use App\Models\Action;
use App\Models\SousAction;
use App\Services\Actions\ActionTrackingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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
        $typeIndicateur = $action->resolvedTypeIndicateur();
        $target = $this->actionQuantityTarget($action);
        $realized = max(0.0, (float) ($action->quantite_realisee ?? 0));
        $completionThreshold = $this->completionThresholdFor($action);

        $actionTargets = collect();
        if ($typeIndicateur->tracksQuantity() || $target > 0.0) {
            $actionTargets->push($this->resultFromRawValues($target, $realized, 'action', $completionThreshold));
        }

        if ($typeIndicateur->tracksDeliverable() && $this->actionTracksDeliverableTarget($action)) {
            $actionTargets->push($this->resultFromDeliverable(
                $this->actionDeliverableCompleted($action),
                'action',
                $completionThreshold
            ));
        }

        if ($actionTargets->isEmpty() && $configuredSubActions->isNotEmpty()) {
            return $this->targetWeighted($configuredSubActions, 'sous_actions', $completionThreshold);
        }

        $configuredTargets = $actionTargets
            ->concat($configuredSubActions)
            ->where('is_configured', true)
            ->values();

        if ($configuredTargets->count() === 1) {
            return $configuredTargets->first();
        }

        if ($typeIndicateur === TypeIndicateur::Mixte && $configuredTargets->isNotEmpty()) {
            return $this->percentageAverage($configuredTargets, 'mixed_targets', $completionThreshold);
        }

        if ($configuredTargets->isNotEmpty()) {
            return $this->targetWeighted($configuredTargets, 'mixed_targets', $completionThreshold);
        }

        return $this->resultFromRawValues($target, $realized, 'action', $completionThreshold);
    }

    /**
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    public function subActionResult(SousAction $sousAction): array
    {
        $typeIndicateur = $sousAction->resolvedTypeIndicateur();
        $target = $this->subActionQuantityTarget($sousAction);
        $realized = max(0.0, (float) ($sousAction->quantite_realisee ?? 0));
        $completionThreshold = $this->completionThresholdFor($sousAction);
        $targets = collect();

        if ($typeIndicateur->tracksQuantity() || $target > 0.0) {
            $targets->push($this->resultFromRawValues($target, $realized, 'sous_action', $completionThreshold));
        }

        if ($typeIndicateur->tracksDeliverable() && $this->subActionTracksDeliverableTarget($sousAction)) {
            $targets->push($this->resultFromDeliverable(
                $this->subActionDeliverableCompleted($sousAction),
                'sous_action',
                $completionThreshold
            ));
        }

        if ($targets->count() === 1) {
            return $targets->first();
        }

        if ($typeIndicateur === TypeIndicateur::Mixte && $targets->isNotEmpty()) {
            return $this->percentageAverage($targets, 'sous_action_mixed_targets', $completionThreshold);
        }

        if ($targets->isNotEmpty()) {
            return $this->targetWeighted($targets, 'sous_action', $completionThreshold);
        }

        return $this->resultFromRawValues($target, $realized, 'sous_action', $completionThreshold);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    public function targetWeighted(Collection $items, string $source = 'target_rollup', float $completionThreshold = 100.0): array
    {
        $configured = $items->filter(
            fn (array $item): bool => (bool) ($item['is_configured'] ?? false)
                && (float) ($item['target'] ?? 0) > 0.0
        );

        $target = (float) $configured->sum(fn (array $item): float => max(0.0, (float) ($item['target'] ?? 0)));
        $realized = (float) $configured->sum(fn (array $item): float => max(0.0, (float) ($item['realized'] ?? 0)));

        return $this->resultFromRawValues($target, $realized, $source, $completionThreshold);
    }

    /**
     * Calcule une action mixte selon la formule metier :
     * taux_final = (taux_quantitatif + taux_livrable) / 2.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,statut_realisation:string,statut_realisation_label:string,source:string}
     */
    public function percentageAverage(Collection $items, string $source = 'percentage_average', float $completionThreshold = 100.0): array
    {
        $configured = $items->filter(
            fn (array $item): bool => (bool) ($item['is_configured'] ?? false)
                && ($item['rate'] ?? null) !== null
        );

        if ($configured->isEmpty()) {
            return $this->resultFromRawValues(0.0, 0.0, $source);
        }

        $rate = round((float) $configured->avg(fn (array $item): float => (float) ($item['display_rate'] ?? $item['rate'] ?? 0.0)), 2);
        $statutRealisation = StatutRealisation::fromRate($rate, false, $completionThreshold);
        $status = $statutRealisation->legacyStatus();

        return [
            'target' => 100.0,
            'realized' => $rate,
            'rate' => $rate,
            'display_rate' => $this->displayRate($rate),
            'is_configured' => true,
            'excluded' => false,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'statut_realisation' => $statutRealisation->value,
            'statut_realisation_label' => $statutRealisation->label(),
            'source' => $source,
        ];
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
     * Remontee hierarchique officielle : objectif operationnel, objectif
     * strategique, axe strategique et PAS.
     *
     * Regle metier retenue (2026-08-04) : chaque enfant a le meme poids et sa
     * cible de performance vaut 100 %. Le taux d'un niveau est donc la moyenne
     * des taux de ses enfants :
     *
     *     taux = ( Σ taux des enfants ) ÷ ( 100 × nombre d'enfants ) × 100
     *          = moyenne des taux des enfants
     *
     * Les enfants non parametres (cible absente) sont exclus du calcul.
     * `target` et `realized` restent les cumuls en unites : ils ne servent plus
     * au calcul du taux mais restent affiches a titre informatif.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    public function targetWeightedRows(Collection $rows, string $source = 'row_rollup'): array
    {
        $items = $rows->map(fn (array $row): array => [
            'target' => (float) ($row['calcul_cible'] ?? $row['cible_cumulee'] ?? $row['target'] ?? 0),
            'realized' => (float) ($row['calcul_realise'] ?? $row['realisation_cumulee'] ?? $row['realized'] ?? 0),
            'is_configured' => (bool) ($row['calcul_configured'] ?? $row['is_configured'] ?? false),
            'rate' => $this->rowRate($row),
        ])->values();

        $configured = $items->filter(
            fn (array $item): bool => $item['is_configured'] && $item['rate'] !== null
        );

        $cumulatedTarget = round((float) $items->sum(fn (array $item): float => max(0.0, $item['target'])), 4);
        $cumulatedRealized = round((float) $items->sum(fn (array $item): float => max(0.0, $item['realized'])), 4);

        if ($configured->isEmpty()) {
            $statutRealisation = StatutRealisation::AParametrer;

            return [
                'target' => $cumulatedTarget,
                'realized' => $cumulatedRealized,
                'rate' => null,
                'display_rate' => 0.0,
                'is_configured' => false,
                'excluded' => true,
                'status' => $statutRealisation->legacyStatus(),
                'status_label' => $this->statusLabel($statutRealisation->legacyStatus()),
                'statut_realisation' => $statutRealisation->value,
                'statut_realisation_label' => $statutRealisation->label(),
                'source' => $source,
            ];
        }

        $rate = round((float) $configured->avg(fn (array $item): float => (float) $item['rate']), 2);
        $statutRealisation = StatutRealisation::fromRate($rate, false);

        return [
            'target' => $cumulatedTarget,
            'realized' => $cumulatedRealized,
            'rate' => $rate,
            'display_rate' => $this->displayRate($rate),
            'is_configured' => true,
            'excluded' => false,
            'status' => $statutRealisation->legacyStatus(),
            'status_label' => $this->statusLabel($statutRealisation->legacyStatus()),
            'statut_realisation' => $statutRealisation->value,
            'statut_realisation_label' => $statutRealisation->label(),
            'source' => $source,
        ];
    }

    /**
     * Taux deja calcule d'une ligne (action ou niveau agrege), quel que soit le
     * nom de la cle utilisee par l'appelant.
     *
     * @param  array<string, mixed>  $row
     */
    private function rowRate(array $row): ?float
    {
        foreach (['taux_realisation', 'performance', 'rate'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                return round(max(0.0, (float) $row[$key]), 2);
            }
        }

        $target = (float) ($row['calcul_cible'] ?? $row['cible_cumulee'] ?? $row['target'] ?? 0);
        $realized = (float) ($row['calcul_realise'] ?? $row['realisation_cumulee'] ?? $row['realized'] ?? 0);

        return $target > 0.0 ? round(($realized / $target) * 100, 2) : null;
    }

    /**
     * Taux d'execution du PAS : part des actions echues qui sont realisees.
     *
     *     taux = ( actions echues realisees ) ÷ ( actions echues ) × 100
     *
     * @param  Collection<int, array<string, mixed>>  $rows  lignes d'action
     * @return array{rate:?float,display_rate:float,due:int,done:int,is_configured:bool}
     */
    public function executionRate(Collection $rows): array
    {
        $due = $rows->filter(fn (array $row): bool => (bool) ($row['est_echue'] ?? false));
        $done = $due->filter(fn (array $row): bool => (bool) ($row['est_realisee'] ?? false));

        return $this->countRatio($done->count(), $due->count());
    }

    /**
     * Taux d'avancement global du PAS : part des actions programmees realisees,
     * independamment de leur echeance.
     *
     *     taux = ( actions realisees ) ÷ ( actions programmees ) × 100
     *
     * @param  Collection<int, array<string, mixed>>  $rows  lignes d'action
     * @return array{rate:?float,display_rate:float,due:int,done:int,is_configured:bool}
     */
    public function globalCompletionRate(Collection $rows): array
    {
        $done = $rows->filter(fn (array $row): bool => (bool) ($row['est_realisee'] ?? false));

        return $this->countRatio($done->count(), $rows->count());
    }

    /**
     * @return array{rate:?float,display_rate:float,due:int,done:int,is_configured:bool}
     */
    private function countRatio(int $done, int $total): array
    {
        if ($total <= 0) {
            return [
                'rate' => null,
                'display_rate' => 0.0,
                'due' => 0,
                'done' => 0,
                'is_configured' => false,
            ];
        }

        $rate = round(($done / $total) * 100, 2);

        return [
            'rate' => $rate,
            'display_rate' => $this->displayRate($rate),
            'due' => $total,
            'done' => $done,
            'is_configured' => true,
        ];
    }

    public function statusForRate(?float $rate, bool $isLate = false, float $completionThreshold = 100.0): string
    {
        if ($rate === null) {
            return StatutRealisation::AParametrer->legacyStatus();
        }

        return $isLate && $rate < $completionThreshold
            ? self::STATUS_LATE
            : StatutRealisation::fromRate($rate, false, $completionThreshold)->legacyStatus();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_TO_CONFIGURE => 'À paramétrer',
            self::STATUS_PENDING => 'Non demarree',
            self::STATUS_IN_PROGRESS => 'En cours',
            self::STATUS_DONE => 'Realisee',
            self::STATUS_LATE => 'En retard',
            default => 'En cours',
        };
    }

    public function displayRate(?float $rate): float
    {
        return round(min(100.0, max(0.0, (float) ($rate ?? 0.0))), 2);
    }

    public function deadlineStatus(Action|SousAction $trackable, CarbonInterface|string|null $reportDate = null): StatutEcheance
    {
        $deadline = $this->deadlineFor($trackable);

        if ($deadline === null) {
            return StatutEcheance::NonEchue;
        }

        $reportDate = $reportDate instanceof CarbonInterface
            ? Carbon::instance($reportDate)->startOfDay()
            : Carbon::parse($reportDate ?? now())->startOfDay();

        return Carbon::parse($deadline)->startOfDay()->lte($reportDate)
            ? StatutEcheance::Echue
            : StatutEcheance::NonEchue;
    }

    public function delayStatus(Action|SousAction $trackable, ?float $rate = null, CarbonInterface|string|null $reportDate = null): StatutRetard
    {
        $rate ??= $trackable instanceof Action
            ? $this->actionResult($trackable)['display_rate']
            : $this->subActionResult($trackable)['display_rate'];

        $isLate = $this->deadlineStatus($trackable, $reportDate) === StatutEcheance::Echue
            && $this->displayRate($rate) < $this->completionThresholdFor($trackable);

        return $isLate ? StatutRetard::EnRetard : StatutRetard::DansLesDelais;
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

    private function actionQuantityTarget(Action $action): float
    {
        return max(0.0, (float) ($action->quantite_a_realiser ?? $action->quantite_cible ?? 0));
    }

    private function subActionQuantityTarget(SousAction $sousAction): float
    {
        return max(0.0, (float) ($sousAction->quantite_a_realiser ?? $sousAction->cible_prevue ?? 0));
    }

    private function deadlineFor(Action|SousAction $trackable): CarbonInterface|string|null
    {
        if ($trackable instanceof Action) {
            return $trackable->date_fin ?? $trackable->date_echeance ?? $trackable->echeance_cible;
        }

        return $trackable->date_fin;
    }

    /**
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    private function resultFromRawValues(float $target, float $realized, string $source, float $completionThreshold = 100.0): array
    {
        $target = round(max(0.0, $target), 4);
        $realized = round(max(0.0, $realized), 4);

        if ($target <= 0.0) {
            $statutRealisation = StatutRealisation::AParametrer;

            return [
                'target' => 0.0,
                'realized' => $realized,
                'rate' => null,
                'raw_rate' => null,
                'display_rate' => 0.0,
                'is_configured' => false,
                'excluded' => true,
                'status' => $statutRealisation->legacyStatus(),
                'status_label' => $this->statusLabel($statutRealisation->legacyStatus()),
                'statut_realisation' => $statutRealisation->value,
                'statut_realisation_label' => $statutRealisation->label(),
                'source' => $source,
            ];
        }

        $rawRate = round(($realized / $target) * 100, 2);
        $rate = $this->displayRate($rawRate);
        $statutRealisation = StatutRealisation::fromRate($rate, false, $completionThreshold);
        $status = $statutRealisation->legacyStatus();

        return [
            'target' => $target,
            'realized' => $realized,
            'rate' => $rate,
            'raw_rate' => $rawRate,
            'display_rate' => $this->displayRate($rate),
            'is_configured' => true,
            'excluded' => false,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'statut_realisation' => $statutRealisation->value,
            'statut_realisation_label' => $statutRealisation->label(),
            'source' => $source,
        ];
    }

    /**
     * @return array{target:float,realized:float,rate:?float,display_rate:float,is_configured:bool,excluded:bool,status:string,status_label:string,source:string}
     */
    private function resultFromDeliverable(bool $completed, string $source, float $completionThreshold = 100.0): array
    {
        return $this->resultFromRawValues(1.0, $completed ? 1.0 : 0.0, $source, $completionThreshold);
    }

    private function completionThresholdFor(Action|SousAction $trackable): float
    {
        $value = $trackable instanceof Action
            ? $trackable->seuil_minimum ?? 80
            : $trackable->seuil_minimum ?? 80;

        return min(100.0, max(0.0, (float) $value));
    }

    private function actionTracksDeliverableTarget(Action $action): bool
    {
        $typeAction = trim((string) ($action->type_action ?? ''));
        $typeIndicateur = $action->resolvedTypeIndicateur();
        $modeEvaluation = trim((string) ($action->mode_evaluation ?? ''));
        $typeCible = trim((string) ($action->type_cible ?? ''));

        $hasExplicitDeliverable = $this->filledText($action->cible ?? null)
            || $this->filledText($action->livrable_attendu ?? null)
            || $this->filledText($action->intitule_cible ?? null);

        $isDeliverableMode = $typeIndicateur->tracksDeliverable()
            || in_array($typeAction, [
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
        $typeIndicateur = $sousAction->resolvedTypeIndicateur();
        $isDeliverableType = $typeIndicateur->tracksDeliverable() || in_array($type, [
            SousAction::TYPE_NON_QUANTITATIVE,
            SousAction::TYPE_MIXTE,
        ], true) || max(0.0, (float) ($sousAction->cible_prevue ?? 0)) <= 0.0;

        return $isDeliverableType && (
            $this->filledText($sousAction->cible ?? null)
            || $this->filledText($sousAction->livrable_attendu ?? null)
            || $this->filledText($sousAction->resultat_attendu ?? null)
            || $this->filledText($sousAction->description ?? null)
        );
    }

    private function actionDeliverableCompleted(Action $action): bool
    {
        if (in_array((string) ($action->statut_validation ?? ''), [
            ActionTrackingService::VALIDATION_VALIDEE_PLANIFICATION,
            ActionTrackingService::VALIDATION_VALIDEE_PLANIFICATION,
            ActionTrackingService::VALIDATION_VALIDEE_CONTROLE,
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
