<?php

namespace App\Http\Controllers\Concerns;

use App\Support\UiLabel;

trait FormatsWorkflowMessages
{
    protected function lockedRelatedStateMessage(string $module, string $relation, string $operation): string
    {
        return sprintf(
            'Le %s %s est %s. %s impossible.',
            $module,
            $relation,
            $this->workflowStatusText('verrouille'),
            $operation
        );
    }

    protected function lockedStateMessage(string $module, string $action): string
    {
        return sprintf(
            'Le %s est %s et ne peut %s.',
            $module,
            $this->workflowStatusText('verrouille'),
            $action
        );
    }

    protected function requiredStateMessage(string $module, string $requiredStatus, string $action): string
    {
        return sprintf(
            'Seul un %s en %s peut etre %s.',
            $module,
            $this->workflowStatusText($requiredStatus),
            $action
        );
    }

    protected function transitionedStateMessage(string $module, string $status): string
    {
        return sprintf(
            '%s passe en %s.',
            $module,
            $this->workflowStatusText($status)
        );
    }

    protected function entityCreatedMessage(string $label, bool $feminine = false): string
    {
        return sprintf('%s %s avec succes.', $label, $feminine ? 'creee' : 'cree');
    }

    protected function entityUpdatedMessage(string $label): string
    {
        return sprintf('%s mis a jour avec succes.', $label);
    }

    protected function entityDeletedMessage(string $label, bool $feminine = false): string
    {
        return sprintf('%s %s avec succes.', $label, $feminine ? 'supprimee' : 'supprime');
    }

    protected function entityNotFoundMessage(string $label): string
    {
        return sprintf('%s introuvable.', $label);
    }

    protected function invalidTypeMessage(string $label): string
    {
        return sprintf('Type %s invalide.', $this->frenchPartitive(strtolower($label)));
    }

    protected function unsupportedTypeMessage(string $label): string
    {
        return sprintf('Type %s non pris en charge.', $this->frenchPartitive(strtolower($label)));
    }

    protected function outOfScopeMessage(string $label): string
    {
        return sprintf('%s hors perimetre.', $label);
    }

    protected function reopenedStateMessage(string $module): string
    {
        return sprintf(
            '%s remis en %s.',
            $module,
            $this->workflowStatusText('brouillon')
        );
    }

    protected function lockedCannotBeReopenedMessage(string $module): string
    {
        return sprintf(
            'Le %s %s ne peut pas etre remis en %s.',
            $module,
            $this->workflowStatusText('verrouille'),
            $this->workflowStatusText('brouillon')
        );
    }

    protected function reopenAllowedStatusesMessage(array $statuses): string
    {
        $labels = array_map(
            fn (string $status): string => $this->workflowStatusText($status),
            $statuses
        );

        return sprintf(
            'Retour %s possible uniquement depuis %s.',
            $this->workflowStatusText('brouillon'),
            implode(' ou ', $labels)
        );
    }

    private function workflowStatusText(string $status): string
    {
        return strtolower(UiLabel::workflowStatus($status));
    }

    private function frenchPartitive(string $label): string
    {
        $normalized = trim($label);
        $first = strtolower(substr($normalized, 0, 1));

        if (in_array($first, ['a', 'e', 'i', 'o', 'u', 'y', 'h'], true)) {
            return "d'{$normalized}";
        }

        return "de {$normalized}";
    }
}
