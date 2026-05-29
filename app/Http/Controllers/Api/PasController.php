<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePasRequest;
use App\Http\Requests\UpdatePasRequest;
use App\Models\Pas;
use App\Models\User;
use App\Services\PasStructureService;
use App\Services\PlanningModificationLockService;
use App\Support\UiLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasController extends Controller
{
    use AuthorizesPlanningScope;
    use FormatsWorkflowMessages;
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
        // Le PAS est deja valide officiellement avant saisie : l'API l'enregistre actif.
        $payload = [
            'titre' => (string) $validated['titre'],
            'periode_debut' => (int) $validated['periode_debut'],
            'periode_fin' => (int) $validated['periode_fin'],
            'created_by' => $user->id,
        ];

        $pas = DB::transaction(function () use ($validated, $payload, $user): Pas {
            $pas = new Pas();
            $pas->fill($payload);
            $pas->forceFill([
                'statut' => Pas::STATUS_ACTIF,
                'valide_le' => null,
                'valide_par' => null,
            ])->save();
            $this->pasStructureService->sync(
                $pas,
                is_array($validated['axes'] ?? null) ? $validated['axes'] : [],
                $user->id,
            );

            return $pas;
        });
        app(PlanningModificationLockService::class)->lockAfterSave($pas, $user);

        $after = $pas->load([
            'validateur:id,name,email',
            'directions:id,code,libelle',
            'axes' => fn ($query) => $query
                ->orderBy('ordre')
                ->orderBy('id')
                ->with('direction:id,code,libelle')
                ->with('objectifs:id,pas_axe_id,code,libelle,date_echeance,description,indicateur_global,valeur_cible,valeurs_cible'),
        ])->toArray();
        $this->recordAudit($request, 'pas', 'create', $pas, null, $after);

        return response()->json([
            'message' => $this->entityCreatedMessage(UiLabel::object('pas')),
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
                    ->with('objectifs:id,pas_axe_id,code,libelle,date_echeance,description,indicateur_global,valeur_cible,valeurs_cible'),
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

        if ($pas->statut === Pas::STATUS_ARCHIVE) {
            return response()->json([
                'message' => 'Impossible de modifier un PAS archive.',
            ], 409);
        }
        if ($message = app(PlanningModificationLockService::class)->ensureUnlocked($pas, $request->user())) {
            return response()->json(['message' => $message], 409);
        }

        $validated = $request->validated();
        // statut / valide_* ne sont plus exposes via l API (cf. A02). La mise a jour
        // du contenu n affecte pas le statut workflow courant.
        $payload = [
            'titre' => (string) $validated['titre'],
            'periode_debut' => (int) $validated['periode_debut'],
            'periode_fin' => (int) $validated['periode_fin'],
        ];

        $before = $pas->load([
            'validateur:id,name,email',
            'directions:id,code,libelle',
            'axes' => fn ($query) => $query
                ->orderBy('ordre')
                ->orderBy('id')
                ->with('direction:id,code,libelle')
                ->with('objectifs:id,pas_axe_id,code,libelle,date_echeance,description,indicateur_global,valeur_cible'),
        ])->toArray();

        DB::transaction(function () use ($pas, $payload, $validated, $user): void {
            $pas->update($payload);
            $this->pasStructureService->sync(
                $pas,
                is_array($validated['axes'] ?? null) ? $validated['axes'] : [],
                $user->id,
            );
        });
        app(PlanningModificationLockService::class)->lockAfterSave($pas->refresh(), $user);

        $pas->refresh();
        $after = $pas->load([
            'validateur:id,name,email',
            'directions:id,code,libelle',
            'axes' => fn ($query) => $query
                ->orderBy('ordre')
                ->orderBy('id')
                ->with('direction:id,code,libelle')
                ->with('objectifs:id,pas_axe_id,code,libelle,date_echeance,description,indicateur_global,valeur_cible,valeurs_cible'),
        ])->toArray();

        $this->recordAudit($request, 'pas', 'update', $pas, $before, $after);

        return response()->json([
            'message' => $this->entityUpdatedMessage(UiLabel::object('pas')),
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

        if ($pas->statut === Pas::STATUS_ARCHIVE) {
            return response()->json([
                'message' => 'Impossible de supprimer directement un PAS archive.',
            ], 409);
        }

        $before = $pas->toArray();
        $pas->delete();

        $this->recordAudit($request, 'pas', 'delete', $pas, $before, null);

        return response()->json([], 204);
    }
}
