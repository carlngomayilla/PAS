<?php

namespace App\Http\Controllers\Web;

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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JustificatifWebController extends Controller
{
    use AuthorizesPlanningScope;
    use RecordsAuditTrail;

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $query = Justificatif::query()
            ->with(['ajoutePar:id,name,email', 'justifiable']);

        $this->applyJustificatifScope($query, $user);

        $query->when($request->filled('type'), function (Builder $q) use ($request): void {
            $class = $this->resolveJustifiableClass((string) $request->string('type'));
            $q->where('justifiable_type', $class);
        });

        $query->when(
            $request->filled('entite_id'),
            fn (Builder $q) => $q->where('justifiable_id', (int) $request->integer('entite_id'))
        );

        $query->when($request->filled('q'), function (Builder $q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function (Builder $subQuery) use ($search): void {
                $subQuery->where('nom_original', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('mime_type', 'like', "%{$search}%");
            });
        });

        $justificatifs = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $canWriteByJustificatif = [];
        foreach ($justificatifs as $item) {
            if (! $item instanceof Justificatif) {
                continue;
            }

            $canWriteByJustificatif[(int) $item->id] = $this->canMutateJustificatif($user, $item);
        }

        return view('workspace.justificatifs.index', [
            'justificatifs' => $justificatifs,
            'canWrite' => $this->canWrite($user),
            'canWriteByJustificatif' => $canWriteByJustificatif,
            'type' => (string) $request->string('type'),
            'entiteId' => $request->filled('entite_id') ? (int) $request->integer('entite_id') : null,
            'q' => (string) $request->string('q'),
            'typeOptions' => $this->typeOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canWrite($user)) {
            abort(403, 'Acces non autorise.');
        }

        return view('workspace.justificatifs.create', [
            'typeOptions' => $this->typeOptions(),
            'references' => $this->loadReferences($user),
        ]);
    }

    public function store(
        StoreJustificatifRequest $request,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse
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
            return back()
                ->withInput()
                ->withErrors(['fichier' => 'Le PTA parent est verrouille. Ajout impossible.']);
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

        return redirect()
            ->route('workspace.justificatifs.index')
            ->with('success', 'Justificatif ajoute avec succes.');
    }

    public function edit(Request $request, Justificatif $justificatif): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $justificatif->loadMissing('justifiable');
        $this->assertUserCanReadEntity($user, $justificatif->justifiable);
        $scope = $this->resolveEntityScope($justificatif->justifiable);
        $this->denyUnlessWriteService($user, $scope['direction_id'], $scope['service_id']);

        return view('workspace.justificatifs.edit', [
            'justificatif' => $justificatif,
            'typeAlias' => $this->aliasForClass((string) $justificatif->justifiable_type),
        ]);
    }

    public function update(
        UpdateJustificatifRequest $request,
        Justificatif $justificatif,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();

        $justificatif->loadMissing('justifiable');
        $scope = $this->resolveEntityScope($justificatif->justifiable);
        $this->denyUnlessWriteService($user, $scope['direction_id'], $scope['service_id']);

        if ($scope['is_locked']) {
            return back()
                ->withInput()
                ->withErrors(['description' => 'Le PTA parent est verrouille. Mise a jour impossible.']);
        }

        $before = $justificatif->toArray();
        $updateData = ['description' => $validated['description'] ?? null];

        if ($request->hasFile('fichier')) {
            $secureStorage->delete($justificatif);

            $file = $request->file('fichier');
            $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
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

        return redirect()
            ->route('workspace.justificatifs.index')
            ->with('success', 'Justificatif mis a jour avec succes.');
    }

    public function destroy(
        Request $request,
        Justificatif $justificatif,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $justificatif->loadMissing('justifiable');
        $scope = $this->resolveEntityScope($justificatif->justifiable);
        $this->denyUnlessWriteService($user, $scope['direction_id'], $scope['service_id']);

        if ($scope['is_locked']) {
            return redirect()
                ->route('workspace.justificatifs.index')
                ->withErrors(['general' => 'Le PTA parent est verrouille. Suppression impossible.']);
        }

        $before = $justificatif->toArray();

        $secureStorage->delete($justificatif);

        $justificatif->delete();

        $this->recordAudit(
            $request,
            'justificatif',
            'delete',
            $justificatif,
            $before,
            null
        );

        return redirect()
            ->route('workspace.justificatifs.index')
            ->with('success', 'Justificatif supprime avec succes.');
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

    /**
     * @return array<string, string>
     */
    private function typeOptions(): array
    {
        return [
            'action' => 'Action',
            'kpi' => 'KPI',
            'kpi_mesure' => 'Mesure KPI',
        ];
    }

    /**
     * @return array<string, \Illuminate\Support\Collection<int, mixed>>
     */
    private function loadReferences(User $user): array
    {
        $actions = Action::query()->select(['id', 'pta_id', 'libelle']);
        $kpis = Kpi::query()->select(['id', 'action_id', 'libelle']);
        $kpiMesures = KpiMesure::query()->select(['id', 'kpi_id', 'periode']);

        $this->scopeAction($actions, $user);
        $this->scopeKpi($kpis, $user);
        $this->scopeKpiMesure($kpiMesures, $user);

        return [
            'actions' => $actions->orderByDesc('id')->limit(30)->get(),
            'kpis' => $kpis->orderByDesc('id')->limit(30)->get(),
            'kpi_mesures' => $kpiMesures->orderByDesc('id')->limit(30)->get(),
        ];
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

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess()
            || $user->hasRole(User::ROLE_DIRECTION)
            || $user->hasRole(User::ROLE_SERVICE);
    }

    private function canMutateJustificatif(User $user, Justificatif $justificatif): bool
    {
        $justificatif->loadMissing('justifiable');
        $scope = $this->resolveEntityScope($justificatif->justifiable);

        if ($scope['is_locked']) {
            return false;
        }

        return $this->canWriteService($user, $scope['direction_id'], $scope['service_id']);
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

    private function scopeKpiMesure(Builder $query, User $user): void
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
}
