<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\Pta;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PilotageHierarchyController extends Controller
{
    use AuthorizesPlanningScope;

    public function __invoke(Request $request): View
    {
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
                    'average_progress' => round((float) $pasPtas->avg('actions_avg_progression_reelle'), 1),
                    'paos' => $pasPtas
                        ->groupBy(fn (Pta $pta): int => (int) ($pta->pao?->id ?? 0))
                        ->map(function ($paoPtas): array {
                            /** @var Collection<int, Pta> $paoPtas */
                            $first = $paoPtas->first();

                            return [
                                'pao' => $first?->pao,
                                'ptas_count' => $paoPtas->count(),
                                'actions_count' => (int) $paoPtas->sum('actions_count'),
                                'average_progress' => round((float) $paoPtas->avg('actions_avg_progression_reelle'), 1),
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
                'average_progress' => round((float) $ptas->avg('actions_avg_progression_reelle'), 1),
            ],
        ]);
    }
}
