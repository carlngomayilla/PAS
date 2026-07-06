<?php

namespace App\Services\Ai;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use Illuminate\Support\Str;

class PtaImportHierarchyCoherenceService
{
    /**
     * @return array{rows:int,fields:int,warnings:int}
     */
    public function repairAndCheck(AiImportBatch $batch): array
    {
        $state = $this->initialState();
        $stats = ['rows' => 0, 'fields' => 0, 'warnings' => 0];

        foreach ($batch->rows()->reorder('row_number')->get() as $row) {
            $payload = $row->normalized_payload ?? [];
            if ($payload === []) {
                continue;
            }

            $changes = [];
            $warnings = [];
            $payload = $this->normalizeHierarchy($payload, $state, $changes, $warnings);
            $this->rememberHierarchy($payload, $state, $warnings);

            if ($changes === [] && $warnings === []) {
                continue;
            }

            $row->forceFill([
                'normalized_payload' => $this->withHierarchyNote($payload, $changes, $warnings),
                'status' => $row->status === AiImportRow::STATUS_VALID ? AiImportRow::STATUS_CORRECTED : $row->status,
            ])->save();

            $stats['rows']++;
            $stats['fields'] += count(array_unique($changes));
            $stats['warnings'] += count($warnings);
        }

        return $stats;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $state
     * @param  list<string>  $changes
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function normalizeHierarchy(array $payload, array &$state, array &$changes, array &$warnings): array
    {
        $payload = $this->carryForwardMissingContext($payload, $state, $changes);

        $axisOrder = $this->assignOrder($payload, 'ordre_axe', 'libelle_axe', 'axes', 'global', $state, $changes);
        $strategicParent = $axisOrder === null ? 'axe-inconnu' : 'axe:'.$axisOrder;
        $strategicOrder = $this->assignOrder($payload, 'ordre_objectif_strategique', 'libelle_objectif_strategique', 'strategic', $strategicParent, $state, $changes);
        $operationalParent = $strategicParent.'|os:'.($strategicOrder ?? 'inconnu');
        $operationalOrder = $this->assignOrder($payload, 'ordre_objectif_operationnel', 'libelle_objectif_operationnel', 'operational', $operationalParent, $state, $changes);
        $actionParent = $operationalParent.'|oo:'.($operationalOrder ?? 'inconnu');

        if (! $this->blank($payload['libelle_action'] ?? null)) {
            $this->assignActionOrder($payload, $actionParent, $state, $changes, $warnings);
        }

        $this->detectMissingHierarchy($payload, $warnings);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $state
     * @param  list<string>  $changes
     * @return array<string,mixed>
     */
    private function carryForwardMissingContext(array $payload, array $state, array &$changes): array
    {
        foreach ([
            'ordre_axe',
            'libelle_axe',
            'ordre_objectif_strategique',
            'libelle_objectif_strategique',
            'ordre_objectif_operationnel',
            'libelle_objectif_operationnel',
        ] as $field) {
            if (! $this->blank($payload[$field] ?? null)) {
                continue;
            }

            $value = $state['current'][$field] ?? null;
            if (! $this->blank($value)) {
                $payload[$field] = $value;
                $changes[] = $field;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $state
     * @param  list<string>  $changes
     */
    private function assignOrder(
        array &$payload,
        string $orderField,
        string $labelField,
        string $scope,
        string $parent,
        array &$state,
        array &$changes
    ): ?int {
        $label = $this->cleanLabel($payload[$labelField] ?? null);
        $order = $this->integer($payload[$orderField] ?? null);

        if ($label === null && $order === null) {
            return null;
        }

        if ($label !== null) {
            $labelKey = $this->key($label);
            $knownOrder = $state['labelOrders'][$scope][$parent][$labelKey] ?? null;
            if ($order === null && is_int($knownOrder)) {
                $order = $knownOrder;
                $payload[$orderField] = $order;
                $changes[] = $orderField;
            }

            if ($order === null) {
                $order = $this->nextOrder($state, $scope, $parent);
                $payload[$orderField] = $order;
                $changes[] = $orderField;
            }

            $state['labelOrders'][$scope][$parent][$labelKey] = $order;
        }

        if ($order !== null && ((string) ($payload[$orderField] ?? '') !== (string) $order)) {
            $payload[$orderField] = $order;
            $changes[] = $orderField;
        }

        if ($order !== null) {
            $this->reserveOrder($state, $scope, $parent, $order);
        }

        return $order;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $state
     * @param  list<string>  $changes
     * @param  list<string>  $warnings
     */
    private function assignActionOrder(array &$payload, string $parent, array &$state, array &$changes, array &$warnings): void
    {
        $label = $this->cleanLabel($payload['libelle_action'] ?? null);
        if ($label === null) {
            return;
        }

        $labelKey = $this->key($label);
        $order = $this->integer($payload['ordre_action'] ?? null);
        $knownOrder = $state['labelOrders']['actions'][$parent][$labelKey] ?? null;

        if ($order === null && is_int($knownOrder)) {
            $order = $knownOrder;
            $payload['ordre_action'] = $order;
            $changes[] = 'ordre_action';
        }

        if ($order === null) {
            $order = $this->nextOrder($state, 'actions', $parent);
            $payload['ordre_action'] = $order;
            $changes[] = 'ordre_action';
        }

        $state['labelOrders']['actions'][$parent][$labelKey] = $order;
        $this->reserveOrder($state, 'actions', $parent, $order);

        $previous = $state['orderLabels']['actions'][$parent][$order] ?? null;
        if ($previous !== null && $previous !== $labelKey) {
            $warnings[] = "Ordre action {$order} deja attribue a une autre action dans le meme objectif operationnel.";
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $state
     * @param  list<string>  $warnings
     */
    private function rememberHierarchy(array $payload, array &$state, array &$warnings): void
    {
        foreach ([
            'ordre_axe',
            'libelle_axe',
            'ordre_objectif_strategique',
            'libelle_objectif_strategique',
            'ordre_objectif_operationnel',
            'libelle_objectif_operationnel',
        ] as $field) {
            if (! $this->blank($payload[$field] ?? null)) {
                $state['current'][$field] = $payload[$field];
            }
        }

        $this->rememberOrderLabel($payload, 'axes', 'global', 'ordre_axe', 'libelle_axe', $state, $warnings);

        $axisOrder = $this->integer($payload['ordre_axe'] ?? null);
        $strategicParent = $axisOrder === null ? 'axe-inconnu' : 'axe:'.$axisOrder;
        $this->rememberOrderLabel($payload, 'strategic', $strategicParent, 'ordre_objectif_strategique', 'libelle_objectif_strategique', $state, $warnings);

        $strategicOrder = $this->integer($payload['ordre_objectif_strategique'] ?? null);
        $operationalParent = $strategicParent.'|os:'.($strategicOrder ?? 'inconnu');
        $this->rememberOrderLabel($payload, 'operational', $operationalParent, 'ordre_objectif_operationnel', 'libelle_objectif_operationnel', $state, $warnings);

        $operationalOrder = $this->integer($payload['ordre_objectif_operationnel'] ?? null);
        $actionParent = $operationalParent.'|oo:'.($operationalOrder ?? 'inconnu');
        $this->rememberOrderLabel($payload, 'actions', $actionParent, 'ordre_action', 'libelle_action', $state, $warnings);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $state
     * @param  list<string>  $warnings
     */
    private function rememberOrderLabel(
        array $payload,
        string $scope,
        string $parent,
        string $orderField,
        string $labelField,
        array &$state,
        array &$warnings
    ): void {
        $order = $this->integer($payload[$orderField] ?? null);
        $label = $this->cleanLabel($payload[$labelField] ?? null);
        if ($order === null || $label === null) {
            return;
        }

        $labelKey = $this->key($label);
        $previous = $state['orderLabels'][$scope][$parent][$order] ?? null;
        if ($previous !== null && $previous !== $labelKey) {
            $warnings[] = $this->conflictMessage($scope, $order);
        }

        $state['orderLabels'][$scope][$parent][$order] = $labelKey;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $warnings
     */
    private function detectMissingHierarchy(array $payload, array &$warnings): void
    {
        if ($this->blank($payload['libelle_action'] ?? null)) {
            return;
        }

        foreach ([
            'libelle_axe' => 'Axe non identifie pour cette action.',
            'libelle_objectif_strategique' => 'Objectif strategique non identifie pour cette action.',
            'libelle_objectif_operationnel' => 'Objectif operationnel non identifie pour cette action.',
        ] as $field => $message) {
            if ($this->blank($payload[$field] ?? null)) {
                $warnings[] = $message;
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $changes
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function withHierarchyNote(array $payload, array $changes, array $warnings): array
    {
        $notes = [];
        $changes = array_values(array_unique($changes));
        if ($changes !== []) {
            $notes[] = 'Hierarchie PTA ajustee: '.implode(', ', $changes);
        }

        foreach (array_unique($warnings) as $warning) {
            $notes[] = 'Controle hierarchie PTA: '.$warning;
        }

        $current = trim((string) ($payload['validation_warnings'] ?? ''));
        $payload['validation_warnings'] = trim(implode(' | ', array_filter([
            $current === '' ? null : $current,
            implode(' | ', $notes),
        ])));

        return $payload;
    }

    private function nextOrder(array &$state, string $scope, string $parent): int
    {
        $current = (int) ($state['nextOrders'][$scope][$parent] ?? 1);
        $state['nextOrders'][$scope][$parent] = $current + 1;

        return $current;
    }

    private function reserveOrder(array &$state, string $scope, string $parent, int $order): void
    {
        $next = (int) ($state['nextOrders'][$scope][$parent] ?? 1);
        if ($next <= $order) {
            $state['nextOrders'][$scope][$parent] = $order + 1;
        }
    }

    private function conflictMessage(string $scope, int $order): string
    {
        return match ($scope) {
            'axes' => "Ordre axe {$order} deja attribue a un autre axe.",
            'strategic' => "Ordre objectif strategique {$order} deja attribue a un autre objectif dans le meme axe.",
            'operational' => "Ordre objectif operationnel {$order} deja attribue a un autre objectif dans le meme objectif strategique.",
            default => "Ordre action {$order} deja attribue a une autre action dans le meme objectif operationnel.",
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function initialState(): array
    {
        return [
            'current' => [],
            'nextOrders' => [],
            'labelOrders' => [],
            'orderLabels' => [],
        ];
    }

    private function integer(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function cleanLabel(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        $label = trim((string) $value);
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return $label === '' ? null : $label;
    }

    private function key(string $value): string
    {
        $value = strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function blank(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }
}
