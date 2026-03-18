<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportingController extends Controller
{
    use AuthorizesPlanningScope;

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $today = Carbon::today()->toDateString();

        $pas = Pas::query();
        $paos = Pao::query();
        $ptas = Pta::query();
        $actions = Action::query();
        $kpis = Kpi::query();
        $mesures = KpiMesure::query();

        $this->scopePasByUser($pas, $user);
        $this->scopePao($paos, $user);
        $this->scopePta($ptas, $user);
        $this->scopeAction($actions, $user);
        $this->scopeKpi($kpis, $user);
        $this->scopeMesure($mesures, $user);
        $actionsStatistics = (clone $actions)
            ->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION);

        $retardsActions = (clone $actions)
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', $today)
            ->whereNotIn('statut_dynamique', ['acheve_dans_delai', 'acheve_hors_delai'])
            ->count();

        $kpiSousSeuil = KpiMesure::query()
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte');

        $this->scopeJoinedPta($kpiSousSeuil, $user, 'ptas.direction_id', 'ptas.service_id');

        return response()->json([
            'generated_at' => now(),
            'scope' => [
                'role' => $user->role,
                'direction_id' => $user->direction_id,
                'service_id' => $user->service_id,
            ],
            'global' => [
                'pas_total' => (clone $pas)->count(),
                'paos_total' => (clone $paos)->count(),
                'ptas_total' => (clone $ptas)->count(),
                'actions_total' => (clone $actions)->count(),
                'actions_validees' => (clone $actionsStatistics)->count(),
                'kpis_total' => (clone $kpis)->count(),
                'kpi_mesures_total' => (clone $mesures)->count(),
            ],
            'statuts' => [
                'pas' => $this->countByStatus($pas, 'statut'),
                'paos' => $this->countByStatus($paos, 'statut'),
                'ptas' => $this->countByStatus($ptas, 'statut'),
                'actions' => $this->countByStatus($actions, 'statut_dynamique'),
                'actions_validation' => $this->countByStatus($actions, 'statut_validation'),
            ],
            'alertes' => [
                'actions_en_retard' => $retardsActions,
                'mesures_kpi_sous_seuil' => $kpiSousSeuil->count(),
            ],
        ]);
    }

    private function scopePao(Builder $query, User $user): void
    {
        $this->scopeByUserDirection($query, $user, 'direction_id');
    }

    private function scopePta(Builder $query, User $user): void
    {
        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
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

    private function scopeKpi(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('action.pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('action.pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function scopeMesure(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('kpi.action.pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('kpi.action.pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
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
     * @return array<string, int>
     */
    private function countByStatus(Builder $query, string $statusColumn): array
    {
        /** @var array<string, int> $result */
        $result = (clone $query)
            ->selectRaw("{$statusColumn} as status_label, COUNT(*) as total")
            ->groupBy($statusColumn)
            ->pluck('total', 'status_label')
            ->map(fn ($value): int => (int) $value)
            ->toArray();

        return $result;
    }
}
