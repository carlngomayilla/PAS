<?php

namespace App\Services;

use App\Models\Action;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Alerting\AlertCenterService;
use App\Services\Alerting\AlertReadService;
use App\Services\Analytics\AnalyticsCacheVersionService;
use App\Support\SchemaIntrospectionCache;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

class AdminLayoutViewDataService
{
    public function __construct(
        private readonly Request $request,
        private readonly ExerciceContext $exerciceContext,
        private readonly AlertReadService $alertReadService,
        private readonly AlertCenterService $alertCenterService,
        private readonly AnalyticsCacheVersionService $analyticsCacheVersionService,
        private readonly PlanningModificationLockService $planningModificationLockService,
        private readonly PersonalTaskService $personalTaskService,
        private readonly DeadlineExtensionQueueService $deadlineExtensionQueueService,
        private readonly UserWorkspaceService $userWorkspaceService,
        private readonly AccessScopeService $accessScopeService,
        private readonly RoleRegistryService $roleRegistryService,
        private readonly CacheFactory $cache,
    ) {}

    public function compose(View $view): void
    {
        $provided = $view->getData();
        $requestUser = $this->request->user();
        $user = array_key_exists('layoutUser', $provided)
            ? ($provided['layoutUser'] instanceof User ? $provided['layoutUser'] : null)
            : ($requestUser instanceof User ? $requestUser : null);

        foreach ($this->data($user, $provided) as $key => $value) {
            if (! array_key_exists($key, $provided)) {
                $view->with($key, $value);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $provided
     * @return array<string, mixed>
     */
    public function data(?User $user, array $provided = []): array
    {
        $activePeriodLabel = array_key_exists('headerActivePeriodLabel', $provided)
            && is_string($provided['headerActivePeriodLabel'])
                ? $provided['headerActivePeriodLabel']
                : $this->exerciceContext->activeLabel();

        if ($user === null) {
            return $this->anonymousData($activePeriodLabel);
        }

        $this->loadLayoutRelations($user);

        $workspaceModules = $this->providedModules($provided)
            ?? $this->userWorkspaceService->modulesFor($user);
        $accessScope = match (true) {
            is_array($provided['navbarScope'] ?? null) => $provided['navbarScope'],
            is_array($provided['accessScope'] ?? null) => $provided['accessScope'],
            default => $this->accessScopeService->scopeFor($user),
        };
        $notificationFeed = $this->notificationFeed($user, $provided);
        $headerNotifications = $notificationFeed
            ->reject(static fn ($notification): bool => strtolower((string) ($notification->data['module'] ?? '')) === 'alertes')
            ->take(12)
            ->values();
        $unreadNotifications = $user->unreadNotifications()
            ->latest()
            ->get();
        $headerUnreadCount = $unreadNotifications->count();
        $headerUnreadByModule = $unreadNotifications
            ->groupBy(static fn ($notification): string => strtolower((string) ($notification->data['module'] ?? 'autres')))
            ->map(static fn (Collection $notifications): int => $notifications->count())
            ->toArray();
        $headerNotificationUnreadCount = $unreadNotifications
            ->reject(static fn ($notification): bool => strtolower((string) ($notification->data['module'] ?? '')) === 'alertes')
            ->count();
        $headerSidebarBadges = $headerUnreadByModule;
        $headerSidebarBadges['notifications'] = $headerNotificationUnreadCount;
        $headerAlertSummary = $this->emptyAlertSummary();

        if ($user->hasPermission('alerts.read')) {
            $headerAlertSummary = $this->alertCenterService->summaryForUser(
                $user,
                $this->alertReadService->readFingerprintsForUser($user),
            );
        }

        $headerAlertUnreadCount = (int) ($headerAlertSummary['unread'] ?? 0);
        $headerSidebarBadges['notifications'] = $headerNotificationUnreadCount + $headerAlertUnreadCount;

        $validationBadgeCount = $this->validationBadgeCount($user);
        if ($validationBadgeCount > 0) {
            $headerSidebarBadges['actions'] = (int) ($headerSidebarBadges['actions'] ?? 0) + $validationBadgeCount;
        }

        $openTasksCount = $this->personalTaskService->openTaskCount($user);
        if ($openTasksCount > 0) {
            $headerSidebarBadges['mes_taches'] = $openTasksCount;
        }

        $controlTasksCount = $this->personalTaskService->controlTaskCount($user);
        if ($controlTasksCount > 0) {
            $headerSidebarBadges['controle'] = $controlTasksCount;
        }

        if ($this->hasTable('deadline_extension_requests')) {
            $deadlineExtensionTaskCount = $this->deadlineExtensionQueueService->actionableCount($user);
            if ($deadlineExtensionTaskCount > 0) {
                $headerSidebarBadges['reports_echeance'] = $deadlineExtensionTaskCount;
            }
        }

        $headerBellUnreadCount = $headerNotificationUnreadCount + $headerAlertUnreadCount;
        $headerBellBadgeKind = match (true) {
            $headerNotificationUnreadCount > 0 && $headerAlertUnreadCount > 0 => 'both',
            $headerAlertUnreadCount > 0 => 'alert',
            $headerNotificationUnreadCount > 0 => 'notification',
            default => 'none',
        };
        $headerBellBadgeClass = match ($headerBellBadgeKind) {
            'both' => 'bg-[#6d28d9]',
            'alert' => 'bg-[#92400e]',
            'notification' => 'bg-[#0f5f99]',
            default => 'bg-[#0f5f99]',
        };
        $navbarScopeType = (string) ($accessScope['scope_type'] ?? AccessScopeService::TYPE_LIMITED);

        return [
            'layoutUser' => $user,
            'layoutUserRoleLabel' => $this->roleRegistryService->label($user->effectiveRoleCode()),
            'headerActivePeriodLabel' => $activePeriodLabel,
            'headerNotifications' => $headerNotifications,
            'headerUnreadCount' => $headerUnreadCount,
            'headerNotificationUnreadCount' => $headerNotificationUnreadCount,
            'headerUnreadByModule' => $headerUnreadByModule,
            'headerSidebarBadges' => $headerSidebarBadges,
            'headerAlertSummary' => $headerAlertSummary,
            'headerAlertUnreadCount' => $headerAlertUnreadCount,
            'headerBellUnreadCount' => $headerBellUnreadCount,
            'headerBellBadgeKind' => $headerBellBadgeKind,
            'headerBellBadgeClass' => $headerBellBadgeClass,
            'layoutWorkspaceModules' => $workspaceModules,
            'navbarScope' => $accessScope,
            'navbarScopeType' => $navbarScopeType,
            'navbarScopeLabel' => $this->navbarScopeLabel($navbarScopeType, $user),
            'navbarScopeTone' => $this->navbarScopeTone($navbarScopeType),
            'sidebarIsDafFinanceReviewer' => $user->hasRole(User::ROLE_DIRECTION)
                && $user->direction_id !== null
                && (string) ($user->direction?->code ?? '') === 'DAF',
            'sidebarIsSuperAdmin' => $user->isSuperAdmin(),
            'validationBadgeCount' => $validationBadgeCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function anonymousData(string $activePeriodLabel): array
    {
        return [
            'layoutUser' => null,
            'layoutUserRoleLabel' => 'Compte',
            'headerActivePeriodLabel' => $activePeriodLabel,
            'headerNotifications' => collect(),
            'headerUnreadCount' => 0,
            'headerNotificationUnreadCount' => 0,
            'headerUnreadByModule' => [],
            'headerSidebarBadges' => [],
            'headerAlertSummary' => $this->emptyAlertSummary(),
            'headerAlertUnreadCount' => 0,
            'headerBellUnreadCount' => 0,
            'headerBellBadgeKind' => 'none',
            'headerBellBadgeClass' => 'bg-[#0f5f99]',
            'layoutWorkspaceModules' => [],
            'navbarScope' => [],
            'navbarScopeType' => AccessScopeService::TYPE_LIMITED,
            'navbarScopeLabel' => 'Accès limité',
            'navbarScopeTone' => 'border-slate-300 bg-slate-50 text-slate-700',
            'sidebarIsDafFinanceReviewer' => false,
            'sidebarIsSuperAdmin' => false,
            'validationBadgeCount' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $provided
     * @return array<int, array<string, mixed>>|null
     */
    private function providedModules(array $provided): ?array
    {
        foreach (['layoutWorkspaceModules', 'modules'] as $key) {
            if (array_key_exists($key, $provided) && is_iterable($provided[$key])) {
                return collect($provided[$key])->values()->all();
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $provided
     * @return Collection<int, mixed>
     */
    private function notificationFeed(User $user, array $provided): Collection
    {
        if (array_key_exists('layoutNotifications', $provided) && is_iterable($provided['layoutNotifications'])) {
            return collect($provided['layoutNotifications']);
        }

        if (array_key_exists('notifications', $provided) && is_iterable($provided['notifications'])) {
            $providedNotifications = collect($provided['notifications']);
            if ($providedNotifications->isNotEmpty()
                && $providedNotifications->every(
                    static fn (mixed $notification): bool => $notification instanceof DatabaseNotification,
                )) {
                return $providedNotifications;
            }
        }

        return $user->notifications()
            ->latest()
            ->limit(24)
            ->get();
    }

    private function loadLayoutRelations(User $user): void
    {
        if ($user->hasRole(User::ROLE_DIRECTION) && $user->relationLoaded('direction')) {
            $direction = $user->getRelation('direction');
            if ($direction instanceof Model && ! array_key_exists('code', $direction->getAttributes())) {
                $user->unsetRelation('direction');
            }
        }

        $user->loadMissing([
            'direction:id,code,libelle',
            'service:id,libelle',
            'uniteDg:id,libelle',
        ]);
    }

    private function validationBadgeCount(User $user): int
    {
        if (! $this->hasTable('actions')) {
            return 0;
        }

        $dashboardVersion = $this->analyticsCacheVersionService->dashboardVersion();

        return (int) $this->cache->store()->remember(
            'header-validation-badge:'.$dashboardVersion.':'.(int) $user->id,
            now()->addSeconds(120),
            fn (): int => $this->uncachedValidationBadgeCount($user),
        );
    }

    private function uncachedValidationBadgeCount(User $user): int
    {
        $isFinalControlUser = $this->planningModificationLockService->canGivePlanifAvis($user)
            || $user->isSuperAdmin()
            || $user->hasRole(User::ROLE_ADMIN_FONCTIONNEL);
        $isGlobalReader = $user->hasGlobalReadAccess()
            || $user->hasRole(
                User::ROLE_SUPER_ADMIN,
                User::ROLE_DG,
                User::ROLE_PLANIFICATION,
                User::ROLE_CABINET,
            );
        $pendingQuery = Action::query()
            ->whereIn('statut_validation', $isFinalControlUser
                ? [
                    ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                    ActionTrackingService::VALIDATION_SOUMISE_CONTROLE,
                ]
                : [ActionTrackingService::VALIDATION_SOUMISE_CHEF]);

        if (! $isGlobalReader) {
            if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id) {
                $pendingQuery->whereHas(
                    'pta',
                    fn (Builder $query): Builder => $query->where('direction_id', $user->direction_id),
                );
            } elseif ($user->hasRole(User::ROLE_SERVICE) && $user->service_id) {
                $pendingQuery->whereHas(
                    'pta',
                    fn (Builder $query): Builder => $query->where('service_id', $user->service_id),
                );
            } else {
                $pendingQuery->whereRaw('0 = 1');
            }
        }

        $count = (int) $pendingQuery->count();
        $count += (int) Action::query()
            ->whereIn('statut_validation', [
                ActionTrackingService::VALIDATION_REJETEE_CHEF,
                ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
                ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
            ])
            ->where(static fn (Builder $query): Builder => $query
                ->where('responsable_id', $user->id)
                ->orWhereHas(
                    'responsables',
                    fn (Builder $responsibleQuery): Builder => $responsibleQuery->where('users.id', $user->id),
                ))
            ->count();

        return $count;
    }

    private function navbarScopeLabel(string $scopeType, User $user): string
    {
        return match ($scopeType) {
            AccessScopeService::TYPE_GLOBAL => 'Vue globale',
            AccessScopeService::TYPE_DIRECTION => 'Direction : '.($user->direction?->libelle ?? '—'),
            AccessScopeService::TYPE_SERVICE => 'Service : '.($user->service?->libelle ?? '—'),
            AccessScopeService::TYPE_UNITE => 'Unité : '.($user->uniteDg?->libelle ?? '—'),
            AccessScopeService::TYPE_AGENT => 'Mes actions',
            default => 'Accès limité',
        };
    }

    private function navbarScopeTone(string $scopeType): string
    {
        return match ($scopeType) {
            AccessScopeService::TYPE_GLOBAL => 'border-emerald-300 bg-emerald-50 text-emerald-700',
            AccessScopeService::TYPE_DIRECTION,
            AccessScopeService::TYPE_UNITE => 'border-sky-300 bg-sky-50 text-sky-700',
            AccessScopeService::TYPE_SERVICE => 'border-amber-300 bg-amber-50 text-amber-700',
            AccessScopeService::TYPE_AGENT => 'border-violet-300 bg-violet-50 text-violet-700',
            default => 'border-slate-300 bg-slate-50 text-slate-700',
        };
    }

    /**
     * @return array{total:int,unread:int,urgence:int,critical:int,warning:int,info:int}
     */
    private function emptyAlertSummary(): array
    {
        return [
            'total' => 0,
            'unread' => 0,
            'urgence' => 0,
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
        ];
    }

    private function hasTable(string $table): bool
    {
        try {
            return SchemaIntrospectionCache::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
