<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\Direction;
use App\Models\PaoObjectifOperationnel;
use App\Models\Service;
use App\Models\User;
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
        private readonly AntivirusScanner $scanner
    ) {
    }

    public function directionsIndex(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielReader($user);

        $query = Direction::query()
            ->withCount(['services', 'users', 'paos', 'ptas'])
            ->orderBy('code');

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

        return view('workspace.referentiel.directions.index', [
            'rows' => $query->paginate(20)->withQueryString(),
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
            ->with('success', 'Direction creee avec succes.');
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
            ->with('success', 'Direction mise a jour avec succes.');
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
            ->with('success', 'Direction supprimee avec succes.');
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

        return view('workspace.referentiel.services.index', [
            'rows' => $query->paginate(20)->withQueryString(),
            'directionOptions' => Direction::query()->orderBy('code')->get(['id', 'code', 'libelle', 'actif']),
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
            'directionOptions' => Direction::query()->orderBy('code')->get(['id', 'code', 'libelle', 'actif']),
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
            ->with('success', 'Service cree avec succes.');
    }

    public function servicesEdit(Request $request, Service $service): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        return view('workspace.referentiel.services.form', [
            'mode' => 'edit',
            'row' => $service,
            'directionOptions' => Direction::query()->orderBy('code')->get(['id', 'code', 'libelle', 'actif']),
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
            ->with('success', 'Service mis a jour avec succes.');
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
            ->with('success', 'Service supprime avec succes.');
    }

    public function utilisateursIndex(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessRoleManager($user);

        $query = User::query()
            ->with([
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
            ])
            ->orderBy('name');

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

        return view('workspace.referentiel.utilisateurs.index', [
            'rows' => $query->paginate(20)->withQueryString(),
            'canWrite' => $this->canManageRoles($user),
            'directionOptions' => Direction::query()->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => Service::query()->with('direction:id,code')->orderBy('direction_id')->orderBy('code')
                ->get(['id', 'direction_id', 'code', 'libelle']),
            'roleOptions' => $this->roleOptions(),
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
        $this->denyUnlessRoleManager($user);

        return view('workspace.referentiel.utilisateurs.form', [
            'mode' => 'create',
            'row' => new User(),
            'directionOptions' => Direction::query()->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => Service::query()->with('direction:id,code')->orderBy('direction_id')->orderBy('code')
                ->get(['id', 'direction_id', 'code', 'libelle']),
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    public function utilisateursStore(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessRoleManager($user);

        $validated = $this->validateUtilisateur($request, true);
        $this->applyRoleScopeRules($validated);
        $profilePhotoPath = $this->storeProfilePhoto($request);

        $created = DB::transaction(function () use ($validated, $profilePhotoPath, $request): User {
            $created = User::query()->create([
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
                'password' => 'temp-password-placeholder',
                'password_changed_at' => now(),
            ]);

            $this->passwordPolicy->persistPassword($created, (string) $validated['password']);

            return $created->fresh();
        });

        $this->recordAudit($request, 'referentiel_utilisateur', 'create', $created, null, $created->toArray());

        return redirect()
            ->route('workspace.referentiel.utilisateurs.index')
            ->with('success', 'Utilisateur cree avec succes.');
    }

    public function utilisateursEdit(Request $request, User $utilisateur): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessRoleManager($user);

        return view('workspace.referentiel.utilisateurs.form', [
            'mode' => 'edit',
            'row' => $utilisateur,
            'directionOptions' => Direction::query()->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => Service::query()->with('direction:id,code')->orderBy('direction_id')->orderBy('code')
                ->get(['id', 'direction_id', 'code', 'libelle']),
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    public function utilisateursUpdate(Request $request, User $utilisateur): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessRoleManager($user);

        $validated = $this->validateUtilisateur($request, false, $utilisateur);
        $this->applyRoleScopeRules($validated);

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
        ];

        if (! empty($validated['password'])) {
            $this->passwordPolicy->validateNotReused($utilisateur, (string) $validated['password']);
        }

        $payload = array_merge($payload, $this->resolveProfilePhotoPayloadForUpdate($request, $utilisateur));

        $before = $utilisateur->toArray();
        DB::transaction(function () use ($utilisateur, $payload, $validated): void {
            $utilisateur->fill($payload);
            $utilisateur->save();

            if (! empty($validated['password'])) {
                $this->passwordPolicy->persistPassword($utilisateur, (string) $validated['password']);
            }
        });
        $utilisateur->refresh();

        $this->recordAudit($request, 'referentiel_utilisateur', 'update', $utilisateur, $before, $utilisateur->toArray());

        return redirect()
            ->route('workspace.referentiel.utilisateurs.index')
            ->with('success', 'Utilisateur mis a jour avec succes.');
    }

    public function utilisateursDestroy(Request $request, User $utilisateur): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessRoleManager($user);

        if ((int) $utilisateur->id === (int) $user->id) {
            return back()->withErrors(['general' => 'Vous ne pouvez pas supprimer votre propre compte.']);
        }

        $usedAsResponsable = PaoObjectifOperationnel::query()
            ->where('responsable_id', (int) $utilisateur->id)
            ->exists();
        $usedAsActionResponsable = Action::query()
            ->where('responsable_id', (int) $utilisateur->id)
            ->exists();

        if ($usedAsResponsable || $usedAsActionResponsable) {
            return back()->withErrors([
                'general' => 'Suppression impossible: utilisateur deja responsable d actions ou d objectifs operationnels.',
            ]);
        }

        if (is_string($utilisateur->profile_photo_path) && trim($utilisateur->profile_photo_path) !== '') {
            Storage::disk('public')->delete($utilisateur->profile_photo_path);
        }

        $before = $utilisateur->toArray();
        $utilisateur->delete();

        $this->recordAudit($request, 'referentiel_utilisateur', 'delete', $utilisateur, $before, null);

        return redirect()
            ->route('workspace.referentiel.utilisateurs.index')
            ->with('success', 'Utilisateur supprime avec succes.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateUtilisateur(Request $request, bool $creating, ?User $utilisateur = null): array
    {
        $emailRule = Rule::unique('users', 'email');
        if (! $creating && $utilisateur !== null) {
            $emailRule = $emailRule->ignore($utilisateur->id);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', $emailRule],
            'role' => ['required', Rule::in($this->roleOptions())],
            'is_active' => ['nullable', 'boolean'],
            'agent_matricule' => ['nullable', 'string', 'max:80'],
            'agent_fonction' => ['nullable', 'string', 'max:120'],
            'agent_telephone' => ['nullable', 'string', 'max:40'],
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
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
        $role = (string) $validated['role'];
        $directionId = isset($validated['direction_id']) ? (int) $validated['direction_id'] : null;
        $serviceId = isset($validated['service_id']) ? (int) $validated['service_id'] : null;

        if ($role === User::ROLE_SERVICE || $role === User::ROLE_AGENT) {
            if ($directionId === null || $serviceId === null) {
                throw ValidationException::withMessages([
                    'direction_id' => 'Direction et service sont obligatoires pour un profil service/agent.',
                ]);
            }

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
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    private function denyUnlessReferentielWriter(User $user): void
    {
        $this->denyUnlessGlobalWriter($user);
    }

    private function denyUnlessRoleManager(User $user): void
    {
        if ($this->canManageRoles($user)) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess();
    }

    private function canManageRoles(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    /**
     * @return array<int, string>
     */
    private function roleOptions(): array
    {
        return [
            User::ROLE_ADMIN,
            User::ROLE_DG,
            User::ROLE_PLANIFICATION,
            User::ROLE_DIRECTION,
            User::ROLE_SERVICE,
            User::ROLE_AGENT,
            User::ROLE_CABINET,
        ];
    }
}
