<?php

namespace App\Services;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\ExportTemplateAssignment;
use App\Models\JournalAudit;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\Justificatif;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PlatformDiagnosticService
{
    public function __construct(
        private readonly WorkspaceModuleSettings $workspaceModuleSettings
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function auditSummary(): array
    {
        $since = Carbon::now()->subDay();

        return [
            'logs_total' => JournalAudit::query()->count(),
            'logs_last_24h' => JournalAudit::query()->where('created_at', '>=', $since)->count(),
            'super_admin_changes' => JournalAudit::query()->where('module', 'super_admin')->count(),
            'sensitive_changes' => JournalAudit::query()
                ->where(function ($query): void {
                    $query->where('module', 'super_admin')
                        ->orWhere('action', 'like', 'maintenance_%')
                        ->orWhere('action', 'like', '%permission%')
                        ->orWhere('action', 'like', '%workflow%');
                })
                ->count(),
            'organization_actions' => JournalAudit::query()
                ->where('action', 'like', 'organization_%')
                ->count(),
            'modules_touched' => JournalAudit::query()
                ->distinct('module')
                ->count('module'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function checks(): array
    {
        $rows = [
            $this->makeCheck(
                'actions_without_weeks',
                'Actions sans periodes de suivi',
                Action::query()->doesntHave('weeks')->count(),
                'Les actions sans semaines ne remontent pas correctement dans le suivi.'
            ),
            $this->makeCheck(
                'actions_without_responsable',
                'Actions sans responsable',
                Action::query()->whereNull('responsable_id')->count(),
                'Une action sans responsable ne peut pas etre pilotee proprement.'
            ),
            $this->makeCheck(
                'paos_without_pta',
                'PAO sans PTA',
                Pao::query()->doesntHave('ptas')->count(),
                'Un PAO non decliné en PTA reste strategique mais n est pas encore mis en execution.'
            ),
            $this->makeCheck(
                'ptas_without_service',
                'PTA sans service',
                Pta::query()->whereNull('service_id')->count(),
                'Un PTA sans service casse le perimetre d execution.'
            ),
            $this->makeCheck(
                'critical_alerts_unread',
                'Alertes critiques non lues',
                ActionLog::query()
                    ->whereIn('niveau', ['critical', 'urgence'])
                    ->where('lu', false)
                    ->count(),
                'Ces alertes doivent etre traitees ou accusees de lecture.'
            ),
            $this->makeCheck(
                'actions_without_pta',
                'Actions sans PTA',
                Action::query()->whereNull('pta_id')->count(),
                'Une action sans PTA ne peut pas etre consolidee correctement.'
            ),
            $this->makeCheck(
                'users_scope_mismatch',
                'Utilisateurs avec scope incoherent',
                User::query()
                    ->whereNotNull('service_id')
                    ->whereHas('service', fn ($query) => $query->whereColumn('services.direction_id', '!=', 'users.direction_id'))
                    ->count(),
                'Le service rattache doit appartenir a la meme direction que le compte.'
            ),
            $this->makeCheck(
                'inactive_responsables_on_open_actions',
                'Actions ouvertes avec responsable inactif ou suspendu',
                Action::query()
                    ->whereHas('responsable', function ($query): void {
                        $query->where(function ($subQuery): void {
                            $subQuery->where('is_active', false)
                                ->orWhereNotNull('suspended_until');
                        });
                    })
                    ->whereNull('date_fin_reelle')
                    ->count(),
                'Ces actions doivent etre reaffectees ou regularisees.'
            ),
            $this->makeCheck(
                'justificatifs_without_file',
                'Justificatifs avec fichier manquant',
                Justificatif::query()
                    ->get(['id', 'chemin_stockage'])
                    ->filter(fn (Justificatif $justificatif): bool => ! Storage::exists((string) $justificatif->chemin_stockage))
                    ->count(),
                'Le stockage documentaire contient des references orphelines.'
            ),
            $this->makeCheck(
                'duplicate_default_template_assignments',
                'Affectations par defaut en doublon',
                (int) ExportTemplateAssignment::query()
                    ->selectRaw('COUNT(*) as duplicates')
                    ->fromSub(function ($query): void {
                        $query->from('export_template_assignments')
                            ->selectRaw('module, report_type, format, COALESCE(target_profile, \'\') as target_profile, COALESCE(reading_level, \'\') as reading_level, COALESCE(direction_id, 0) as direction_id, COALESCE(service_id, 0) as service_id, COUNT(*) as total')
                            ->where('is_active', true)
                            ->where('is_default', true)
                            ->groupByRaw('module, report_type, format, COALESCE(target_profile, \'\'), COALESCE(reading_level, \'\'), COALESCE(direction_id, 0), COALESCE(service_id, 0)')
                            ->havingRaw('COUNT(*) > 1');
                    }, 'duplicates')
                    ->count(),
                'Une meme resolution d export ne doit avoir qu un seul template par defaut actif.'
            ),
            [
                'code' => 'disabled_modules',
                'label' => 'Modules desactives',
                'count' => count(array_filter(
                    $this->workspaceModuleSettings->configuredModules(),
                    static fn (array $module): bool => ! (bool) ($module['enabled'] ?? false)
                )),
                'status' => 'info',
                'recommendation' => 'Controle de configuration globale du workspace.',
            ],
        ];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function filteredAuditSummary(array $filters = []): array
    {
        $query = JournalAudit::query();

        if (($filters['module'] ?? '') !== '') {
            $query->where('module', (string) $filters['module']);
        }

        if (($filters['action'] ?? '') !== '') {
            $query->where('action', (string) $filters['action']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (($filters['entite_type'] ?? '') !== '') {
            $query->where('entite_type', (string) $filters['entite_type']);
        }

        if (! empty($filters['entite_id'])) {
            $query->where('entite_id', (int) $filters['entite_id']);
        }

        if (($filters['date_from'] ?? '') !== '') {
            $query->whereDate('created_at', '>=', (string) $filters['date_from']);
        }

        if (($filters['date_to'] ?? '') !== '') {
            $query->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        if (($filters['q'] ?? '') !== '') {
            $search = trim((string) $filters['q']);
            $query->where(function ($subQuery) use ($search): void {
                $subQuery->where('module', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('entite_type', 'like', "%{$search}%");
            });
        }

        $countQuery = clone $query;
        $distinctUsers = (clone $query)
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');
        $superAdminActions = (clone $query)
            ->where('module', 'super_admin')
            ->count();
        $sensitiveActions = (clone $query)
            ->where(function ($sensitiveQuery): void {
                $sensitiveQuery->where('module', 'super_admin')
                    ->orWhere('action', 'like', 'maintenance_%')
                    ->orWhere('action', 'like', '%permission%')
                    ->orWhere('action', 'like', '%workflow%');
            })
            ->count();
        $organizationActions = (clone $query)
            ->where('action', 'like', 'organization_%')
            ->count();
        $modulesTouched = (clone $query)
            ->distinct('module')
            ->count('module');

        return [
            'total' => $countQuery->count(),
            'distinct_users' => $distinctUsers,
            'super_admin_actions' => $superAdminActions,
            'sensitive_actions' => $sensitiveActions,
            'organization_actions' => $organizationActions,
            'modules_touched' => $modulesTouched,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeCheck(string $code, string $label, int $count, string $recommendation): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'count' => $count,
            'status' => $count > 0 ? 'warning' : 'ok',
            'recommendation' => $recommendation,
        ];
    }
}
