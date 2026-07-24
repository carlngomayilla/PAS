<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Organization\OrganizationDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferentielController extends Controller
{
    use AuthorizesPlanningScope;

    public function __construct(
        private readonly OrganizationDirectoryService $organizationDirectoryService
    ) {}

    public function directions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $input = $request->query();
        $input['actif'] = $request->boolean('actif_only', true) ? '1' : '';
        $filters = $this->organizationDirectoryService->normalizeDirectionFilters($input);
        $query = $this->organizationDirectoryService
            ->directionQuery($user, $filters)
            ->orderBy('code');

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

        $input = $request->query();
        $input['actif'] = $request->boolean('actif_only', true) ? '1' : '';
        $filters = $this->organizationDirectoryService->normalizeServiceFilters($input);
        $query = $this->organizationDirectoryService
            ->serviceQuery($user, $filters)
            ->orderBy('direction_id')
            ->orderBy('code');

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

        $input = $request->query();
        $filters = $this->organizationDirectoryService->normalizeUserFilters($input);
        $requestedPerPage = is_scalar($input['per_page'] ?? null)
            ? filter_var($input['per_page'], FILTER_VALIDATE_INT)
            : false;
        $filters['per_page'] = $requestedPerPage !== false
            ? max(1, min(100, (int) $requestedPerPage))
            : 20;
        $query = $this->organizationDirectoryService->userQuery($user, $filters);

        return response()->json(
            $this->organizationDirectoryService->paginateUsers($query, $filters, [
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
            ])
        );
    }
}
