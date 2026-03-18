<?php

namespace App\Services\Alerting;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\KpiMesure;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AlertDigestBuilder
{
    /**
     * @return array{
     *     generated_at: \Illuminate\Support\Carbon,
     *     scope: array{role: string, direction_id: int|null, service_id: int|null},
     *     actions_retard: Collection<int, \App\Models\Action>,
     *     kpi_sous_seuil: Collection<int, \App\Models\KpiMesure>,
     *     action_logs: Collection<int, \App\Models\ActionLog>,
     *     totals: array{actions_retard: int, kpi_sous_seuil: int, action_logs: int, total_alertes: int}
     * }
     */
    public function buildForUser(User $user, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        if (! $this->supportsUser($user)) {
            return $this->emptyDigest($user);
        }

        $today = Carbon::today()->toDateString();

        $actionsQuery = Action::query()
            ->with(['pta:id,direction_id,service_id,titre', 'responsable:id,name,email'])
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', $today)
            ->whereNotIn('statut_dynamique', ['acheve_dans_delai', 'acheve_hors_delai']);
        $this->scopeAction($actionsQuery, $user);

        $kpiMesureIds = $this->kpiSousSeuilIds($user, $limit);

        $kpiMesures = KpiMesure::query()
            ->with([
                'kpi:id,action_id,libelle,seuil_alerte,periodicite',
                'kpi.action:id,pta_id,libelle,responsable_id',
                'kpi.action.pta:id,direction_id,service_id,titre',
            ])
            ->whereIn('id', $kpiMesureIds)
            ->orderByDesc('id')
            ->get();

        $actions = $actionsQuery->orderBy('date_echeance')->limit($limit)->get();
        $logs = $this->actionLogAlerts($user, $limit);

        $totals = [
            'actions_retard' => $actions->count(),
            'kpi_sous_seuil' => $kpiMesures->count(),
            'action_logs' => $logs->count(),
        ];
        $totals['total_alertes'] = $totals['actions_retard']
            + $totals['kpi_sous_seuil']
            + $totals['action_logs'];

        return [
            'generated_at' => now(),
            'scope' => [
                'role' => $user->role,
                'direction_id' => $user->direction_id !== null ? (int) $user->direction_id : null,
                'service_id' => $user->service_id !== null ? (int) $user->service_id : null,
            ],
            'actions_retard' => $actions,
            'kpi_sous_seuil' => $kpiMesures,
            'action_logs' => $logs,
            'totals' => $totals,
        ];
    }

    public function supportsUser(User $user): bool
    {
        if ($user->hasRole(User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION)) {
            return true;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            return true;
        }

        return $user->hasRole(User::ROLE_SERVICE)
            && $user->direction_id !== null
            && $user->service_id !== null;
    }

    /**
     * @return array{
     *     generated_at: \Illuminate\Support\Carbon,
     *     scope: array{role: string, direction_id: int|null, service_id: int|null},
     *     actions_retard: Collection<int, \App\Models\Action>,
     *     kpi_sous_seuil: Collection<int, \App\Models\KpiMesure>,
     *     action_logs: Collection<int, \App\Models\ActionLog>,
     *     totals: array{actions_retard: int, kpi_sous_seuil: int, action_logs: int, total_alertes: int}
     * }
     */
    private function emptyDigest(User $user): array
    {
        return [
            'generated_at' => now(),
            'scope' => [
                'role' => $user->role,
                'direction_id' => $user->direction_id !== null ? (int) $user->direction_id : null,
                'service_id' => $user->service_id !== null ? (int) $user->service_id : null,
            ],
            'actions_retard' => collect(),
            'kpi_sous_seuil' => collect(),
            'action_logs' => collect(),
            'totals' => [
                'actions_retard' => 0,
                'kpi_sous_seuil' => 0,
                'action_logs' => 0,
                'total_alertes' => 0,
            ],
        ];
    }

    /**
     * @return array<int, int>
     */
    private function kpiSousSeuilIds(User $user, int $limit): array
    {
        $query = KpiMesure::query()
            ->select('kpi_mesures.id')
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte');

        $this->scopeJoinedPta($query, $user, 'ptas.direction_id', 'ptas.service_id');

        return $query
            ->orderByDesc('kpi_mesures.id')
            ->limit($limit)
            ->pluck('kpi_mesures.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function scopeAction(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function scopeJoinedPta(
        Builder $query,
        User $user,
        string $directionColumn,
        string $serviceColumn
    ): void {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->where($directionColumn, (int) $user->direction_id);
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where($serviceColumn, (int) $user->service_id);
            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * @return Collection<int, ActionLog>
     */
    private function actionLogAlerts(User $user, int $limit): Collection
    {
        $query = ActionLog::query()
            ->with([
                'action:id,pta_id,libelle,statut_dynamique',
                'action.pta:id,direction_id,service_id,titre',
                'week:id,action_id,numero_semaine',
            ])
            ->whereIn('niveau', ['warning', 'critical']);

        if (! $user->hasGlobalReadAccess()) {
            if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
                $query->whereHas('action.pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            } elseif ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
                $query->whereHas('action.pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->latest()->limit($limit)->get();
    }
}
