<?php

namespace App\Services\Dashboard;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\User;
use App\Services\Scope\UserScopeService;
use App\Support\SchemaIntrospectionCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DashboardStatsService
{
    public function __construct(
        private readonly UserScopeService $scope
    ) {
    }

    public function getGlobalStats(User $user): array
    {
        return [
            ...$this->getPasStats($user),
            ...$this->getPaoStats($user),
            ...$this->getPtaStats($user),
            ...$this->getActionStats($user),
        ];
    }

    public function getPilotageStats(User $user): array
    {
        $actions = $this->scopedActions($user);

        return [
            'taux_global_avancement' => $this->average($actions, 'progression_reelle'),
            'objectifs_strategiques' => 0,
            'objectifs_operationnels' => 0,
            'indicateurs_sous_seuil' => 0,
            'alertes_critiques' => $this->criticalActiveAlerts($user),
            'ruptures_chaine' => 0,
            'actions_en_retard' => $this->delayedActions($actions),
        ];
    }

    public function getPasStats(User $user): array
    {
        $pas = $this->scope->applyToPas(Pas::query(), $user);

        return [
            'pas_total' => (clone $pas)->count(),
            'pas_actifs' => $this->countActive($pas),
            'axes_strategiques' => 0,
            'objectifs_strategiques' => 0,
            'taux_moyen_avancement_pas' => $this->average($pas, 'progression'),
        ];
    }

    public function getPaoStats(User $user): array
    {
        $pao = $this->scope->applyToPao(Pao::query(), $user);

        return [
            'pao_total' => (clone $pao)->count(),
            'pao_actifs' => $this->countActive($pao),
            'objectifs_operationnels' => 0,
            'actions_liees' => $this->scopedActions($user)->count(),
            'taux_moyen_avancement_pao' => $this->average($pao, 'progression'),
        ];
    }

    public function getPtaStats(User $user): array
    {
        $pta = $this->scope->applyToPta(Pta::query(), $user);
        $actions = $this->scopedActions($user);

        return [
            'pta_total' => (clone $pta)->count(),
            'pta_actifs' => $this->countActive($pta),
            'services_concernes' => $this->distinctCount($pta, 'service_id'),
            'actions_planifiees' => (clone $actions)->count(),
            'actions_en_retard' => $this->delayedActions($actions),
            'taux_moyen_execution' => $this->average($actions, 'progression_reelle'),
        ];
    }

    public function getActionStats(User $user): array
    {
        $actions = $this->scopedActions($user);

        return [
            'actions_totales' => (clone $actions)->count(),
            'actions_pilotees' => (clone $actions)->count(),
            'mes_actions' => $this->myActions($actions, $user),
            'actions_en_cours' => $this->countByStatus($actions, 'en_cours'),
            'actions_en_retard' => $this->delayedActions($actions),
            'actions_cloturees' => $this->countByStatus($actions, 'cloturee'),
            'validations_en_attente' => $this->pendingValidations($actions),
        ];
    }

    private function scopedActions(User $user): Builder
    {
        return $this->scope->applyToActions(Action::query(), $user);
    }

    private function average(Builder $query, string $column): float
    {
        if (! SchemaIntrospectionCache::hasColumn($query->getModel()->getTable(), $column)) {
            return 0.0;
        }

        return round((float) (clone $query)->avg($column), 2);
    }

    private function countActive(Builder $query): int
    {
        $table = $query->getModel()->getTable();
        $activeStatuses = match ($table) {
            'pas' => ['actif'],
            'paos' => ['en_cours', 'valide'],
            'ptas' => ['en_cours'],
            default => ['actif', 'en_cours', 'valide'],
        };

        foreach (['statut', 'status'] as $column) {
            if (SchemaIntrospectionCache::hasColumn($table, $column)) {
                return (clone $query)->whereIn($column, $activeStatuses)->count();
            }
        }

        return (clone $query)->count();
    }

    private function countByStatus(Builder $query, string $status): int
    {
        if (! SchemaIntrospectionCache::hasColumn($query->getModel()->getTable(), 'statut_dynamique')) {
            return 0;
        }

        return (clone $query)->where('statut_dynamique', $status)->count();
    }

    private function delayedActions(Builder $query): int
    {
        // A19 — Aligne sur la regle metier centrale du reporting :
        //   "en retard" = date_echeance/date_fin depassee + statut_dynamique
        //   PAS dans la liste des statuts terminaux (acheve, suspendu, annule,
        //   cloturee). Cela elimine la divergence entre dashboard (qui filtrait
        //   sur statut_dynamique='en_retard' uniquement) et reporting (qui
        //   regardait l echeance + l etat).
        $table = $query->getModel()->getTable();
        $completed = \App\Services\Actions\ActionTrackingService::completedActionStatuses();
        $today = Carbon::today();

        if (SchemaIntrospectionCache::hasColumn($table, 'date_echeance') && SchemaIntrospectionCache::hasColumn($table, 'statut_dynamique')) {
            return (clone $query)
                ->whereNotNull('date_echeance')
                ->whereDate('date_echeance', '<', $today)
                ->whereNotIn('statut_dynamique', $completed)
                ->count();
        }

        if (SchemaIntrospectionCache::hasColumn($table, 'statut_dynamique')) {
            return (clone $query)->where('statut_dynamique', 'en_retard')->count();
        }

        if (SchemaIntrospectionCache::hasColumn($table, 'date_fin')) {
            return (clone $query)
                ->whereDate('date_fin', '<', $today)
                ->count();
        }

        return 0;
    }

    private function pendingValidations(Builder $query): int
    {
        if (! SchemaIntrospectionCache::hasColumn($query->getModel()->getTable(), 'statut_validation')) {
            return 0;
        }

        return (clone $query)
            ->whereIn('statut_validation', ['soumise_chef', 'en_attente_validation'])
            ->count();
    }

    private function criticalActiveAlerts(User $user): int
    {
        return ActionLog::query()
            ->activeAlert()
            ->whereIn('niveau', ['critical', 'urgence'])
            ->whereHas('action', function (Builder $actionQuery) use ($user): void {
                $this->scope->applyToActions($actionQuery, $user);
            })
            ->count();
    }

    private function myActions(Builder $query, User $user): int
    {
        $table = $query->getModel()->getTable();

        foreach (['responsable_id', 'agent_id', 'user_id'] as $column) {
            if (SchemaIntrospectionCache::hasColumn($table, $column)) {
                return (clone $query)->where($column, $user->id)->count();
            }
        }

        return 0;
    }

    private function distinctCount(Builder $query, string $column): int
    {
        if (! SchemaIntrospectionCache::hasColumn($query->getModel()->getTable(), $column)) {
            return 0;
        }

        return (clone $query)->whereNotNull($column)->distinct($column)->count($column);
    }
}
