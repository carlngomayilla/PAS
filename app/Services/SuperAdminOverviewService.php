<?php

namespace App\Services;

use App\Models\DataArchive;
use App\Models\DeletionRequest;
use App\Models\Exercice;
use App\Models\ExportTemplate;
use App\Models\ExportTemplateAssignment;
use App\Models\JournalAudit;
use App\Models\PlatformSettingSnapshot;
use App\Models\UniteDg;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SuperAdminOverviewService
{
    /** @var list<string> */
    private const AUDIT_MODULES = ['super_admin', 'export_template', 'retention'];

    public function __construct(
        private readonly ActionCalculationSettings $actionCalculationSettings,
        private readonly ActionManagementSettings $actionManagementSettings,
        private readonly AppearanceSettings $appearanceSettings,
        private readonly DashboardProfileSettings $dashboardProfileSettings,
        private readonly DynamicReferentialSettings $dynamicReferentialSettings,
        private readonly ManagedKpiSettings $managedKpiSettings,
        private readonly NotificationPolicySettings $notificationPolicySettings,
        private readonly PlatformDiagnosticService $platformDiagnosticService,
        private readonly PlatformMaintenanceService $platformMaintenanceService,
        private readonly PlatformSettings $platformSettings,
        private readonly RolePermissionSettings $rolePermissionSettings,
        private readonly WorkspaceModuleSettings $workspaceModuleSettings
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $isTechnicalAdministrator = $user->isSuperAdmin();
        $diagnostics = collect($this->platformDiagnosticService->checks());
        $diagnosticWarnings = $diagnostics->where('status', 'warning')->values();
        $maintenance = $this->platformMaintenanceService->status();
        $drafts = $this->configurationDrafts($isTechnicalAdministrator);
        $draftsTotal = $drafts->where('has_draft', true)->count();
        $draftRoute = data_get($drafts->firstWhere('has_draft', true), 'route');
        $pendingDeletionRequests = DeletionRequest::query()
            ->whereIn('status', [
                DeletionRequest::STATUS_PENDING,
                DeletionRequest::STATUS_COMPLEMENT_REQUESTED,
            ])
            ->count();
        $latestSnapshot = PlatformSettingSnapshot::query()
            ->with('creator:id,name')
            ->latest('id')
            ->first();
        $activeUsers = User::query()->where('is_active', true)->count();
        $totalUsers = User::query()->count();
        $publishedTemplates = ExportTemplate::query()
            ->where('status', ExportTemplate::STATUS_PUBLISHED)
            ->where('is_active', true)
            ->count();
        $draftTemplates = ExportTemplate::query()
            ->where('status', ExportTemplate::STATUS_DRAFT)
            ->count();
        $modules = $this->workspaceModuleSettings->configuredModules();
        $activeModules = collect($modules)->where('enabled', true)->count();
        $snapshotsTotal = PlatformSettingSnapshot::query()->count();
        $activeSessions = $this->activeSessionsCount();

        $summary = [
            'active_users' => $activeUsers,
            'total_users' => $totalUsers,
            'active_sessions' => $activeSessions,
            'modules_active' => $activeModules,
            'modules_total' => count($modules),
            'diagnostic_warnings' => $diagnosticWarnings->count(),
            'diagnostic_checks' => $diagnostics->where('status', '!=', 'info')->count(),
            'pending_deletion_requests' => $pendingDeletionRequests,
            'configuration_drafts' => $draftsTotal,
            'templates_published' => $publishedTemplates,
            'templates_draft' => $draftTemplates,
            'assignments_active' => ExportTemplateAssignment::query()->where('is_active', true)->count(),
            'snapshots_total' => $snapshotsTotal,
            'archives_total' => DataArchive::query()->count(),
        ];

        return [
            'summary' => $summary,
            'platformState' => $this->platformState(
                (bool) ($maintenance['maintenance_active'] ?? false),
                $diagnosticWarnings->count(),
                $pendingDeletionRequests,
                $draftsTotal
            ),
            'attentionItems' => $this->attentionItems(
                (bool) ($maintenance['maintenance_active'] ?? false),
                $diagnosticWarnings,
                $pendingDeletionRequests,
                $draftsTotal,
                is_string($draftRoute) ? $draftRoute : null,
                $latestSnapshot,
                $draftTemplates,
                $isTechnicalAdministrator
            ),
            'configurationDrafts' => $drafts,
            'configurationFacts' => $this->configurationFacts(),
            'areas' => $this->areas($summary, $isTechnicalAdministrator),
            'activity' => $this->activity(),
            'recentAudits' => $this->recentAudits(),
            'latestSnapshot' => $latestSnapshot,
            'isTechnicalAdministrator' => $isTechnicalAdministrator,
        ];
    }

    /**
     * @return Collection<int, array{key:string,label:string,has_draft:bool,updated_at:Carbon|null,route:string}>
     */
    private function configurationDrafts(bool $isTechnicalAdministrator): Collection
    {
        $drafts = collect([
            [
                'key' => 'general',
                'label' => 'Paramètres généraux',
                'has_draft' => $this->platformSettings->hasDraft(),
                'updated_at' => $this->asDate($this->platformSettings->draftUpdatedAt()),
                'route' => route('workspace.super-admin.settings.edit'),
            ],
            [
                'key' => 'modules',
                'label' => 'Modules et navigation',
                'has_draft' => $this->workspaceModuleSettings->hasDraft(),
                'updated_at' => $this->asDate($this->workspaceModuleSettings->draftUpdatedAt()),
                'route' => route('workspace.super-admin.modules.edit'),
            ],
            [
                'key' => 'appearance',
                'label' => 'Apparence',
                'has_draft' => $this->appearanceSettings->hasDraft(),
                'updated_at' => $this->asDate($this->appearanceSettings->draftUpdatedAt()),
                'route' => route('workspace.super-admin.appearance.edit'),
            ],
        ]);

        return $isTechnicalAdministrator
            ? $drafts
            : $drafts->where('key', 'appearance')->values();
    }

    /**
     * @return array{label:string,tone:string,detail:string}
     */
    private function platformState(
        bool $maintenanceActive,
        int $warningCount,
        int $pendingDeletionRequests,
        int $draftsTotal
    ): array {
        if ($maintenanceActive) {
            return [
                'label' => 'Maintenance active',
                'tone' => 'critical',
                'detail' => 'Intervention technique en cours',
            ];
        }

        if ($warningCount > 0) {
            return [
                'label' => 'Vigilance requise',
                'tone' => 'warning',
                'detail' => $warningCount.' contrôle(s) à régulariser',
            ];
        }

        if ($pendingDeletionRequests > 0 || $draftsTotal > 0) {
            return [
                'label' => 'Pilotage requis',
                'tone' => 'info',
                'detail' => ($pendingDeletionRequests + $draftsTotal).' décision(s) ou publication(s)',
            ];
        }

        return [
            'label' => 'Plateforme stable',
            'tone' => 'success',
            'detail' => 'Aucune intervention prioritaire',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $diagnosticWarnings
     * @return Collection<int, array{label:string,meta:string,tone:string,route:string}>
     */
    private function attentionItems(
        bool $maintenanceActive,
        Collection $diagnosticWarnings,
        int $pendingDeletionRequests,
        int $draftsTotal,
        ?string $draftRoute,
        ?PlatformSettingSnapshot $latestSnapshot,
        int $draftTemplates,
        bool $isTechnicalAdministrator
    ): Collection {
        $items = collect();

        if ($isTechnicalAdministrator && $maintenanceActive) {
            $items->push([
                'label' => 'Maintenance active',
                'meta' => 'Accès utilisateurs potentiellement restreint',
                'tone' => 'critical',
                'route' => route('workspace.super-admin.maintenance.index'),
            ]);
        }

        if ($isTechnicalAdministrator && $diagnosticWarnings->isNotEmpty()) {
            $affectedRecords = $diagnosticWarnings->sum(fn (array $check): int => (int) ($check['count'] ?? 0));
            $items->push([
                'label' => $diagnosticWarnings->count().' contrôle(s) en anomalie',
                'meta' => $affectedRecords.' enregistrement(s) concerné(s)',
                'tone' => 'warning',
                'route' => route('workspace.super-admin.audit-diagnostic.index'),
            ]);
        }

        if ($pendingDeletionRequests > 0) {
            $items->push([
                'label' => $pendingDeletionRequests.' demande(s) de suppression ouverte(s)',
                'meta' => 'Décision Super Admin attendue',
                'tone' => 'warning',
                'route' => route('workspace.deletion-requests.index', ['status' => DeletionRequest::STATUS_PENDING]),
            ]);
        }

        if ($draftsTotal > 0) {
            $items->push([
                'label' => $draftsTotal.' brouillon(s) de configuration',
                'meta' => 'Publication ou abandon à décider',
                'tone' => 'info',
                'route' => $draftRoute ?? route('workspace.super-admin.appearance.edit'),
            ]);
        }

        if ($isTechnicalAdministrator && ($latestSnapshot === null || $latestSnapshot->created_at?->lt(now()->subDays(30)))) {
            $items->push([
                'label' => $latestSnapshot === null ? 'Aucun snapshot de configuration' : 'Snapshot de configuration ancien',
                'meta' => $latestSnapshot === null
                    ? 'Point de restauration absent'
                    : 'Dernier point créé il y a plus de 30 jours',
                'tone' => 'warning',
                'route' => route('workspace.super-admin.snapshots.index'),
            ]);
        }

        if ($draftTemplates > 0) {
            $items->push([
                'label' => $draftTemplates.' template(s) non publié(s)',
                'meta' => 'Brouillons disponibles',
                'tone' => 'info',
                'route' => route('workspace.super-admin.templates.index', ['status' => ExportTemplate::STATUS_DRAFT]),
            ]);
        }

        if ($items->isEmpty()) {
            $items->push([
                'label' => 'Aucune intervention prioritaire',
                'meta' => 'Contrôles, décisions et publications à jour',
                'tone' => 'success',
                'route' => $isTechnicalAdministrator
                    ? route('workspace.super-admin.audit-diagnostic.index')
                    : route('workspace.super-admin.templates.index'),
            ]);
        }

        return $items;
    }

    /** @return array<string, string|int> */
    private function configurationFacts(): array
    {
        $activeExercise = Exercice::query()
            ->where('is_active', true)
            ->orderByDesc('annee')
            ->first(['annee', 'libelle']);

        return [
            'official_base' => $this->actionCalculationSettings->statisticalScopeLabel(),
            'closure_threshold' => $this->actionManagementSettings->minProgressForClosure(),
            'default_theme' => ucfirst((string) $this->appearanceSettings->get('default_theme', 'dark')),
            'locale' => strtoupper($this->platformSettings->locale()),
            'timezone' => $this->platformSettings->timezone(),
            'active_exercise' => $activeExercise?->libelle ?: (string) ($activeExercise?->annee ?? 'Non défini'),
            'notification_events' => (int) ($this->notificationPolicySettings->summary()['events_enabled'] ?? 0),
            'timeline_rules' => (int) ($this->notificationPolicySettings->summary()['timeline_rules_enabled'] ?? 0),
        ];
    }

    /**
     * @param  array<string, int>  $summary
     * @return array<int, array{key:string,label:string,items:array<int, array{label:string,meta:string,route:string}>}>
     */
    private function areas(array $summary, bool $isTechnicalAdministrator): array
    {
        $areas = [
            [
                'key' => 'platform',
                'label' => 'Plateforme',
                'items' => [
                    ['label' => 'Paramètres généraux', 'meta' => $this->platformSettings->locale().' · '.$this->platformSettings->timezone(), 'route' => route('workspace.super-admin.settings.edit'), 'technical' => true],
                    ['label' => 'Apparence', 'meta' => ucfirst((string) $this->appearanceSettings->get('default_theme', 'dark')), 'route' => route('workspace.super-admin.appearance.edit')],
                    ['label' => 'Modules et navigation', 'meta' => $summary['modules_active'].' / '.$summary['modules_total'].' actifs', 'route' => route('workspace.super-admin.modules.edit'), 'technical' => true],
                    ['label' => 'Maintenance', 'meta' => 'Caches et exploitation', 'route' => route('workspace.super-admin.maintenance.index'), 'technical' => true],
                ],
            ],
            [
                'key' => 'governance',
                'label' => 'Gouvernance',
                'items' => [
                    ['label' => 'Rôles et permissions', 'meta' => count($this->rolePermissionSettings->groupedPermissions()).' groupes', 'route' => route('workspace.super-admin.roles.edit'), 'technical' => true],
                    ['label' => 'Organisation et utilisateurs', 'meta' => $summary['active_users'].' actifs', 'route' => route('workspace.super-admin.organization.index')],
                    ['label' => 'Unités DG', 'meta' => UniteDg::query()->where('actif', true)->count().' actives', 'route' => route('workspace.super-admin.unites-dg.index')],
                    ['label' => 'Dashboards par profil', 'meta' => count($this->dashboardProfileSettings->all()).' profils', 'route' => route('workspace.super-admin.dashboard-profiles.edit')],
                    ['label' => 'Audit et diagnostic', 'meta' => $summary['diagnostic_warnings'].' alerte(s)', 'route' => route('workspace.super-admin.audit-diagnostic.index'), 'technical' => true],
                    ['label' => 'Journal d’audit', 'meta' => 'Traçabilité globale', 'route' => route('workspace.audit.index')],
                ],
            ],
            [
                'key' => 'pilotage',
                'label' => 'Pilotage métier',
                'items' => [
                    ['label' => 'Workflow et validations', 'meta' => 'PAS · PAO · PTA · Actions', 'route' => route('workspace.super-admin.workflow.edit')],
                    ['label' => 'Exercices et périodes', 'meta' => 'Calendrier opérationnel', 'route' => route('workspace.super-admin.exercises.index')],
                    ['label' => 'Politique de calcul', 'meta' => $this->actionCalculationSettings->statisticalScopeLabel(), 'route' => route('workspace.super-admin.calculation.edit')],
                    ['label' => 'Paramètres Actions', 'meta' => 'Clôture à '.$this->actionManagementSettings->minProgressForClosure().' %', 'route' => route('workspace.super-admin.action-policies.edit')],
                    ['label' => 'Référentiels dynamiques', 'meta' => count($this->dynamicReferentialSettings->all()).' registres', 'route' => route('workspace.super-admin.referentials.edit')],
                    ['label' => 'Documents et justificatifs', 'meta' => 'Politique documentaire', 'route' => route('workspace.super-admin.documents.edit')],
                    ['label' => 'Indicateurs de performance', 'meta' => ((int) ($this->managedKpiSettings->summary()['visible'] ?? 0)).' visibles', 'route' => route('workspace.super-admin.kpis.edit')],
                    ['label' => 'Alertes et notifications', 'meta' => ((int) ($this->notificationPolicySettings->summary()['events_enabled'] ?? 0)).' événements actifs', 'route' => route('workspace.super-admin.notifications.edit')],
                ],
            ],
            [
                'key' => 'continuity',
                'label' => 'Continuité et sorties',
                'items' => [
                    ['label' => 'Snapshots de configuration', 'meta' => $summary['snapshots_total'].' point(s)', 'route' => route('workspace.super-admin.snapshots.index'), 'technical' => true],
                    ['label' => 'Simulation', 'meta' => 'Impact avant application', 'route' => route('workspace.super-admin.simulation.index'), 'technical' => true],
                    ['label' => 'Templates d’export', 'meta' => $summary['templates_published'].' publiés', 'route' => route('workspace.super-admin.templates.index')],
                    ['label' => 'Rétention et archivage', 'meta' => $summary['archives_total'].' archive(s)', 'route' => route('workspace.retention.index')],
                ],
            ],
        ];

        return collect($areas)
            ->map(function (array $area) use ($isTechnicalAdministrator): array {
                $area['items'] = collect($area['items'])
                    ->filter(fn (array $item): bool => $isTechnicalAdministrator || ! ($item['technical'] ?? false))
                    ->map(function (array $item): array {
                        unset($item['technical']);

                        return $item;
                    })
                    ->values()
                    ->all();

                return $area;
            })
            ->filter(fn (array $area): bool => $area['items'] !== [])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array{date:string,label:string,count:int}>
     */
    private function activity(): Collection
    {
        $start = now()->startOfDay()->subDays(6);
        $counts = JournalAudit::query()
            ->whereIn('module', self::AUDIT_MODULES)
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->countBy(fn (JournalAudit $audit): string => $audit->created_at->toDateString());

        return collect(range(6, 0))->map(function (int $daysAgo) use ($counts): array {
            $date = now()->startOfDay()->subDays($daysAgo);

            return [
                'date' => $date->toDateString(),
                'label' => $date->translatedFormat('D d'),
                'count' => (int) $counts->get($date->toDateString(), 0),
            ];
        });
    }

    /**
     * @return Collection<int, array{id:int,created_at:Carbon|null,actor:string,module:string,module_label:string,action:string,action_label:string}>
     */
    private function recentAudits(): Collection
    {
        return JournalAudit::query()
            ->with('user:id,name,email')
            ->whereIn('module', self::AUDIT_MODULES)
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(fn (JournalAudit $audit): array => [
                'id' => (int) $audit->id,
                'created_at' => $audit->created_at,
                'actor' => $audit->user?->name ?? 'Système',
                'module' => (string) $audit->module,
                'module_label' => match ((string) $audit->module) {
                    'super_admin' => 'Super Admin',
                    'export_template' => 'Templates',
                    'retention' => 'Rétention',
                    default => Str::headline((string) $audit->module),
                },
                'action' => (string) $audit->action,
                'action_label' => Str::headline((string) $audit->action),
            ]);
    }

    private function activeSessionsCount(): int
    {
        $table = (string) config('session.table', 'sessions');
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) Cache::remember(
            'super_admin:active_sessions_count',
            now()->addSeconds(30),
            fn (): int => (int) DB::table($table)->count()
        );
    }

    private function asDate(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
