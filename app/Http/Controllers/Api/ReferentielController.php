<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferentielController extends Controller
{
    use AuthorizesPlanningScope;

    public function directions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $query = Direction::query()->orderBy('code');

        if (! $user->hasGlobalReadAccess() && $user->direction_id !== null) {
            $query->where('id', (int) $user->direction_id);
        }

        $query->when(
            $request->boolean('actif_only', true),
            fn ($q) => $q->where('actif', true)
        );

        return response()->json([
            'data' => $query->get(['id', 'code', 'libelle', 'actif']),
        ]);
    }

    public function services(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $query = Service::query()
            ->with('direction:id,code,libelle')
            ->orderBy('direction_id')
            ->orderBy('code');

        if (! $user->hasGlobalReadAccess() && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where('id', (int) $user->service_id);
        }

        $query->when(
            $request->filled('direction_id'),
            fn ($q) => $q->where('direction_id', (int) $request->integer('direction_id'))
        );

        $query->when(
            $request->boolean('actif_only', true),
            fn ($q) => $q->where('actif', true)
        );

        return response()->json([
            'data' => $query->get(['id', 'direction_id', 'code', 'libelle', 'actif']),
        ]);
    }

    public function utilisateurs(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasAnyPermission('users.manage', 'users.manage_roles')) {
            abort(403, 'Acces non autorise.');
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $query = User::query()
            ->with([
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
            ])
            ->orderBy('name');

        if (! $user->isSuperAdmin()) {
            $query->where('role', '!=', User::ROLE_SUPER_ADMIN);
        }

        if (! $user->hasGlobalReadAccess()) {
            if ($user->direction_id !== null) {
                $query->where('direction_id', (int) $user->direction_id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where('service_id', (int) $user->service_id);
        }

        $query->when(
            $request->filled('direction_id'),
            fn ($q) => $q->where('direction_id', (int) $request->integer('direction_id'))
        );

        $query->when(
            $request->filled('service_id'),
            fn ($q) => $q->where('service_id', (int) $request->integer('service_id'))
        );

        $query->when(
            $request->filled('role'),
            fn ($q) => $q->where('role', (string) $request->string('role'))
        );
        $query->when(
            $request->filled('is_active'),
            fn ($q) => $q->where('is_active', $request->string('is_active') === '1')
        );
        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        });

        return response()->json(
            $query->paginate($perPage, [
                'id',
                'name',
                'email',
                'role',
                'is_active',
                'agent_matricule',
                'agent_fonction',
                'agent_telephone',
                'direction_id',
                'service_id',
            ])->withQueryString()
        );
    }
}
