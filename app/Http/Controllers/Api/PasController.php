<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePasRequest;
use App\Http\Requests\UpdatePasRequest;
use App\Models\Pas;
use App\Models\User;
use App\Services\PasStructureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasController extends Controller
{
    use AuthorizesPlanningScope;
    use RecordsAuditTrail;

    public function __construct(
        private readonly PasStructureService $pasStructureService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->authorize('viewAny', Pas::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));

        $query = Pas::query()
            ->with(['validateur:id,name,email'])
            ->withCount(['axes', 'directions', 'paos']);

        $this->scopePasByUser($query, $user);

        $query->when(
            $request->filled('statut'),
            fn ($q) => $q->where('statut', (string) $request->string('statut'))
        );

        $query->when(
            $request->filled('periode_debut'),
            fn ($q) => $q->where('periode_debut', (int) $request->integer('periode_debut'))
        );

        $query->when(
            $request->filled('periode_fin'),
            fn ($q) => $q->where('periode_fin', (int) $request->integer('periode_fin'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));

            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('titre', 'like', "%{$search}%");
            });
        });

        $result = $query
            ->orderByDesc('periode_debut')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($result);
    }

    public function store(StorePasRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->authorize('create', Pas::class);

        $validated = $request->validated();
        $statut = (string) ($validated['statut'] ?? 'brouillon');
        $payload = [
            'titre' => (string) $validated['titre'],
            'periode_debut' => (int) $validated['periode_debut'],
            'periode_fin' => (int) $validated['periode_fin'],
            'statut' => $statut,
            'created_by' => $user->id,
            'valide_le' => in_array($statut, ['valide', 'verrouille'], true) ? now() : null,
            'valide_par' => in_array($statut, ['valide', 'verrouille'], true) ? $user->id : null,
        ];

        $pas = DB::transaction(function () use ($validated, $payload, $user): Pas {
            $pas = Pas::query()->create($payload);
            $this->pasStructureService->sync(
                $pas,
                is_array($validated['axes'] ?? null) ? $validated['axes'] : [],
                $user->id,
            );

            return $pas;
        });

        $after = $pas->load([
            'validateur:id,name,email',
            'directions:id,code,libelle',
            'axes' => fn ($query) => $query
                ->orderBy('ordre')
                ->orderBy('id')
                ->with('direction:id,code,libelle')
                ->with('objectifs:id,pas_axe_id,code,libelle,description,indicateur_global,valeur_cible,valeurs_cible'),
        ])->toArray();
        $this->recordAudit($request, 'pas', 'create', $pas, null, $after);

        return response()->json([
            'message' => 'PAS cree avec succes.',
            'data' => $after,
        ], 201);
    }

    public function show(Request $request, Pas $pas): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->authorize('view', $pas);

        return response()->json([
            'data' => $pas->load([
                'validateur:id,name,email',
                'directions:id,code,libelle',
                'axes' => fn ($query) => $query
                    ->orderBy('ordre')
                    ->orderBy('id')
                    ->with('direction:id,code,libelle')
                    ->with('objectifs:id,pas_axe_id,code,libelle,description,indicateur_global,valeur_cible,valeurs_cible'),
            ]),
        ]);
    }

    public function update(UpdatePasRequest $request, Pas $pas): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->authorize('update', $pas);

        if ($pas->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PAS est verrouille et ne peut plus etre modifie.',
            ], 409);
        }

        $validated = $request->validated();
        $statut = (string) ($validated['statut'] ?? 'brouillon');
        $payload = [
            'titre' => (string) $validated['titre'],
            'periode_debut' => (int) $validated['periode_debut'],
            'periode_fin' => (int) $validated['periode_fin'],
            'statut' => $statut,
            'valide_le' => in_array($statut, ['valide', 'verrouille'], true) ? now() : null,
            'valide_par' => in_array($statut, ['valide', 'verrouille'], true) ? $user->id : null,
        ];

        $before = $pas->load([
            'validateur:id,name,email',
            'directions:id,code,libelle',
            'axes' => fn ($query) => $query
                ->orderBy('ordre')
                ->orderBy('id')
                ->with('direction:id,code,libelle')
                ->with('objectifs:id,pas_axe_id,code,libelle,description,indicateur_global,valeur_cible'),
        ])->toArray();

        DB::transaction(function () use ($pas, $payload, $validated, $user): void {
            $pas->update($payload);
            $this->pasStructureService->sync(
                $pas,
                is_array($validated['axes'] ?? null) ? $validated['axes'] : [],
                $user->id,
            );
        });

        $pas->refresh();
        $after = $pas->load([
            'validateur:id,name,email',
            'directions:id,code,libelle',
            'axes' => fn ($query) => $query
                ->orderBy('ordre')
                ->orderBy('id')
                ->with('direction:id,code,libelle')
                ->with('objectifs:id,pas_axe_id,code,libelle,description,indicateur_global,valeur_cible,valeurs_cible'),
        ])->toArray();

        $this->recordAudit($request, 'pas', 'update', $pas, $before, $after);

        return response()->json([
            'message' => 'PAS mis a jour avec succes.',
            'data' => $after,
        ]);
    }

    public function destroy(Request $request, Pas $pas): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->authorize('delete', $pas);

        if ($pas->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PAS est verrouille et ne peut pas etre supprime.',
            ], 409);
        }

        $before = $pas->toArray();
        $pas->delete();

        $this->recordAudit($request, 'pas', 'delete', $pas, $before, null);

        return response()->json([], 204);
    }
}
