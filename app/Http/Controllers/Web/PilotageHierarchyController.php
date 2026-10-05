<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\Pta;
use App\Models\User;
use App\Services\Dashboard\DashboardFilterContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PilotageHierarchyController extends Controller
{
    use AuthorizesPlanningScope;

    public function __construct(
        private readonly DashboardFilterContext $dashboardFilterContext,
    ) {}

    public function __invoke(Request $request): View
    {
        $this->dashboardFilterContext->useRequest($request);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $ptaQuery = Pta::query()
            ->with([
                'pao:id,pas_id,pas_objectif_id,annee,titre,statut',
                'pao.pas:id,titre,periode_debut,periode_fin,statut',
                'pao.pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'pao.pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
                'objectifOperationnel:id,pao_id,pas_objectif_id,service_id,libelle,statut,echeance',
                'direction:id,code,libelle',
                'service:id,code,libelle',
                'actions:id,pta_id,libelle,statut,progression_reelle,date_echeance',
            ])
            ->withCount('actions')
            ->withAvg('actions', 'progression_reelle')
            ->orderByDesc('id');

        $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        $actionRouteFilters = $this->dashboardFilterContext->actionRouteFilters($user);
        $dashboardRouteFilters = $this->dashboardFilterContext->dashboardRouteFilters($user);
        $directionContext = $this->dashboardFilterContext->directionContext($user);
        $synthesisFilters = $this->dashboardFilterContext->synthesisFilters();

        if (($actionRouteFilters['annee'] ?? null) !== null) {
            $ptaQuery->whereHas(
                'pao',
                fn (Builder $query): Builder => $query->where('annee', (int) $actionRouteFilters['annee'])
            );
        }

        if (($actionRouteFilters['direction_id'] ?? null) !== null) {
            $ptaQuery->where('direction_id', (int) $actionRouteFilters['direction_id']);
        }

        if (($actionRouteFilters['service_id'] ?? null) !== null) {
            $ptaQuery->where('service_id', (int) $actionRouteFilters['service_id']);
        }

        $ptas = $ptaQuery->limit(120)->get();
        $tree = $ptas
            ->groupBy(fn (Pta $pta): int => (int) ($pta->pao?->pas?->id ?? 0))
            ->map(function ($pasPtas): array {
                /** @var Collection<int, Pta> $pasPtas */
                $first = $pasPtas->first();

                return [
                    'pas' => $first?->pao?->pas,
                    'ptas_count' => $pasPtas->count(),
                    'actions_count' => (int) $pasPtas->sum('actions_count'),
                    'average_progress' => $this->weightedAverageProgress($pasPtas),
                    'paos' => $pasPtas
                        ->groupBy(fn (Pta $pta): int => (int) ($pta->pao?->id ?? 0))
                        ->map(function ($paoPtas): array {
                            /** @var Collection<int, Pta> $paoPtas */
                            $first = $paoPtas->first();

                            return [
                                'pao' => $first?->pao,
                                'ptas_count' => $paoPtas->count(),
                                'actions_count' => (int) $paoPtas->sum('actions_count'),
                                'average_progress' => $this->weightedAverageProgress($paoPtas),
                                'ptas' => $paoPtas->values(),
                            ];
                        })
                        ->values(),
                ];
            })
            ->values();

        return view('workspace.pilotage.index', [
            'tree' => $tree,
            'summary' => [
                'pas_total' => $tree->count(),
                'pao_total' => $tree->sum(fn (array $pas): int => $pas['paos']->count()),
                'pta_total' => $ptas->count(),
                'actions_total' => (int) $ptas->sum('actions_count'),
                'average_progress' => $this->weightedAverageProgress($ptas),
            ],
            'actionRouteFilters' => $actionRouteFilters,
            'dashboardRouteFilters' => $dashboardRouteFilters,
            'directionContext' => $directionContext,
            'synthesisFilters' => $synthesisFilters,
            'periodLabel' => (string) ($synthesisFilters['periode_label'] ?? 'Toutes périodes'),
        ]);
    }

    /**
     * @param  Collection<int, Pta>  $ptas
     */
    private function weightedAverageProgress(Collection $ptas): float
    {
        $actionsCount = (int) $ptas->sum('actions_count');
        if ($actionsCount <= 0) {
            return 0.0;
        }

        $weightedProgress = $ptas->sum(
            static fn (Pta $pta): float => (float) ($pta->actions_avg_progression_reelle ?? 0) * (int) $pta->actions_count
        );

        return round($weightedProgress / $actionsCount, 1);
    }
}
