<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJustificatifRequest;
use App\Http\Requests\UpdateJustificatifRequest;
use App\Models\Action;
use App\Models\Justificatif;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\User;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JustificatifController extends Controller
{
    use AuthorizesPlanningScope;
    use RecordsAuditTrail;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));
        $query = Justificatif::query()->with(['ajoutePar:id,name,email']);

        $this->applyJustificatifScope($query, $user);

        if ($request->filled('justifiable_type')) {
            $class = $this->resolveJustifiableClass((string) $request->string('justifiable_type'));
            $query->where('justifiable_type', $class);
        }

        $query->when(
            $request->filled('justifiable_id'),
            fn ($q) => $q->where('justifiable_id', (int) $request->integer('justifiable_id'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('nom_original', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('mime_type', 'like', "%{$search}%");
            });
        });

        $result = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (Justificatif $justificatif) => $this->transformJustificatif($justificatif))
            ->withQueryString();

        return response()->json($result);
    }

    public function store(
        StoreJustificatifRequest $request,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $justifiable = $this->resolveJustifiableEntity(
            (string) $validated['justifiable_type'],
            (int) $validated['justifiable_id']
        );

        $scope = $this->resolveEntityScope($justifiable);
        $this->denyUnlessWriteService($user, $scope['direction_id'], $scope['service_id']);

        if ($scope['is_locked']) {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Ajout impossible.',
            ], 409);
        }

        $file = $request->file('fichier');
        $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));

        $justificatif = Justificatif::query()->create([
            'justifiable_type' => $justifiable::class,
            'justifiable_id' => (int) $justifiable->getKey(),
            'nom_original' => $storedFile['nom_original'],
            'chemin_stockage' => $storedFile['path'],
            'est_chiffre' => $storedFile['est_chiffre'],
            'mime_type' => $storedFile['mime_type'],
            'taille_octets' => $storedFile['taille_octets'],
            'description' => $validated['description'] ?? null,
            'ajoute_par' => $user->id,
        ]);

        $this->recordAudit(
            $request,
            'justificatif',
            'create',
            $justificatif,
            null,
            $justificatif->toArray()
        );

        return response()->json([
            'message' => 'Justificatif ajoute avec succes.',
            'data' => $this->transformJustificatif($justificatif->load('ajoutePar:id,name,email')),
        ], 201);
    }

    public function show(Request $request, Justificatif $justificatif): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $justificatif->loadMissing(['justifiable', 'ajoutePar:id,name,email']);
        $this->assertUserCanReadEntity($user, $justificatif->justifiable);

        return response()->json([
            'data' => $this->transformJustificatif($justificatif),
        ]);
    }

    public function update(
        UpdateJustificatifRequest $request,
        Justificatif $justificatif,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $justificatif->loadMissing('justifiable');
        $scope = $this->resolveEntityScope($justificatif->justifiable);
        $this->denyUnlessWriteService($user, $scope['direction_id'], $scope['service_id']);

        if ($scope['is_locked']) {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Mise a jour impossible.',
            ], 409);
        }

        $before = $justificatif->toArray();
        $updateData = ['description' => $request->validated('description')];

        if ($request->hasFile('fichier')) {
            $secureStorage->delete($justificatif);
            $storedFile = $secureStorage->store($request->file('fichier'), 'justificatifs/'.date('Y/m'));
            $updateData = array_merge($updateData, [
                'nom_original' => $storedFile['nom_original'],
                'chemin_stockage' => $storedFile['path'],
                'est_chiffre' => $storedFile['est_chiffre'],
                'mime_type' => $storedFile['mime_type'],
                'taille_octets' => $storedFile['taille_octets'],
            ]);
        }

        $justificatif->update($updateData);

        $this->recordAudit(
            $request,
            'justificatif',
            'update',
            $justificatif,
            $before,
            $justificatif->toArray()
        );

        return response()->json([
            'message' => 'Justificatif mis a jour avec succes.',
            'data' => $this->transformJustificatif($justificatif->load('ajoutePar:id,name,email')),
        ]);
    }

    public function destroy(
        Request $request,
        Justificatif $justificatif,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $justificatif->loadMissing('justifiable');
        $scope = $this->resolveEntityScope($justificatif->justifiable);
        $this->denyUnlessWriteService($user, $scope['direction_id'], $scope['service_id']);

        if ($scope['is_locked']) {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Suppression impossible.',
            ], 409);
        }

        $before = $justificatif->toArray();

        $secureStorage->delete($justificatif);

        $justificatif->delete();

        $this->recordAudit($request, 'justificatif', 'delete', $justificatif, $before, null);

        return response()->json([], 204);
    }

    public function download(
        Request $request,
        Justificatif $justificatif,
        SecureJustificatifStorage $secureStorage
    ): StreamedResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $justificatif->loadMissing('justifiable');
        $this->assertUserCanReadEntity($user, $justificatif->justifiable);

        return $secureStorage->download($justificatif);
    }

    private function applyJustificatifScope(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $this->applyScopeForDirection($query, (int) $user->direction_id);
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE)
            && $user->direction_id !== null
            && $user->service_id !== null
        ) {
            $this->applyScopeForService($query, (int) $user->direction_id, (int) $user->service_id);
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function applyScopeForDirection(Builder $query, int $directionId): void
    {
        $query->where(function (Builder $outer) use ($directionId): void {
            $outer->where(function (Builder $sub): void {
                $sub->where('justifiable_type', Action::class);
            })->orWhere(function (Builder $sub): void {
                $sub->where('justifiable_type', Kpi::class);
            })->orWhere(function (Builder $sub): void {
                $sub->where('justifiable_type', KpiMesure::class);
            });
        });

        $query->where(function (Builder $outer) use ($directionId): void {
            $outer->whereHasMorph('justifiable', [Action::class], function (Builder $sub) use ($directionId): void {
                $sub->whereHas('pta', fn (Builder $ptaQ) => $ptaQ->where('direction_id', $directionId));
            })->orWhereHasMorph('justifiable', [Kpi::class], function (Builder $sub) use ($directionId): void {
                $sub->whereHas('action.pta', fn (Builder $ptaQ) => $ptaQ->where('direction_id', $directionId));
            })->orWhereHasMorph('justifiable', [KpiMesure::class], function (Builder $sub) use ($directionId): void {
                $sub->whereHas('kpi.action.pta', fn (Builder $ptaQ) => $ptaQ->where('direction_id', $directionId));
            });
        });
    }

    private function applyScopeForService(Builder $query, int $directionId, int $serviceId): void
    {
        $query->where(function (Builder $outer): void {
            $outer->where(function (Builder $sub): void {
                $sub->where('justifiable_type', Action::class);
            })->orWhere(function (Builder $sub): void {
                $sub->where('justifiable_type', Kpi::class);
            })->orWhere(function (Builder $sub): void {
                $sub->where('justifiable_type', KpiMesure::class);
            });
        });

        $query->where(function (Builder $outer) use ($directionId, $serviceId): void {
            $outer->whereHasMorph('justifiable', [Action::class], function (Builder $sub) use ($directionId, $serviceId): void {
                $sub->whereHas('pta', fn (Builder $ptaQ) => $ptaQ
                    ->where('direction_id', $directionId)
                    ->where('service_id', $serviceId));
            })->orWhereHasMorph('justifiable', [Kpi::class], function (Builder $sub) use ($directionId, $serviceId): void {
                $sub->whereHas('action.pta', fn (Builder $ptaQ) => $ptaQ
                    ->where('direction_id', $directionId)
                    ->where('service_id', $serviceId));
            })->orWhereHasMorph('justifiable', [KpiMesure::class], function (Builder $sub) use ($directionId, $serviceId): void {
                $sub->whereHas('kpi.action.pta', fn (Builder $ptaQ) => $ptaQ
                    ->where('direction_id', $directionId)
                    ->where('service_id', $serviceId));
            });
        });
    }

    private function assertUserCanReadEntity(User $user, ?Model $entity): void
    {
        if ($entity === null) {
            abort(404, 'Entite associee introuvable.');
        }

        $scope = $this->resolveEntityScope($entity);

        if (! $this->canReadDirection($user, $scope['direction_id'])) {
            abort(403, 'Acces non autorise.');
        }

        if ($user->hasRole(User::ROLE_SERVICE) && (int) $user->service_id !== $scope['service_id']) {
            abort(403, 'Acces non autorise.');
        }
    }

    /**
     * @return array{direction_id:int|null,service_id:int|null,is_locked:bool}
     */
    private function resolveEntityScope(Model $entity): array
    {
        if ($entity instanceof Action) {
            $entity->loadMissing('pta:id,direction_id,service_id,statut');
            return [
                'direction_id' => $entity->pta?->direction_id !== null ? (int) $entity->pta->direction_id : null,
                'service_id' => $entity->pta?->service_id !== null ? (int) $entity->pta->service_id : null,
                'is_locked' => (string) $entity->pta?->statut === 'verrouille',
            ];
        }

        if ($entity instanceof Kpi) {
            $entity->loadMissing('action.pta:id,direction_id,service_id,statut');
            return [
                'direction_id' => $entity->action?->pta?->direction_id !== null
                    ? (int) $entity->action->pta->direction_id
                    : null,
                'service_id' => $entity->action?->pta?->service_id !== null
                    ? (int) $entity->action->pta->service_id
                    : null,
                'is_locked' => (string) $entity->action?->pta?->statut === 'verrouille',
            ];
        }

        if ($entity instanceof KpiMesure) {
            $entity->loadMissing('kpi.action.pta:id,direction_id,service_id,statut');
            return [
                'direction_id' => $entity->kpi?->action?->pta?->direction_id !== null
                    ? (int) $entity->kpi->action->pta->direction_id
                    : null,
                'service_id' => $entity->kpi?->action?->pta?->service_id !== null
                    ? (int) $entity->kpi->action->pta->service_id
                    : null,
                'is_locked' => (string) $entity->kpi?->action?->pta?->statut === 'verrouille',
            ];
        }

        abort(422, 'Type d entite non pris en charge.');
    }

    private function resolveJustifiableEntity(string $type, int $id): Model
    {
        $class = $this->resolveJustifiableClass($type);

        /** @var Model|null $entity */
        $entity = $class::query()->find($id);

        if ($entity === null) {
            abort(404, 'Entite justifiable introuvable.');
        }

        return $entity;
    }

    /**
     * @return class-string<Model>
     */
    private function resolveJustifiableClass(string $type): string
    {
        $normalized = strtolower(trim($type));

        return match ($normalized) {
            'action', strtolower(Action::class) => Action::class,
            'kpi', strtolower(Kpi::class) => Kpi::class,
            'kpi_mesure', 'kpimesure', strtolower(KpiMesure::class) => KpiMesure::class,
            default => abort(422, 'Type de justificatif invalide.'),
        };
    }

    private function aliasForClass(string $class): string
    {
        return match ($class) {
            Action::class => 'action',
            Kpi::class => 'kpi',
            KpiMesure::class => 'kpi_mesure',
            default => $class,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function transformJustificatif(Justificatif $justificatif): array
    {
        return [
            'id' => (int) $justificatif->id,
            'justifiable_type' => $this->aliasForClass((string) $justificatif->justifiable_type),
            'justifiable_id' => (int) $justificatif->justifiable_id,
            'nom_original' => $justificatif->nom_original,
            'chemin_stockage' => $justificatif->chemin_stockage,
            'mime_type' => $justificatif->mime_type,
            'taille_octets' => $justificatif->taille_octets,
            'description' => $justificatif->description,
            'ajoute_par' => $justificatif->ajoute_par,
            'ajoute_par_user' => $justificatif->relationLoaded('ajoutePar') ? $justificatif->ajoutePar : null,
            'created_at' => $justificatif->created_at,
            'updated_at' => $justificatif->updated_at,
            'download_url' => route('justificatifs.download', ['justificatif' => $justificatif->id]),
        ];
    }
}
