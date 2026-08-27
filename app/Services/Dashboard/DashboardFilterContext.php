<?php

namespace App\Services\Dashboard;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use App\Services\ExerciceContext;
use App\Services\PtaSuiviService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DashboardFilterContext
{
    /**
     * @var array<string, string>
     */
    public const TRACKING_STATUS_OPTIONS = [
        'a_parametrer' => 'À paramétrer',
        'non_demarre' => 'Non démarré',
        'en_cours' => 'En cours',
        'validation_chef' => 'Validation chef',
        'validation_controleur' => 'Validation contrôle',
        'validation_planification' => 'Validation planification',
        'cloture' => 'Clôturé',
    ];

    /**
     * @var array<string, string>
     */
    public const DELAY_STATUS_OPTIONS = [
        'dans_les_delais' => 'Dans les délais',
        'hors_delai' => 'Hors délai',
    ];

    /**
     * @var array<string, string>
     */
    public const DEADLINE_ALERT_OPTIONS = [
        'aucune_alerte' => 'Aucune alerte',
        'echeance_proche' => 'Échéance proche',
        'critique' => 'Critique',
        'en_retard' => 'En retard',
        'cloturee' => 'Clôturée',
        'a_parametrer' => 'À paramétrer',
    ];

    /**
     * @var array<string, string>
     */
    public const ACTION_STATUS_OPTIONS = [
        'a_parametrer' => 'À paramétrer',
        'non_demarre' => 'Non démarrée',
        'en_cours' => 'En cours',
        'a_risque' => 'À risque',
        'en_avance' => 'En avance',
        'en_retard' => 'En retard',
        'a_corriger' => 'À corriger',
        'suspendu' => 'Suspendue',
        'annule' => 'Annulée',
        'acheve' => 'Achevée',
    ];

    /**
     * @var array<string, int|null>
     */
    private array $directionIds = [];

    /**
     * @var array<string, int|null>
     */
    private array $serviceIds = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $directionContexts = [];

    /**
     * @var array<string, array{annee: int|null, direction_id?: int, service_id?: int}>
     */
    private array $actionRouteFilters = [];

    /**
     * @var array{periode: string, periode_label: string, statut_action: string|null, statut_suivi: string|null, statut_delai: string|null, alerte_echeance: string|null, responsable_id: int|null}|null
     */
    private ?array $synthesisFilters = null;

    private ?string $period = null;

    public function __construct(
        private Request $request,
        private readonly ExerciceContext $exerciceContext,
        private readonly PtaSuiviService $ptaSuiviService,
    ) {}

    public function useRequest(Request $request): void
    {
        if ($this->request === $request) {
            return;
        }

        $this->request = $request;
        $this->directionIds = [];
        $this->serviceIds = [];
        $this->directionContexts = [];
        $this->actionRouteFilters = [];
        $this->synthesisFilters = null;
        $this->period = null;
    }

    public function directionId(User $user): ?int
    {
        $cacheKey = $this->userCacheKey($user);
        if (array_key_exists($cacheKey, $this->directionIds)) {
            return $this->directionIds[$cacheKey];
        }

        $this->directionIds[$cacheKey] = null;
        $rawValue = $this->queryString('direction_id');
        if ($rawValue === '' || $rawValue === 'all' || ! ctype_digit($rawValue)) {
            return null;
        }

        $directionId = (int) $rawValue;
        if ($directionId <= 0) {
            return null;
        }

        if (! $user->hasCrossOrganizationDashboardAccess()
            && ! in_array($directionId, $this->allowedDirectionIds($user), true)) {
            return null;
        }

        $this->directionIds[$cacheKey] = Direction::query()
            ->whereKey($directionId)
            ->where('actif', true)
            ->exists()
                ? $directionId
                : null;

        return $this->directionIds[$cacheKey];
    }

    public function serviceId(User $user): ?int
    {
        $cacheKey = $this->userCacheKey($user);
        if (array_key_exists($cacheKey, $this->serviceIds)) {
            return $this->serviceIds[$cacheKey];
        }

        $this->serviceIds[$cacheKey] = null;
        $directionId = $this->directionId($user);
        if ($directionId === null) {
            return null;
        }

        $rawValue = $this->queryString('service_id');
        if ($rawValue === '' || $rawValue === 'all' || ! ctype_digit($rawValue)) {
            return null;
        }

        $serviceId = (int) $rawValue;
        if ($serviceId <= 0) {
            return null;
        }

        if (! $user->hasCrossOrganizationDashboardAccess()
            && ! $this->canUseService($user, $directionId, $serviceId)) {
            return null;
        }

        $this->serviceIds[$cacheKey] = Service::query()
            ->whereKey($serviceId)
            ->where('direction_id', $directionId)
            ->where('actif', true)
            ->exists()
                ? $serviceId
                : null;

        return $this->serviceIds[$cacheKey];
    }

    /**
     * @return array{enabled: bool, selected_id: int|null, selected_label: string, service_selected_id: int|null, service_selected_label: string, options: list<array{id: int, label: string}>, service_options: list<array{id: int, label: string}>}
     */
    public function directionContext(User $user): array
    {
        $cacheKey = $this->userCacheKey($user);
        if (array_key_exists($cacheKey, $this->directionContexts)) {
            return $this->directionContexts[$cacheKey];
        }

        $hasCrossOrganizationAccess = $user->hasCrossOrganizationDashboardAccess();
        $enabled = $hasCrossOrganizationAccess || $this->hasDelegatedPlanningScope($user);
        $selectedId = $this->directionId($user);
        $selectedServiceId = $this->serviceId($user);
        $directions = $enabled
            ? Direction::query()
                ->where('actif', true)
                ->when(
                    ! $hasCrossOrganizationAccess,
                    fn ($query) => $query->whereIn('id', $this->allowedDirectionIds($user))
                )
                ->orderBy('code')
                ->orderBy('libelle')
                ->get(['id', 'code', 'libelle'])
            : collect();
        $selected = $selectedId !== null
            ? ($directions->firstWhere('id', $selectedId)
                ?? Direction::query()->whereKey($selectedId)->where('actif', true)->first(['id', 'code', 'libelle']))
            : null;
        $services = ($enabled && $selectedId !== null)
            ? Service::query()
                ->where('direction_id', $selectedId)
                ->where('actif', true)
                ->when(
                    ! $hasCrossOrganizationAccess && ! $this->canReadAllServicesInDirection($user, $selectedId),
                    fn ($query) => $query->whereIn('id', $this->narrowServiceIds($user, $selectedId))
                )
                ->orderBy('code')
                ->orderBy('libelle')
                ->get(['id', 'code', 'libelle'])
            : collect();
        $selectedService = $selectedServiceId !== null
            ? ($services->firstWhere('id', $selectedServiceId)
                ?? Service::query()
                    ->whereKey($selectedServiceId)
                    ->where('direction_id', $selectedId)
                    ->where('actif', true)
                    ->first(['id', 'code', 'libelle']))
            : null;

        return $this->directionContexts[$cacheKey] = [
            'enabled' => $enabled,
            'selected_id' => $selectedId,
            'selected_label' => $selected instanceof Direction
                ? trim((string) ($selected->code ?: '').' - '.(string) $selected->libelle, ' -')
                : 'Pilotage global',
            'service_selected_id' => $selectedServiceId,
            'service_selected_label' => $selectedService instanceof Service
                ? trim((string) ($selectedService->code ?: '').' - '.(string) $selectedService->libelle, ' -')
                : 'Tous les services',
            'options' => $directions
                ->map(fn (Direction $direction): array => [
                    'id' => (int) $direction->id,
                    'label' => trim((string) ($direction->code ?: '').' - '.(string) $direction->libelle, ' -'),
                ])
                ->values()
                ->all(),
            'service_options' => $services
                ->map(fn (Service $service): array => [
                    'id' => (int) $service->id,
                    'label' => trim((string) ($service->code ?: '').' - '.(string) $service->libelle, ' -'),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{periode: string, periode_label: string, statut_action: string|null, statut_suivi: string|null, statut_delai: string|null, alerte_echeance: string|null, responsable_id: int|null}
     */
    public function synthesisFilters(): array
    {
        if ($this->synthesisFilters !== null) {
            return $this->synthesisFilters;
        }

        $responsable = $this->queryString('responsable_id');
        $responsableId = $responsable !== ''
            && $responsable !== 'all'
            && ctype_digit($responsable)
            && (int) $responsable > 0
                ? (int) $responsable
                : null;
        $period = $this->period();

        return $this->synthesisFilters = [
            'periode' => $period,
            'periode_label' => $this->ptaSuiviService->periodLabel($period),
            'responsable_id' => $responsableId,
            'statut_action' => $this->allowedQueryValue('statut_action', array_keys(self::ACTION_STATUS_OPTIONS)),
            'statut_suivi' => $this->allowedQueryValue('statut_suivi', array_keys(self::TRACKING_STATUS_OPTIONS)),
            'statut_delai' => $this->allowedQueryValue('statut_delai', array_keys(self::DELAY_STATUS_OPTIONS)),
            'alerte_echeance' => $this->allowedQueryValue('alerte_echeance', array_keys(self::DEADLINE_ALERT_OPTIONS)),
        ];
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return array{
     *     years: list<array{value: string, label: string}>,
     *     quarters: list<array{value: string, label: string}>,
     *     periods: list<array{value: string, label: string}>,
     *     action_statuses: list<array{value: string, label: string}>,
     *     tracking_statuses: list<array{value: string, label: string}>,
     *     delay_statuses: list<array{value: string, label: string}>,
     *     deadline_alerts: list<array{value: string, label: string}>,
     *     responsibles: list<array{id: int, label: string}>
     * }
     */
    public function filterOptions(Collection $actions): array
    {
        return [
            'years' => collect($this->exerciceContext->options())
                ->map(static fn (array $option): array => [
                    'value' => (string) ($option['value'] ?? ''),
                    'label' => (string) ($option['label'] ?? ''),
                ])
                ->values()
                ->all(),
            'quarters' => collect($this->exerciceContext->quarterOptions())
                ->map(static fn (array $option): array => [
                    'value' => (string) ($option['value'] ?? '') === 'all'
                        ? 'all'
                        : 'q'.(string) ($option['value'] ?? ''),
                    'label' => (string) ($option['label'] ?? ''),
                ])
                ->values()
                ->all(),
            'periods' => $this->ptaSuiviService->periodOptions(),
            'action_statuses' => self::valueOptions(self::ACTION_STATUS_OPTIONS),
            'tracking_statuses' => self::valueOptions(self::TRACKING_STATUS_OPTIONS),
            'delay_statuses' => self::valueOptions(self::DELAY_STATUS_OPTIONS),
            'deadline_alerts' => self::valueOptions(self::DEADLINE_ALERT_OPTIONS),
            'responsibles' => $this->responsibleOptions($actions),
        ];
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return list<array{id: int, label: string}>
     */
    public function responsibleOptions(Collection $actions): array
    {
        $now = now();

        return $actions
            ->flatMap(static function (Action $action): array {
                return [
                    $action->responsable,
                    ...$action->responsables->all(),
                    ...$action->sousActions->pluck('agent')->all(),
                ];
            })
            ->filter(static fn (mixed $responsible): bool => $responsible instanceof User
                && (bool) $responsible->is_active
                && ($responsible->suspended_until === null || $responsible->suspended_until->lte($now)))
            ->unique(static fn (User $responsible): int => (int) $responsible->id)
            ->sortBy(static fn (User $responsible): string => Str::lower(Str::ascii((string) $responsible->name)))
            ->map(static fn (User $responsible): array => [
                'id' => (int) $responsible->id,
                'label' => (string) $responsible->name,
            ])
            ->values()
            ->all();
    }

    public function period(): string
    {
        if ($this->period !== null) {
            return $this->period;
        }

        if ($this->request->query->has('periode')) {
            $value = $this->queryString('periode');
        } elseif ($this->request->query->has('trimestre')) {
            $value = $this->queryString('trimestre');
        } else {
            $value = $this->exerciceContext->selectedQuarter() ?: 'all';
        }

        return $this->period = $this->ptaSuiviService->normalizePeriod($value);
    }

    /**
     * @return array{annee: int|null, direction_id?: int, service_id?: int}
     */
    public function actionRouteFilters(User $user): array
    {
        $cacheKey = $this->userCacheKey($user);
        if (array_key_exists($cacheKey, $this->actionRouteFilters)) {
            return $this->actionRouteFilters[$cacheKey];
        }

        $filters = ['annee' => $this->exerciceContext->selectedYear()];

        if (($directionId = $this->directionId($user)) !== null) {
            $filters['direction_id'] = $directionId;
        }

        if (($serviceId = $this->serviceId($user)) !== null) {
            $filters['service_id'] = $serviceId;
        }

        return $this->actionRouteFilters[$cacheKey] = $filters;
    }

    /**
     * @return array{annee: int|null, direction_id?: int, service_id?: int}
     */
    public function currentUserActionRouteFilters(): array
    {
        $user = $this->request->user();

        return $user instanceof User
            ? $this->actionRouteFilters($user)
            : ['annee' => $this->exerciceContext->selectedYear()];
    }

    /**
     * @return array{exercice?: int, periode: string, direction_id?: int, service_id?: int, responsable_id?: int, statut_action?: string, statut_suivi?: string, statut_delai?: string, alerte_echeance?: string}
     */
    public function dashboardRouteFilters(?User $user = null): array
    {
        $user ??= $this->request->user();
        $synthesisFilters = $this->synthesisFilters();
        $filters = [
            'exercice' => $this->exerciceContext->selectedYear(),
            'periode' => $synthesisFilters['periode'],
            'responsable_id' => $synthesisFilters['responsable_id'],
            'statut_action' => $synthesisFilters['statut_action'],
            'statut_suivi' => $synthesisFilters['statut_suivi'],
            'statut_delai' => $synthesisFilters['statut_delai'],
            'alerte_echeance' => $synthesisFilters['alerte_echeance'],
        ];

        if ($user instanceof User) {
            $filters['direction_id'] = $this->directionId($user);
            $filters['service_id'] = $this->serviceId($user);
        }

        return array_filter(
            $filters,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    /**
     * @return array{periode: string, direction_filter: int|null, service_filter: int|null, responsable_filter: int|null, statut_action: string|null, statut_suivi: string|null, statut_delai: string|null, alerte_echeance: string|null}
     */
    public function cacheDimensions(User $user): array
    {
        $filters = $this->synthesisFilters();

        return [
            'periode' => $this->period(),
            'direction_filter' => $this->directionId($user),
            'service_filter' => $this->serviceId($user),
            'responsable_filter' => $filters['responsable_id'],
            'statut_action' => $filters['statut_action'],
            'statut_suivi' => $filters['statut_suivi'],
            'statut_delai' => $filters['statut_delai'],
            'alerte_echeance' => $filters['alerte_echeance'],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowedQueryValue(string $key, array $allowed): ?string
    {
        $value = $this->queryString($key);

        return $value !== '' && $value !== 'all' && in_array($value, $allowed, true)
            ? $value
            : null;
    }

    private function queryString(string $key): string
    {
        $value = $this->request->query($key, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @return list<int>
     */
    private function allowedDirectionIds(User $user): array
    {
        $directionIds = $this->delegatedDirectionScopeIds($user);
        foreach ($this->delegatedServiceScopes($user) as $scope) {
            $directionIds[] = $scope['direction_id'];
        }

        if ($user->direction_id !== null
            && ($user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE) || $user->isAgent())) {
            $directionIds[] = (int) $user->direction_id;
        }

        sort($directionIds);

        return array_values(array_unique(array_filter(
            $directionIds,
            static fn (int $directionId): bool => $directionId > 0
        )));
    }

    /**
     * @return list<int>
     */
    private function delegatedDirectionScopeIds(User $user): array
    {
        $directionIds = array_merge(
            $user->delegatedDirectionIds('planning_read'),
            $user->delegatedDirectionIds('planning_write'),
        );
        sort($directionIds);

        return array_values(array_unique($directionIds));
    }

    /**
     * @return list<array{direction_id: int, service_id: int}>
     */
    private function delegatedServiceScopes(User $user): array
    {
        return collect(array_merge(
            $user->delegatedServiceScopes('planning_read'),
            $user->delegatedServiceScopes('planning_write'),
        ))
            ->unique(static fn (array $scope): string => $scope['direction_id'].'-'.$scope['service_id'])
            ->sortBy(static fn (array $scope): string => sprintf('%010d-%010d', $scope['direction_id'], $scope['service_id']))
            ->values()
            ->all();
    }

    private function hasDelegatedPlanningScope(User $user): bool
    {
        return $this->delegatedDirectionScopeIds($user) !== []
            || $this->delegatedServiceScopes($user) !== [];
    }

    private function canReadAllServicesInDirection(User $user, int $directionId): bool
    {
        return ($user->hasRole(User::ROLE_DIRECTION)
                && $user->direction_id !== null
                && (int) $user->direction_id === $directionId)
            || in_array($directionId, $this->delegatedDirectionScopeIds($user), true);
    }

    private function canUseService(User $user, int $directionId, int $serviceId): bool
    {
        return $this->canReadAllServicesInDirection($user, $directionId)
            || in_array($serviceId, $this->narrowServiceIds($user, $directionId), true);
    }

    /**
     * @return list<int>
     */
    private function narrowServiceIds(User $user, int $directionId): array
    {
        $serviceIds = collect($this->delegatedServiceScopes($user))
            ->where('direction_id', $directionId)
            ->pluck('service_id')
            ->map(static fn (mixed $serviceId): int => (int) $serviceId)
            ->all();

        if ($user->direction_id !== null
            && (int) $user->direction_id === $directionId
            && $user->service_id !== null
            && ($user->hasRole(User::ROLE_SERVICE) || $user->isAgent())) {
            $serviceIds[] = (int) $user->service_id;
        }
        sort($serviceIds);

        return array_values(array_unique(array_filter(
            $serviceIds,
            static fn (int $serviceId): bool => $serviceId > 0
        )));
    }

    private function userCacheKey(User $user): string
    {
        return $user->exists
            ? 'id:'.(int) $user->getKey()
            : 'object:'.spl_object_id($user);
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private static function valueOptions(array $options): array
    {
        return collect($options)
            ->map(static fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }
}
