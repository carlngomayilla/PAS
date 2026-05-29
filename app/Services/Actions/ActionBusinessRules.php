<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\SousAction;

class ActionBusinessRules
{
    public function isActionQuantifiable(Action $action): bool
    {
        return $action->usesQuantitativeProgress()
            && (float) ($action->quantite_cible ?? 0) > 0.0;
    }

    public function isSubActionQuantifiable(SousAction $sousAction): bool
    {
        return (float) ($sousAction->cible_prevue ?? 0) > 0.0;
    }

    public function requiresSubActionQuantity(SousAction $sousAction): bool
    {
        return $this->isSubActionQuantifiable($sousAction);
    }

    public function requiresActionQuantity(Action $action): bool
    {
        return $this->isActionQuantifiable($action);
    }

    public function requiresSubActionProof(SousAction $sousAction): bool
    {
        return true;
    }

    public function requiresActionProof(Action $action): bool
    {
        return true;
    }

    /**
     * @return array{quantity: bool, comment: bool, difficulties: bool, proof: bool}
     */
    public function subActionSubmissionRequirements(SousAction $sousAction): array
    {
        $isQuantifiable = $this->isSubActionQuantifiable($sousAction);

        return [
            'quantity' => $isQuantifiable,
            'comment' => $isQuantifiable,
            'difficulties' => false,
            'proof' => $this->requiresSubActionProof($sousAction),
        ];
    }

    /**
     * @return array{quantity: bool, comment: bool, difficulties: bool, proof: bool}
     */
    public function actionSubmissionRequirements(Action $action): array
    {
        $isQuantifiable = $this->isActionQuantifiable($action);

        return [
            'quantity' => $isQuantifiable,
            'comment' => $isQuantifiable,
            'difficulties' => false,
            'proof' => $this->requiresActionProof($action),
        ];
    }

    public function declaredSubActionProgress(SousAction $sousAction): float
    {
        if (! $this->isSubActionQuantifiable($sousAction)) {
            return (string) ($sousAction->statut ?? '') === 'en_attente_validation_chef'
                || (bool) ($sousAction->est_effectuee ?? false)
                ? 100.0
                : 0.0;
        }

        $target = max(0.0, (float) ($sousAction->cible_prevue ?? 0));
        if ($target <= 0.0) {
            return 0.0;
        }

        return $this->boundRate((max(0.0, (float) ($sousAction->quantite_realisee ?? 0)) / $target) * 100);
    }

    public function officialSubActionProgress(SousAction $sousAction): float
    {
        $status = (string) ($sousAction->statut ?? '');
        if (! in_array($status, ['validee', 'validee_chef', 'cloturee'], true)) {
            return 0.0;
        }

        if (! $this->isSubActionQuantifiable($sousAction)) {
            return 100.0;
        }

        return $this->declaredSubActionProgress($sousAction);
    }

    private function boundRate(float $value): float
    {
        return round(min(100.0, max(0.0, $value)), 2);
    }
}
