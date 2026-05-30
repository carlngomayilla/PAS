<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use App\Services\DeletionRequestService;
use App\Services\Security\AntivirusScanner;
use App\Services\Security\MalwareScanException;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReferentielWebController extends Controller
{
    use AuthorizesPlanningScope;
    use RecordsAuditTrail;

    public function __construct(
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly AntivirusScanner $scanner,
        private readonly DeletionRequestService $deletionRequestService,
        private readonly \App\Services\ChefUniteSyncService $chefUniteSync,
        private readonly \App\Services\RoleRegistryService $roleRegistry
    ) {
    }

    public function directionsIndex(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielReader($user);

        $query = Direction::query()
            ->withCount(['services', 'users', 'paos', 'ptas'])
            ->orderBy('code');

        if ($request->filled('actif')) {
            $query->where('actif', (bool) $request->boolean('actif'));
        } else {
            $query->where('actif', true);
        }
        $query->when($request->filled('q'), function (Builder $q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function (Builder $subQuery) use ($search): void {
                $subQuery->where('code', 'like', "%{$search}%")
                    ->orWhere('libelle', 'like', "%{$search}%");
            });
        });

        if (! $user->hasGlobalReadAccess()) {
            if ($user->direction_id !== null) {
                $query->whereKey((int) $user->direction_id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $directionSummaryRows = (clone $query)->get();

        return view('workspace.referentiel.directions.index', [
            'rows' => $query->paginate(20)->withQueryString(),
            'summary' => [
                'total' => $directionSummaryRows->count(),
                'actifs' => $directionSummaryRows->where('actif', true)->count(),
                'services_total' => (int) $directionSummaryRows->sum('services_count'),
                'users_total' => (int) $directionSummaryRows->sum('users_count'),
                'paos_total' => (int) $directionSummaryRows->sum('paos_count'),
                'ptas_total' => (int) $directionSummaryRows->sum('ptas_count'),
            ],
            'canWrite' => $this->canWrite($user),
            'canManageRoles' => $this->canManageRoles($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'actif' => $request->filled('actif') ? (int) $request->integer('actif') : null,
            ],
        ]);
    }

    public function directionsCreate(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        return view('workspace.referentiel.directions.form', [
            'mode' => 'create',
            'row' => new Direction(),
        ]);
    }

    public function directionsStore(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:directions,code'],
            'libelle' => ['required', 'string', 'max:255'],
            'actif' => ['required', 'boolean'],
        ]);

        $direction = Direction::query()->create($validated);
        $this->recordAudit($request, 'referentiel_direction', 'create', $direction, null, $direction->toArray());

        return redirect()
            ->route('workspace.referentiel.directions.index')
            ->with('success', 'Direction creee avec succès.');
    }

    public function directionsEdit(Request $request, Direction $direction): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        return view('workspace.referentiel.directions.form', [
            'mode' => 'edit',
            'row' => $direction,
        ]);
    }

    public function directionsUpdate(Request $request, Direction $direction): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('directions', 'code')->ignore($direction->id)],
            'libelle' => ['required', 'string', 'max:255'],
            'actif' => ['required', 'boolean'],
        ]);

        $before = $direction->toArray();
        $direction->update($validated);

        $this->recordAudit($request, 'referentiel_direction', 'update', $direction, $before, $direction->toArray());

        return redirect()
            ->route('workspace.referentiel.directions.index')
            ->with('success', 'Direction mise a jour avec succès.');
    }

    public function directionsDestroy(Request $request, Direction $direction): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        $direction->loadCount(['services', 'paos', 'ptas']);
        if ($direction->services_count > 0 || $direction->paos_count > 0 || $direction->ptas_count > 0) {
            return back()->withErrors([
                'general' => 'Suppression impossible: la direction est encore utilisee (services/PAO/PTA).',
            ]);
        }

        $before = $direction->toArray();
        $direction->delete();

        $this->recordAudit($request, 'referentiel_direction', 'delete', $direction, $before, null);

        return redirect()
            ->route('workspace.referentiel.directions.index')
            ->with('success', 'Direction supprimee avec succès.');
    }

    public function servicesIndex(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielReader($user);

        $query = Service::query()
            ->with('direction:id,code,libelle')
            ->withCount(['users', 'ptas'])
            ->orderBy('direction_id')
            ->orderBy('code');

        $query->when(
            $request->filled('direction_id'),
            fn (Builder $q) => $q->where('direction_id', (int) $request->integer('direction_id'))
        );
        $query->when(
            $request->filled('actif'),
            fn (Builder $q) => $q->where('actif', (bool) $request->boolean('actif'))
        );
        $query->when($request->filled('q'), function (Builder $q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function (Builder $subQuery) use ($search): void {
                $subQuery->where('code', 'like', "%{$search}%")
                    ->orWhere('libelle', 'like', "%{$search}%");
            });
        });

        if (! $user->hasGlobalReadAccess()) {
            if ($user->direction_id !== null) {
                $query->where('direction_id', (int) $user->direction_id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereKey((int) $user->service_id);
        }

        $serviceSummaryRows = (clone $query)->get();

        return view('workspace.referentiel.services.index', [
            'rows' => $query->paginate(20)->withQueryString(),
            'summary' => [
                'total' => $serviceSummaryRows->count(),
                'actifs' => $serviceSummaryRows->where('actif', true)->count(),
                'directions_total' => $serviceSummaryRows->pluck('direction_id')->filter()->unique()->count(),
                'users_total' => (int) $serviceSummaryRows->sum('users_count'),
                'ptas_total' => (int) $serviceSummaryRows->sum('ptas_count'),
            ],
            'directionOptions' => $this->activeDirectionOptions(['id', 'code', 'libelle', 'actif']),
            'canWrite' => $this->canWrite($user),
            'canManageRoles' => $this->canManageRoles($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'direction_id' => $request->filled('direction_id') ? (int) $request->integer('direction_id') : null,
                'actif' => $request->filled('actif') ? (int) $request->integer('actif') : null,
            ],
        ]);
    }

    public function servicesCreate(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        return view('workspace.referentiel.services.form', [
            'mode' => 'create',
            'row' => new Service(),
            'directionOptions' => $this->activeDirectionOptions(['id', 'code', 'libelle', 'actif']),
        ]);
    }

    public function servicesStore(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        $validated = $request->validate([
            'direction_id' => ['required', 'integer', 'exists:directions,id'],
            'code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('services', 'code')
                    ->where(fn ($q) => $q->where('direction_id', $request->input('direction_id'))),
            ],
            'libelle' => ['required', 'string', 'max:255'],
            'actif' => ['required', 'boolean'],
        ], [
            'code.unique' => 'Le code de service est deja utilise dans cette direction.',
        ]);

        $service = Service::query()->create($validated);
        $this->recordAudit($request, 'referentiel_service', 'create', $service, null, $service->toArray());

        return redirect()
            ->route('workspace.referentiel.services.index')
            ->with('success', 'Service cree avec succès.');
    }

    public function servicesEdit(Request $request, Service $service): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        return view('workspace.referentiel.services.form', [
            'mode' => 'edit',
            'row' => $service,
            'directionOptions' => $this->activeDirectionOptions(['id', 'code', 'libelle', 'actif']),
        ]);
    }

    public function servicesUpdate(Request $request, Service $service): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        $validated = $request->validate([
            'direction_id' => ['required', 'integer', 'exists:directions,id'],
            'code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('services', 'code')
                    ->ignore($service->id)
                    ->where(fn ($q) => $q->where('direction_id', $request->input('direction_id'))),
            ],
            'libelle' => ['required', 'string', 'max:255'],
            'actif' => ['required', 'boolean'],
        ], [
            'code.unique' => 'Le code de service est deja utilise dans cette direction.',
        ]);

        $before = $service->toArray();
        $service->update($validated);

        $this->recordAudit($request, 'referentiel_service', 'update', $service, $before, $service->toArray());

        return redirect()
            ->route('workspace.referentiel.services.index')
            ->with('success', 'Service mis a jour avec succès.');
    }

    public function servicesDestroy(Request $request, Service $service): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        $service->loadCount('ptas');
        if ($service->ptas_count > 0) {
            return back()->withErrors([
                'general' => 'Suppression impossible: le service est rattache a au moins un PTA.',
            ]);
        }

        $before = $service->toArray();
        $service->delete();

        $this->recordAudit($request, 'referentiel_service', 'delete', $service, $before, null);

        return redirect()
            ->route('workspace.referentiel.services.index')
            ->with('success', 'Service supprime avec succès.');
    }

    public function utilisateursIndex(Request $request): View
    {
        $user = $this->authUser($request);
        // Lecture autorisee aux roles avec referentiel.read (Direction, Chef de service,
        // DG, Planification, etc.) afin que le module sidebar "Agents / RMO" soit
        // operationnel. Les operations d'ecriture (create/update/destroy) restent
        // protegees par denyUnlessUserManager dans les methodes correspondantes.
        $this->denyUnlessReferentielReader($user);

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
            fn (Builder $q) => $q->where('direction_id', (int) $request->integer('direction_id'))
        );
        $query->when(
            $request->filled('service_id'),
            fn (Builder $q) => $q->where('service_id', (int) $request->integer('service_id'))
        );
        $query->when(
            $request->filled('role'),
            fn (Builder $q) => $q->where('role', (string) $request->string('role'))
        );
        $query->when(
            $request->filled('is_active'),
            fn (Builder $q) => $q->where('is_active', $request->string('is_active') === '1')
        );
        $query->when($request->filled('q'), function (Builder $q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function (Builder $subQuery) use ($search): void {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        });

        $userSummaryBase = clone $query;

        return view('workspace.referentiel.utilisateurs.index', [
            'rows' => $query->paginate(20)->withQueryString(),
            'summary' => [
                'total' => (clone $userSummaryBase)->count(),
                'actifs' => (clone $userSummaryBase)->where('is_active', true)->count(),
                'agents' => (clone $userSummaryBase)->where('is_agent', true)->count(),
                'encadrement' => (clone $userSummaryBase)
                    ->whereIn('role', [
                        User::ROLE_SERVICE,
                        User::ROLE_DIRECTION,
                        User::ROLE_PLANIFICATION,
                        User::ROLE_DG,
                        User::ROLE_ADMIN_FONCTIONNEL,
                        User::ROLE_SUPER_ADMIN,
                    ])->count(),
                'directions_total' => (clone $userSummaryBase)->whereNotNull('direction_id')->distinct()->count('direction_id'),
                'services_total' => (clone $userSummaryBase)->whereNotNull('service_id')->distinct()->count('service_id'),
            ],
            'canWrite' => $this->canManageUsers($user),
            'canDeleteUsers' => $user->isSuperAdmin(),
            'canRequestUserDeletion' => $this->canRequestAnyUserDeletion($user),
            'canManageRoles' => $this->canManageRoles($user),
            'directionOptions' => $this->activeDirectionOptions(),
            'serviceOptions' => Service::query()->with('direction:id,code')->orderBy('direction_id')->orderBy('code')
                ->get(['id', 'direction_id', 'code', 'libelle']),
            'roleOptions' => $this->roleOptions($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'direction_id' => $request->filled('direction_id') ? (int) $request->integer('direction_id') : null,
                'service_id' => $request->filled('service_id') ? (int) $request->integer('service_id') : null,
                'role' => (string) $request->string('role'),
                'is_active' => $request->filled('is_active') ? (string) $request->string('is_active') : '',
            ],
        ]);
    }

    public function utilisateursCreate(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessUserManager($user);

        return view('workspace.referentiel.utilisateurs.form', [
            'mode' => 'create',
            'row' => new User(),
            'directionOptions' => $this->activeDirectionOptions(),
            'serviceOptions' => Service::query()->with('direction:id,code')->orderBy('direction_id')->orderBy('code')
                ->get(['id', 'direction_id', 'code', 'libelle']),
            'uniteDgOptions' => \App\Models\UniteDg::query()->where('actif', true)->orderBy('code')->get(['id', 'code', 'libelle']),
            'roleOptions' => $this->roleOptions($user),
            'canManageRoles' => $this->canManageRoles($user),
        ]);
    }

    public function utilisateursStore(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessUserManager($user);

        $validated = $this->validateUtilisateur($request, true, $user);
        $this->applyRoleScopeRules($validated);
        $this->enforceManagedUserScope($user, $validated);
        $profilePhotoPath = $this->storeProfilePhoto($request);

        $created = DB::transaction(function () use ($validated, $profilePhotoPath, $request): User {
            // forceCreate : role / direction_id / service_id / unite_dg_id / is_active /
            // is_agent / agent_* ne sont plus mass-assignables (cf. A02). Cette voie
            // est reservee aux admins et tous les champs sont valides en amont.
            $created = User::query()->forceCreate([
                'name' => (string) $validated['name'],
                'profile_photo_path' => $profilePhotoPath,
                'email' => (string) $validated['email'],
                'role' => (string) $validated['role'],
                'is_active' => $request->boolean('is_active', true),
                'is_agent' => (string) $validated['role'] === User::ROLE_AGENT,
                'agent_matricule' => $validated['agent_matricule'] ?? null,
                'agent_fonction' => $validated['agent_fonction'] ?? null,
                'agent_telephone' => $validated['agent_telephone'] ?? null,
                'direction_id' => $validated['direction_id'] ?? null,
                'service_id' => $validated['service_id'] ?? null,
                'unite_dg_id' => $validated['unite_dg_id'] ?? null,
                'password' => 'temp-password-placeholder',
                'password_changed_at' => now(),
            ]);

            $this->passwordPolicy->persistPassword($created, (string) $validated['password']);

            return $created->fresh();
        });

        $this->chefUniteSync->sync($created);

        $this->recordAudit($request, 'referentiel_utilisateur', 'create', $created, null, $created->toArray());

        return redirect()
            ->route('workspace.referentiel.utilisateurs.index')
            ->with('success', 'Utilisateur cree avec succès.');
    }

    public function utilisateursEdit(Request $request, User $utilisateur): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessUserManager($user);
        $this->denyUnlessManagedUserAccessible($user, $utilisateur);
        $this->denyIfSuperAdminTargetIsLocked($user, $utilisateur);

        return view('workspace.referentiel.utilisateurs.form', [
            'mode' => 'edit',
            'row' => $utilisateur,
            'directionOptions' => $this->activeDirectionOptions(),
            'serviceOptions' => Service::query()->with('direction:id,code')->orderBy('direction_id')->orderBy('code')
                ->get(['id', 'direction_id', 'code', 'libelle']),
            'uniteDgOptions' => \App\Models\UniteDg::query()->where('actif', true)->orderBy('code')->get(['id', 'code', 'libelle']),
            'roleOptions' => $this->roleOptions($user, $utilisateur),
            'canManageRoles' => $this->canManageRoles($user),
        ]);
    }

    public function utilisateursUpdate(Request $request, User $utilisateur): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessUserManager($user);
        $this->denyUnlessManagedUserAccessible($user, $utilisateur);
        $this->denyIfSuperAdminTargetIsLocked($user, $utilisateur);

        $validated = $this->validateUtilisateur($request, false, $user, $utilisateur);
        $this->applyRoleScopeRules($validated);
        $this->enforceManagedUserScope($user, $validated);

        $payload = [
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
            'role' => (string) $validated['role'],
            'is_active' => $request->boolean('is_active', true),
            'is_agent' => (string) $validated['role'] === User::ROLE_AGENT,
            'agent_matricule' => $validated['agent_matricule'] ?? null,
            'agent_fonction' => $validated['agent_fonction'] ?? null,
            'agent_telephone' => $validated['agent_telephone'] ?? null,
            'direction_id' => $validated['direction_id'] ?? null,
            'service_id' => $validated['service_id'] ?? null,
            'unite_dg_id' => $validated['unite_dg_id'] ?? null,
        ];

        if (! empty($validated['password'])) {
            $this->passwordPolicy->validateNotReused($utilisateur, (string) $validated['password']);
        }

        $payload = array_merge($payload, $this->resolveProfilePhotoPayloadForUpdate($request, $utilisateur));

        $before = $utilisateur->toArray();
        DB::transaction(function () use ($utilisateur, $payload, $validated): void {
            // forceFill : role / is_active / direction_id / service_id / unite_dg_id
            // ne sont plus mass-assignables (cf. A02). Le payload est integralement
            // valide et controle par le controleur referentiel reserve aux admins.
            $utilisateur->forceFill($payload)->save();

            if (! empty($validated['password'])) {
                $this->passwordPolicy->persistPassword($utilisateur, (string) $validated['password']);
            }
        });
        $utilisateur->refresh();

        $this->chefUniteSync->sync($utilisateur);

        $this->recordAudit($request, 'referentiel_utilisateur', 'update', $utilisateur, $before, $utilisateur->toArray());

        return redirect()
            ->route('workspace.referentiel.utilisateurs.index')
            ->with('success', 'Utilisateur mis a jour avec succès.');
    }

    public function utilisateursDeletionRequestStore(Request $request, User $utilisateur): RedirectResponse
    {
        $user = $this->authUser($request);

        if (! $this->deletionRequestService->canRequestUserDeletion($user, $utilisateur)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $deletionRequest = $this->deletionRequestService->requestUserDeletion(
            $utilisateur,
            $user,
            (string) $validated['motif']
        );

        $this->recordAudit($request, 'referentiel_utilisateur', 'deletion_request_create', $deletionRequest, null, $deletionRequest->toArray());

        return redirect()
            ->route('workspace.referentiel.utilisateurs.index')
            ->with('success', 'Demande de suppression transmise au Super Admin.');
    }

    public function utilisateursDestroy(Request $request, User $utilisateur): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessUserManager($user);
        $this->denyUnlessManagedUserAccessible($user, $utilisateur);
        $this->denyIfSuperAdminTargetIsLocked($user, $utilisateur);

        if (! $user->isSuperAdmin()) {
            return back()->withErrors([
                'general' => 'Suppression definitive reservee au Super Admin. Utilisez la desactivation ou transmettez une demande motivee.',
            ]);
        }

        if ((int) $utilisateur->id === (int) $user->id) {
            return back()->withErrors(['general' => 'Vous ne pouvez pas supprimer votre propre compte.']);
        }

        $validated = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $impact = $this->deletionRequestService->impactForUser($utilisateur);
        if ((int) ($impact['total'] ?? 0) > 0) {
            return back()->withErrors([
                'general' => 'Suppression impossible : l\'utilisateur est déjà responsable d\'actions ou d\'objectifs opérationnels.',
            ]);
        }

        if (is_string($utilisateur->profile_photo_path) && trim($utilisateur->profile_photo_path) !== '') {
            Storage::disk('public')->delete($utilisateur->profile_photo_path);
        }

        $before = $utilisateur->toArray();
        $reason = trim((string) $validated['motif']);
        $utilisateur->delete();

        $this->recordAudit($request, 'referentiel_utilisateur', 'delete', $utilisateur, [
            ...$before,
            'deletion_reason' => $reason,
            'impact' => $impact,
        ], null);

        return redirect()
            ->route('workspace.referentiel.utilisateurs.index')
            ->with('success', 'Utilisateur supprime avec succès.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateUtilisateur(Request $request, bool $creating, User $actor, ?User $utilisateur = null): array
    {
        $emailRule = Rule::unique('users', 'email');
        if (! $creating && $utilisateur !== null) {
            $emailRule = $emailRule->ignore($utilisateur->id);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', $emailRule],
            'role' => ['required', Rule::in($this->acceptedRoleOptions($actor, $utilisateur))],
            'is_active' => ['nullable', 'boolean'],
            'agent_matricule' => ['nullable', 'string', 'max:80'],
            'agent_fonction' => ['nullable', 'string', 'max:120'],
            'agent_telephone' => ['nullable', 'string', 'max:40'],
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'unite_dg_id' => ['nullable', 'integer', 'exists:unites_dg,id'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'remove_profile_photo' => ['nullable', 'boolean'],
            'password' => $creating
                ? ['required', 'string', $this->passwordPolicy->rule(), 'confirmed']
                : ['nullable', 'string', $this->passwordPolicy->rule(false), 'confirmed'],
        ]);

        $validated['agent_matricule'] = isset($validated['agent_matricule'])
            ? trim((string) $validated['agent_matricule'])
            : null;
        $validated['agent_fonction'] = isset($validated['agent_fonction'])
            ? trim((string) $validated['agent_fonction'])
            : null;
        $validated['agent_telephone'] = isset($validated['agent_telephone'])
            ? trim((string) $validated['agent_telephone'])
            : null;

        // Cohérence Direction ↔ Service/Unité DG :
        //   - Direction "DG" : utilise une Unité DG, pas de Service.
        //   - Autre direction : utilise un Service, pas d'Unité DG.
        $directionId = $validated['direction_id'] ?? null;
        if ($directionId !== null) {
            $direction = Direction::query()->find($directionId);
            $isDg = $direction && (string) $direction->code === 'DG';
            if ($isDg) {
                $validated['service_id'] = null;
            } else {
                $validated['unite_dg_id'] = null;
            }
        } else {
            // Pas de direction sélectionnée : on s'assure que service et unité sont vidés.
            $validated['service_id'] = null;
            $validated['unite_dg_id'] = null;
        }

        return $validated;
    }

    private function storeProfilePhoto(Request $request): ?string
    {
        if (! $request->hasFile('profile_photo')) {
            return null;
        }

        $file = $request->file('profile_photo');
        if ($file === null) {
            return null;
        }

        try {
            $this->scanner->scanUploadedFile($file);
        } catch (MalwareScanException $exception) {
            throw ValidationException::withMessages([
                'profile_photo' => $exception->getMessage(),
            ]);
        }

        return $file->store('profils', 'public');
    }

    /**
     * @return array<string, string|null>
     */
    private function resolveProfilePhotoPayloadForUpdate(
        Request $request,
        User $utilisateur
    ): array {
        if ($request->hasFile('profile_photo')) {
            $newPath = $this->storeProfilePhoto($request);
            if (is_string($utilisateur->profile_photo_path) && trim($utilisateur->profile_photo_path) !== '') {
                Storage::disk('public')->delete($utilisateur->profile_photo_path);
            }

            return ['profile_photo_path' => $newPath];
        }

        if (! $request->boolean('remove_profile_photo')) {
            return [];
        }

        if (is_string($utilisateur->profile_photo_path) && trim($utilisateur->profile_photo_path) !== '') {
            Storage::disk('public')->delete($utilisateur->profile_photo_path);
        }

        return ['profile_photo_path' => null];
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function applyRoleScopeRules(array &$validated): void
    {
        $role = $this->roleRegistry->baseRole((string) $validated['role']);
        $validated['role'] = $role;
        $directionId = isset($validated['direction_id']) ? (int) $validated['direction_id'] : null;
        $serviceId = isset($validated['service_id']) ? (int) $validated['service_id'] : null;

        if ($role === User::ROLE_SERVICE || $role === User::ROLE_AGENT) {
            if ($directionId === null || $serviceId === null) {
                throw ValidationException::withMessages([
                    'direction_id' => 'Direction et service sont obligatoires pour un profil service/agent.',
                ]);
            }

            $this->ensureOperationalDirectionAllowed($directionId);

            $service = Service::query()->find($serviceId);
            if ($service === null || (int) $service->direction_id !== $directionId) {
                throw ValidationException::withMessages([
                    'service_id' => 'Le service doit appartenir a la direction selectionnee.',
                ]);
            }

            if ($role === User::ROLE_AGENT) {
                if (trim((string) ($validated['agent_matricule'] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        'agent_matricule' => 'Le matricule est obligatoire pour le role agent.',
                    ]);
                }

                if (trim((string) ($validated['agent_fonction'] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        'agent_fonction' => 'La fonction est obligatoire pour le role agent.',
                    ]);
                }
            } else {
                $validated['agent_matricule'] = null;
                $validated['agent_fonction'] = null;
                $validated['agent_telephone'] = null;
            }

            return;
        }

        $validated['agent_matricule'] = null;
        $validated['agent_fonction'] = null;
        $validated['agent_telephone'] = null;

        if ($role === User::ROLE_DIRECTION) {
            if ($directionId === null) {
                throw ValidationException::withMessages([
                    'direction_id' => 'La direction est obligatoire pour un profil direction.',
                ]);
            }

            $this->ensureOperationalDirectionAllowed($directionId);

            if ($serviceId !== null) {
                throw ValidationException::withMessages([
                    'service_id' => 'Le service doit etre vide pour un profil direction.',
                ]);
            }

            return;
        }

        if ($directionId !== null || $serviceId !== null) {
            throw ValidationException::withMessages([
                'direction_id' => 'Direction/service doivent etre vides pour ce profil global.',
            ]);
        }
    }

    private function ensureOperationalDirectionAllowed(int $directionId): void
    {
        $direction = Direction::query()->find($directionId);
        $code = strtoupper(trim((string) ($direction?->code ?? '')));

        if (in_array($code, $this->operationalDirectionCodes(), true)) {
            return;
        }

        throw ValidationException::withMessages([
            'direction_id' => 'Les profils direction, service et agent sont reserves aux directions DAF, DSIC et DS.',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function operationalDirectionCodes(): array
    {
        return ['DAF', 'DSIC', 'DS'];
    }

    private function authUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    private function denyUnlessReferentielReader(User $user): void
    {
        if ($user->hasAnyPermission('referentiel.read', 'referentiel.write', 'users.manage', 'users.manage_roles')) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    private function denyUnlessReferentielWriter(User $user): void
    {
        if ($user->hasPermission('referentiel.write') && $user->hasGlobalWriteAccess()) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    private function denyUnlessUserManager(User $user): void
    {
        if ($this->canManageUsers($user)) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    private function denyIfSuperAdminTargetIsLocked(User $actor, User $target): void
    {
        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            abort(403, 'Acces non autorise.');
        }
    }

    private function canWrite(User $user): bool
    {
        return $user->hasPermission('referentiel.write') && $user->hasGlobalWriteAccess();
    }

    private function canManageUsers(User $user): bool
    {
        return $user->hasAnyPermission('users.manage', 'users.manage_roles');
    }

    private function canManageRoles(User $user): bool
    {
        return $user->hasPermission('users.manage_roles');
    }

    private function canRequestAnyUserDeletion(User $user): bool
    {
        return $user->isSuperAdmin()
            || $user->hasGlobalReadAccess()
            || $user->hasRole(
                User::ROLE_DG,
                User::ROLE_DGA_SUPERVISION,
                User::ROLE_CABINET,
                User::ROLE_CABINET_SUPERVISION,
                User::ROLE_SCIQ,
                User::ROLE_PLANIFICATION,
                User::ROLE_ADMIN_FONCTIONNEL,
                User::ROLE_DIRECTION,
                User::ROLE_SERVICE,
                User::ROLE_CHEF_UNITE_UCAS,
                User::ROLE_UCAS,
            );
    }

    /**
     * @param  list<string>  $columns
     */
    private function activeDirectionOptions(array $columns = ['id', 'code', 'libelle'])
    {
        return Direction::query()
            ->where('actif', true)
            ->orderBy('code')
            ->get($columns);
    }

    /**
     * @return array<int, string>
     */
    private function roleOptions(?User $actor = null, ?User $subject = null): array
    {
        // Liste des rôles métier actifs : super_admin, admin_fonctionnel, dg,
        // planification, direction, service, agent, auditeur.
        $allRoles = array_values($this->roleRegistry->codes());

        // Rôles considérés comme "techniques" — réservés au super admin.
        $superAdminOnly = [User::ROLE_SUPER_ADMIN];

        // Sous-ensemble accessible aux gestionnaires non super-admin :
        // on retire les rôles techniques.
        $globalManagerRoles = array_values(array_diff($allRoles, $superAdminOnly));

        if ($actor === null) {
            return $globalManagerRoles;
        }

        if ($actor->isSuperAdmin()) {
            return $allRoles;
        }

        if (! $this->canManageRoles($actor)) {
            return $subject instanceof User ? [$subject->role] : [User::ROLE_AGENT];
        }

        if ($actor->hasGlobalReadAccess()) {
            return $globalManagerRoles;
        }

        // Direction : peut affecter chefs de service et agents.
        if ($actor->hasRole(User::ROLE_DIRECTION)) {
            return [User::ROLE_SERVICE, User::ROLE_AGENT];
        }

        // Service / chef d'unité UCAS : ne peut affecter que des agents.
        if ($actor->hasRole(User::ROLE_SERVICE)) {
            return [User::ROLE_AGENT];
        }

        return [User::ROLE_AGENT];
    }

    /**
     * @return array<int, string>
     */
    private function acceptedRoleOptions(?User $actor = null, ?User $subject = null): array
    {
        return array_values(array_unique([
            ...$this->roleOptions($actor, $subject),
            ...array_keys($this->roleRegistry->deprecatedRoleMap()),
        ]));
    }

    private function denyUnlessManagedUserAccessible(User $actor, User $target): void
    {
        if ($actor->hasGlobalReadAccess()) {
            return;
        }

        if ($actor->hasRole(User::ROLE_DIRECTION) && (int) $actor->direction_id === (int) $target->direction_id) {
            return;
        }

        if ($actor->hasRole(User::ROLE_SERVICE)
            && (int) $actor->direction_id === (int) $target->direction_id
            && (int) $actor->service_id === (int) $target->service_id
        ) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function enforceManagedUserScope(User $actor, array $validated): void
    {
        if ($actor->hasGlobalReadAccess()) {
            return;
        }

        $directionId = isset($validated['direction_id']) ? (int) $validated['direction_id'] : null;
        $serviceId = isset($validated['service_id']) ? (int) $validated['service_id'] : null;

        if ($actor->hasRole(User::ROLE_DIRECTION)) {
            if ($directionId !== (int) $actor->direction_id) {
                throw ValidationException::withMessages([
                    'direction_id' => 'Le compte doit rester dans votre direction.',
                ]);
            }

            return;
        }

        if ($actor->hasRole(User::ROLE_SERVICE)) {
            if ($directionId !== (int) $actor->direction_id || $serviceId !== (int) $actor->service_id) {
                throw ValidationException::withMessages([
                    'service_id' => 'Le compte doit rester dans votre service.',
                ]);
            }
        }
    }
}
