<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\Direction;
use App\Models\ObjectifOperationnel;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DependentSelectController extends Controller
{
    use AuthorizesPlanningScope;

    public function services(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $directionId = $request->integer('direction_id') ?: null;

        $query = Service::query()
            ->where('actif', true)
            ->orderBy('code')
            ->orderBy('libelle');

        if ($directionId !== null) {
            $query->where('direction_id', $directionId);
        }

        $this->scopeByUserDirection($query, $user, 'direction_id', 'id');

        return response()->json($query->get(['id', 'direction_id', 'code', 'libelle']));
    }

    public function servicesByDirection(Request $request, Direction $direction): JsonResponse
    {
        $request->merge(['direction_id' => (int) $direction->id]);

        return $this->services($request);
    }

    public function users(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $directionId = $request->integer('direction_id') ?: null;
        $serviceId = $request->integer('service_id') ?: null;

        // A13 — Defense en profondeur : refuser l enumeration des users si
        // l appelant n a pas de portee globale ET pas de rattachement direction.
        // Sans ce garde-fou, un user incompletement rattache (direction_id null)
        // recevrait tous les utilisateurs actifs de la plateforme via /ajax/users.
        if (! $user->hasGlobalReadAccess() && $user->direction_id === null) {
            abort(403, 'Acces non autorise (perimetre indetermine).');
        }

        $query = User::query()
            ->where('is_active', true)
            ->orderBy('name');

        if ($directionId !== null) {
            $query->where('direction_id', $directionId);
        }

        if ($serviceId !== null) {
            $query->where('service_id', $serviceId);
        }

        if (! $user->hasGlobalReadAccess() && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if ($user->hasRole(User::ROLE_SERVICE, User::ROLE_AGENT) && $user->service_id !== null) {
            $query->where('service_id', (int) $user->service_id);
        }

        return response()->json($query->get(['id', 'name', 'email', 'role', 'direction_id', 'service_id']));
    }

    public function objectifsOperationnels(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $paoId = $request->integer('pao_id') ?: null;
        $objectifStrategiqueId = $request->integer('pas_objectif_id') ?: null;
        $directionId = $request->integer('direction_id') ?: null;
        $serviceId = $request->integer('service_id') ?: null;

        $query = ObjectifOperationnel::query()
            ->with(['service:id,code,libelle,direction_id'])
            ->orderByDesc('id');

        if ($paoId !== null) {
            $query->where('pao_id', $paoId);
        }

        if ($objectifStrategiqueId !== null) {
            $query->where('pas_objectif_id', $objectifStrategiqueId);
        }

        if ($directionId !== null) {
            $query->where('direction_id', $directionId);
        }

        if ($serviceId !== null) {
            $query->where('service_id', $serviceId);
        }

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');

        return response()->json($query->get([
            'id',
            'pao_id',
            'pas_objectif_id',
            'direction_id',
            'service_id',
            'libelle',
            'echeance',
        ]));
    }

    public function ptas(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $paoId = $request->integer('pao_id') ?: null;
        $objectifId = $request->integer('objectif_operationnel_id') ?: null;
        $serviceId = $request->integer('service_id') ?: null;

        $query = Pta::query()
            ->with(['direction:id,code,libelle', 'service:id,code,libelle'])
            ->orderByDesc('id');

        if ($paoId !== null) {
            $query->where('pao_id', $paoId);
        }

        if ($objectifId !== null) {
            $query->where('objectif_operationnel_id', $objectifId);
        }

        if ($serviceId !== null) {
            $query->where('service_id', $serviceId);
        }

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');

        return response()->json($query->get(['id', 'pao_id', 'objectif_operationnel_id', 'direction_id', 'service_id', 'titre']));
    }

    public function actions(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $ptaId = $request->integer('pta_id') ?: null;
        $objectifId = $request->integer('objectif_operationnel_id') ?: null;

        $query = Action::query()
            ->whereNotNull('pta_id')
            ->orderByDesc('id');

        if ($ptaId !== null) {
            $query->where('pta_id', $ptaId);
        }

        if ($objectifId !== null) {
            $query->where('objectif_operationnel_id', $objectifId);
        }

        if ($user->isAgent()) {
            $query->where(function (Builder $agentQuery) use ($user): void {
                $agentQuery->where('responsable_id', (int) $user->id);

                if (Schema::hasTable('action_responsables')) {
                    $agentQuery->orWhereHas('responsables', fn (Builder $responsableQuery) => $responsableQuery->whereKey((int) $user->id));
                }
            });
        } elseif (! $user->hasGlobalReadAccess()) {
            $query->whereHas('pta', function (Builder $ptaQuery) use ($user): void {
                $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
            });
        }

        return response()->json($query->get(['id', 'pta_id', 'objectif_operationnel_id', 'libelle', 'date_debut', 'date_fin']));
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
