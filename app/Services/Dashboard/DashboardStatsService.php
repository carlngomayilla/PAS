<?php

namespace App\Services\Dashboard;

use App\Models\Action;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\User;
use App\Services\Scope\UserScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

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
            'alertes_critiques' => 0,
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
        if (! Schema::hasColumn($query->getModel()->getTable(), $column)) {
            return 0.0;
        }

        return round((float) (clone $query)->avg($column), 2);
    }

    private function countActive(Builder $query): int
    {
        $table = $query->getModel()->getTable();

        foreach (['statut', 'status'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                return (clone $query)->whereIn($column, ['actif', 'valide', 'verrouille', 'en_cours'])->count();
            }
        }

        return (clone $query)->count();
    }

    private function countByStatus(Builder $query, string $status): int
    {
        if (! Schema::hasColumn($query->getModel()->getTable(), 'statut_dynamique')) {
            return 0;
        }

        return (clone $query)->where('statut_dynamique', $status)->count();
    }

    private function delayedActions(Builder $query): int
    {
        $table = $query->getModel()->getTable();

        if (Schema::hasColumn($table, 'statut_dynamique')) {
            return (clone $query)->where('statut_dynamique', 'en_retard')->count();
        }

        if (Schema::hasColumn($table, 'date_fin')) {
            return (clone $query)->whereDate('date_fin', '<', Carbon::today())->count();
        }

        return 0;
    }

    private function pendingValidations(Builder $query): int
    {
        if (! Schema::hasColumn($query->getModel()->getTable(), 'statut_validation')) {
            return 0;
        }

        return (clone $query)
            ->whereIn('statut_validation', ['soumise_chef', 'soumise_direction', 'en_attente_validation'])
            ->count();
    }

    private function myActions(Builder $query, User $user): int
    {
        $table = $query->getModel()->getTable();

        foreach (['responsable_id', 'agent_id', 'user_id'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                return (clone $query)->where($column, $user->id)->count();
            }
        }

        return 0;
    }

    private function distinctCount(Builder $query, string $column): int
    {
        if (! Schema::hasColumn($query->getModel()->getTable(), $column)) {
            return 0;
        }

        return (clone $query)->whereNotNull($column)->distinct($column)->count($column);
    }
}
