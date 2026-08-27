<?php

namespace App\Http\Resources;

use App\Services\Dashboard\DashboardOverviewSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use UnexpectedValueException;

class DashboardOverviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (! $this->resource instanceof DashboardOverviewSnapshot) {
            throw new UnexpectedValueException('Le snapshot du tableau de bord est invalide.');
        }

        $payload = $this->resource->toPayload();
        $scope = $this->array($payload, 'scope');
        $selector = $this->array($payload, 'direction_selector');
        $filters = $this->array($payload, 'filters');
        $filterOptions = $this->array($payload, 'filter_options');
        $exercise = $this->array($payload, 'exercise');
        $metrics = $this->array($payload, 'metrics');
        $actionScope = $this->array($metrics, 'action_scope');
        $decision = $this->array($payload, 'synthesis_decision_summary');
        $links = $this->array($payload, 'links');
        $breakdowns = $this->array($links, 'breakdowns');

        return [
            'schema_version' => '1.0',
            'generated_at' => (string) ($payload['generated_at'] ?? ''),
            'scope' => [
                'mode' => (string) ($scope['mode'] ?? ''),
                'user_role' => (string) ($scope['user_role'] ?? ''),
                'effective_role' => (string) ($scope['effective_role'] ?? ''),
                'cross_organization_filters' => (bool) ($scope['cross_organization_filters'] ?? false),
                'organization_filters_enabled' => (bool) ($scope['organization_filters_enabled'] ?? false),
                'read_only' => (bool) ($scope['read_only'] ?? true),
                'direction_id' => $this->nullableInteger($scope['direction_id'] ?? null),
                'service_id' => $this->nullableInteger($scope['service_id'] ?? null),
                'selected_direction_id' => $this->nullableInteger($scope['selected_direction_id'] ?? null),
                'selected_service_id' => $this->nullableInteger($scope['selected_service_id'] ?? null),
            ],
            'direction_selector' => [
                'enabled' => (bool) ($selector['enabled'] ?? false),
                'selected_id' => $this->nullableInteger($selector['selected_id'] ?? null),
                'selected_label' => (string) ($selector['selected_label'] ?? ''),
                'service_selected_id' => $this->nullableInteger($selector['service_selected_id'] ?? null),
                'service_selected_label' => (string) ($selector['service_selected_label'] ?? ''),
                'options' => $this->selectorOptions($selector['options'] ?? []),
                'service_options' => $this->selectorOptions($selector['service_options'] ?? []),
            ],
            'filters' => [
                'periode' => (string) ($filters['periode'] ?? 'all'),
                'periode_label' => (string) ($filters['periode_label'] ?? ''),
                'statut_action' => $this->nullableString($filters['statut_action'] ?? null),
                'statut_suivi' => $this->nullableString($filters['statut_suivi'] ?? null),
                'statut_delai' => $this->nullableString($filters['statut_delai'] ?? null),
                'alerte_echeance' => $this->nullableString($filters['alerte_echeance'] ?? null),
                'responsable_id' => $this->nullableInteger($filters['responsable_id'] ?? null),
            ],
            'filter_options' => [
                'years' => $this->valueOptions($filterOptions['years'] ?? []),
                'quarters' => $this->valueOptions($filterOptions['quarters'] ?? []),
                'periods' => $this->valueOptions($filterOptions['periods'] ?? []),
                'action_statuses' => $this->valueOptions($filterOptions['action_statuses'] ?? []),
                'tracking_statuses' => $this->valueOptions($filterOptions['tracking_statuses'] ?? []),
                'delay_statuses' => $this->valueOptions($filterOptions['delay_statuses'] ?? []),
                'deadline_alerts' => $this->valueOptions($filterOptions['deadline_alerts'] ?? []),
                'responsibles' => $this->responsibleOptions($filterOptions['responsibles'] ?? []),
            ],
            'exercise' => [
                'year' => $this->nullableInteger($exercise['year'] ?? null),
                'quarter' => $this->quarter(
                    $exercise['quarter'] ?? null,
                    $filters['periode'] ?? null,
                ),
            ],
            'metrics' => [
                'totals' => $this->integerMap($metrics['totals'] ?? []),
                'alerts' => $this->integerMap($metrics['alerts'] ?? []),
                'status_breakdown' => $this->nestedIntegerMap($metrics['status_breakdown'] ?? []),
                'action_scope' => [
                    'mode' => (string) ($actionScope['mode'] ?? ''),
                    'visible_actions_total' => (int) ($actionScope['visible_actions_total'] ?? 0),
                    'personal_actions_total' => (int) ($actionScope['personal_actions_total'] ?? 0),
                    'dashboard_actions_total' => (int) ($actionScope['dashboard_actions_total'] ?? 0),
                ],
            ],
            'synthesis_decision_summary' => [
                'total' => (int) ($decision['total'] ?? 0),
                'taux_execution' => (float) ($decision['taux_execution'] ?? 0),
                'performance_pta' => (float) ($decision['performance_pta'] ?? 0),
                'workflow' => $this->integerMap($decision['workflow'] ?? []),
                'delay' => $this->integerMap($decision['delay'] ?? []),
                'alerts' => $this->integerMap($decision['alerts'] ?? []),
            ],
            'financial_summary' => $this->financialSummary($payload['financial_summary'] ?? null),
            'links' => [
                'blade_pilotage' => $this->relativeLink($links['blade_pilotage'] ?? null),
                'tables' => $this->relativeLink($links['tables'] ?? null),
                'charts' => $this->relativeLink($links['charts'] ?? null),
                'actions' => $this->relativeLink($links['actions'] ?? null),
                'pas' => $this->relativeLink($links['pas'] ?? null),
                'paos' => $this->relativeLink($links['paos'] ?? null),
                'ptas' => $this->relativeLink($links['ptas'] ?? null),
                'late_actions' => $this->relativeLink($links['late_actions'] ?? null),
                'kpi_below_threshold' => $this->relativeLink($links['kpi_below_threshold'] ?? null),
                'reporting' => $this->relativeLink($links['reporting'] ?? null),
                'alerts' => $this->relativeLink($links['alerts'] ?? null),
                'pta_tracking' => $this->relativeLink($links['pta_tracking'] ?? null),
                'breakdowns' => [
                    'actions' => $this->relativeLinkMap($breakdowns['actions'] ?? null, [
                        'a_parametrer',
                        'non_demarre',
                        'en_cours',
                        'a_risque',
                        'a_corriger',
                        'en_retard',
                        'acheve',
                        'suspendu',
                        'annule',
                        'en_avance',
                    ]),
                    'workflow' => $this->relativeLinkMap($breakdowns['workflow'] ?? null, [
                        'a_parametrer',
                        'non_demarre',
                        'en_cours',
                        'validation_chef',
                        'validation_controleur',
                        'validation_planification',
                        'cloture',
                    ]),
                    'alerts' => $this->relativeLinkMap($breakdowns['alerts'] ?? null, [
                        'aucune_alerte',
                        'echeance_proche',
                        'critique',
                        'en_retard',
                        'cloturee',
                        'a_parametrer',
                    ]),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function array(array $payload, string $key): array
    {
        return is_array($payload[$key] ?? null) ? $payload[$key] : [];
    }

    /** @return list<array{id:int,label:string}> */
    private function selectorOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return collect($options)
            ->filter(static fn (mixed $option): bool => is_array($option))
            ->map(static fn (array $option): array => [
                'id' => (int) ($option['id'] ?? 0),
                'label' => (string) ($option['label'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, int> */
    private function integerMap(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(static fn (mixed $value): bool => is_numeric($value))
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }

    /** @return array<string, array<string, int>> */
    private function nestedIntegerMap(mixed $groups): array
    {
        if (! is_array($groups)) {
            return [];
        }

        return collect($groups)
            ->filter(static fn (mixed $group): bool => is_array($group))
            ->map(fn (array $group): array => $this->integerMap($group))
            ->all();
    }

    /**
     * @return array{budget:float,engaged:float,disbursed:float,remaining:float,engagement_rate:float,disbursement_rate:float,actions_total:int}|null
     */
    private function financialSummary(mixed $summary): ?array
    {
        if (! is_array($summary)) {
            return null;
        }

        return [
            'budget' => (float) ($summary['budget'] ?? 0),
            'engaged' => (float) ($summary['engaged'] ?? 0),
            'disbursed' => (float) ($summary['disbursed'] ?? 0),
            'remaining' => (float) ($summary['remaining'] ?? 0),
            'engagement_rate' => (float) ($summary['engagement_rate'] ?? 0),
            'disbursement_rate' => (float) ($summary['disbursement_rate'] ?? 0),
            'actions_total' => (int) ($summary['actions_total'] ?? 0),
        ];
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<array{value: string, label: string}> */
    private function valueOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return collect($options)
            ->filter(static fn (mixed $option): bool => is_array($option)
                && is_scalar($option['value'] ?? null)
                && ! is_bool($option['value'])
                && is_string($option['label'] ?? null))
            ->map(static fn (array $option): array => [
                'value' => (string) $option['value'],
                'label' => (string) $option['label'],
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id: int, label: string}> */
    private function responsibleOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return collect($options)
            ->filter(static fn (mixed $option): bool => is_array($option)
                && is_numeric($option['id'] ?? null)
                && (int) $option['id'] > 0
                && is_string($option['label'] ?? null)
                && trim((string) $option['label']) !== '')
            ->map(static fn (array $option): array => [
                'id' => (int) $option['id'],
                'label' => (string) $option['label'],
            ])
            ->values()
            ->all();
    }

    private function relativeLink(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $link = trim($value);

        return str_starts_with($link, '/')
            && ! str_starts_with($link, '//')
            && ! str_contains($link, chr(92))
                ? $link
                : null;
    }

    /**
     * @param  list<string>  $allowedKeys
     * @return array<string, string|null>
     */
    private function relativeLinkMap(mixed $values, array $allowedKeys): array
    {
        $values = is_array($values) ? $values : [];
        $links = [];

        foreach ($allowedKeys as $key) {
            $links[$key] = $this->relativeLink($values[$key] ?? null);
        }

        return $links;
    }

    private function quarter(mixed $quarter, mixed $period): ?string
    {
        if (is_numeric($quarter) && in_array((int) $quarter, [1, 2, 3, 4], true)) {
            return 'q'.(int) $quarter;
        }

        if (is_string($quarter) && preg_match('/^q[1-4]$/', strtolower($quarter)) === 1) {
            return strtolower($quarter);
        }

        return is_string($period) && preg_match('/^q[1-4]$/', strtolower($period)) === 1
            ? strtolower($period)
            : null;
    }
}
