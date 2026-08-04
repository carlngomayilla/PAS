<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Models\Direction;
use App\Models\Service;
use App\Models\UniteDg;
use App\Models\User;
use App\Services\ChefUniteSyncService;
use App\Services\DeletionRequestService;
use App\Services\Organization\OrganizationDirectoryService;
use App\Services\RoleRegistryService;
use App\Services\Security\AntivirusScanner;
use App\Services\Security\MalwareScanException;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReferentielWebController extends Controller
{
    use AuthorizesPlanningScope;
    use RecordsAuditTrail;

    public function __construct(
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly AntivirusScanner $scanner,
        private readonly DeletionRequestService $deletionRequestService,
        private readonly ChefUniteSyncService $chefUniteSync,
        private readonly RoleRegistryService $roleRegistry,
        private readonly OrganizationDirectoryService $organizationDirectoryService
    ) {}

    public function directionsIndex(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielReader($user);
        $filters = $this->organizationDirectoryService->normalizeDirectionFilters($request->query());
        $workspace = $this->organizationDirectoryService->directionsWorkspace($user, $filters);

        return view('workspace.referentiel.directions.index', [
            'rows' => $workspace['rows'],
            'summary' => $workspace['summary'],
            'canWrite' => $this->canWrite($user),
            'canManageRoles' => $this->canManageRoles($user),
            'filters' => $filters,
        ]);
    }

    public function directionsExport(Request $request): StreamedResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielReader($user);
        $filters = $this->organizationDirectoryService->normalizeDirectionFilters($request->query());

        return $this->streamCsv('referentiel-directions', function ($stream) use ($user, $filters): void {
            $this->organizationDirectoryService->writeDirectionsCsv($stream, $user, $filters);
        });
    }

    public function directionsCreate(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        return view('workspace.referentiel.directions.form', [
            'mode' => 'create',
            'row' => new Direction,
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
        $filters = $this->organizationDirectoryService->normalizeServiceFilters($request->query());
        $workspace = $this->organizationDirectoryService->servicesWorkspace($user, $filters);

        return view('workspace.referentiel.services.index', [
            'rows' => $workspace['rows'],
            'summary' => $workspace['summary'],
            'directionOptions' => $this->activeDirectionOptions(['id', 'code', 'libelle', 'actif']),
            'canWrite' => $this->canWrite($user),
            'canManageRoles' => $this->canManageRoles($user),
            'filters' => $filters,
        ]);
    }

    public function servicesExport(Request $request): StreamedResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielReader($user);
        $filters = $this->organizationDirectoryService->normalizeServiceFilters($request->query());

        return $this->streamCsv('referentiel-services', function ($stream) use ($user, $filters): void {
            $this->organizationDirectoryService->writeServicesCsv($stream, $user, $filters);
        });
    }

    public function servicesCreate(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielWriter($user);

        return view('workspace.referentiel.services.form', [
            'mode' => 'create',
            'row' => new Service,
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
        $filters = $this->organizationDirectoryService->normalizeUserFilters($request->query());
        $workspace = $this->organizationDirectoryService->usersWorkspace($user, $filters);

        return view('workspace.referentiel.utilisateurs.index', [
            'rows' => $workspace['rows'],
            'summary' => $workspace['summary'],
            'healthByUserId' => $workspace['health'],
            'canWrite' => $this->canManageUsers($user),
            'canDeleteUsers' => $user->isSuperAdmin(),
            'canRequestUserDeletion' => $this->canRequestAnyUserDeletion($user),
            'canManageRoles' => $this->canManageRoles($user),
            'directionOptions' => $this->activeDirectionOptions(),
            'serviceOptions' => Service::query()->with('direction:id,code')->orderBy('direction_id')->orderBy('code')
                ->get(['id', 'direction_id', 'code', 'libelle']),
            'roleOptions' => $this->roleOptions($user),
            'filters' => $filters,
        ]);
    }

    public function utilisateursExport(Request $request): StreamedResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielReader($user);
        $filters = $this->organizationDirectoryService->normalizeUserFilters($request->query());

        return $this->streamCsv('referentiel-utilisateurs', function ($stream) use ($user, $filters): void {
            $this->organizationDirectoryService->writeUsersCsv($stream, $user, $filters);
        });
    }

    /**
     * Export Word (.doc) de la liste des utilisateurs — document HTML mis en forme
     * ouvrable par Microsoft Word (aucune dependance externe requise).
     */
    public function utilisateursExportWord(Request $request): Response
    {
        $user = $this->authUser($request);
        $this->denyUnlessReferentielReader($user);
        $filters = $this->organizationDirectoryService->normalizeUserFilters($request->query());
        $rows = $this->organizationDirectoryService->usersForWordExport($user, $filters);

        $html = view('workspace.referentiel.utilisateurs.export-word', [
            'rows' => $rows,
            'generatedAt' => now()->format('d/m/Y à H:i'),
        ])->render();

        $filename = 'referentiel-utilisateurs-'.now()->format('Ymd-His').'.doc';

        return response($html, 200, [
            'Content-Type' => 'application/msword; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function utilisateursCreate(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessUserManager($user);

        return view('workspace.referentiel.utilisateurs.form', [
            'mode' => 'create',
            'row' => new User,
            'directionOptions' => $this->activeDirectionOptions(),
            'serviceOptions' => Service::query()->with('direction:id,code')->orderBy('direction_id')->orderBy('code')
                ->get(['id', 'direction_id', 'code', 'libelle']),
            'uniteDgOptions' => UniteDg::query()->where('actif', true)->orderBy('code')->get(['id', 'code', 'libelle']),
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
        $passwordWasGenerated = trim((string) ($validated['password'] ?? '')) === '';
        $initialPassword = $passwordWasGenerated
            ? $this->passwordPolicy->generateInitialPassword()
            : (string) $validated['password'];

        $created = DB::transaction(function () use ($validated, $profilePhotoPath, $request, $initialPassword): User {
            // forceCreate : role / direction_id / service_id / unite_dg_id / is_active /
            // is_agent / agent_* ne sont plus mass-assignables (cf. A02). Cette voie
            // est reservee aux admins et tous les champs sont valides en amont.
            $created = User::query()->forceCreate([
                'name' => (string) $validated['name'],
                'profile_photo_path' => $profilePhotoPath,
                'email' => (string) $validated['email'],
                'role' => (string) $validated['role'],
                'custom_role_code' => $validated['custom_role_code'] ?? null,
                'is_active' => $request->boolean('is_active', true),
                'is_agent' => (string) $validated['role'] === User::ROLE_AGENT,
                'agent_matricule' => $validated['agent_matricule'] ?? null,
                'agent_fonction' => $validated['agent_fonction'] ?? null,
                'agent_telephone' => $validated['agent_telephone'] ?? null,
                'direction_id' => $validated['direction_id'] ?? null,
                'service_id' => $validated['service_id'] ?? null,
                'unite_dg_id' => $validated['unite_dg_id'] ?? null,
                'password' => 'temp-password-placeholder',
                'password_changed_at' => null,
            ]);

            $this->passwordPolicy->persistPassword($created, $initialPassword, forceRenewal: true);
            $this->chefUniteSync->sync($created);

            return $created->fresh();
        });

        $this->recordAudit($request, 'referentiel_utilisateur', 'create', $created, null, $created->toArray());

        $redirect = redirect()
            ->route('workspace.referentiel.utilisateurs.index')
            ->with('success', 'Utilisateur cree avec succès.');

        if ($passwordWasGenerated) {
            $redirect = $redirect
                ->with('temporary_password_value', $initialPassword)
                ->with('temporary_password_user', $created->email);
        }

        return $redirect;
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
            'uniteDgOptions' => UniteDg::query()->where('actif', true)->orderBy('code')->get(['id', 'code', 'libelle']),
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
            'custom_role_code' => $validated['custom_role_code'] ?? null,
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
                $this->passwordPolicy->persistPassword($utilisateur, (string) $validated['password'], forceRenewal: true);
                $utilisateur->tokens()->delete();
            }

            $this->chefUniteSync->sync($utilisateur);
        });
        $utilisateur->refresh();

        $this->recordAudit($request, 'referentiel_utilisateur', 'update', $utilisateur, $before, $utilisateur->toArray());

        return redirect()
            ->route('workspace.referentiel.utilisateurs.index')
            ->with('success', 'Utilisateur mis a jour avec succès.');
    }

    /**
     * Reinitialise le mot de passe d'un utilisateur (mot de passe temporaire
     * genere par l'application, changement force a la prochaine connexion).
     */
    public function utilisateurResetPassword(Request $request, User $utilisateur): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessUserManager($user);
        $this->denyUnlessManagedUserAccessible($user, $utilisateur);

        $temporaryPassword = $this->passwordPolicy->generateInitialPassword();
        $before = Arr::except($utilisateur->toArray(), ['password']);

        DB::transaction(function () use ($utilisateur, $temporaryPassword): void {
            $this->passwordPolicy->persistPassword($utilisateur, $temporaryPassword, forceRenewal: true);
            $utilisateur->tokens()->delete();
        });

        $this->recordAudit($request, 'referentiel_utilisateur', 'password_reset', $utilisateur, $before, [
            'user_id' => $utilisateur->id,
            'generated_password_reset' => true,
            'force_renewal' => true,
        ]);

        return redirect()
            ->route('workspace.referentiel.utilisateurs.index')
            ->with('success', 'Mot de passe temporaire genere pour '.$utilisateur->email.'. Changement requis a la prochaine connexion.')
            ->with('temporary_password_value', $temporaryPassword)
            ->with('temporary_password_user', $utilisateur->email);
    }

    /**
     * Reinitialise en masse les mots de passe (chaque utilisateur du perimetre
     * recoit un mot de passe temporaire genere par l'application). Les comptes
     * hors perimetre de l'acteur sont ignores.
     */
    public function utilisateursBulkResetPassword(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessUserManager($user);

        $resetAll = $request->boolean('reset_all');

        $validated = $request->validate([
            'reset_all' => ['nullable', 'boolean'],
            'user_ids' => [$resetAll ? 'nullable' : 'required', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $generated = [];

        DB::transaction(function () use ($validated, $resetAll, $user, &$generated): void {
            $targets = $resetAll
                ? User::query()->where('id', '!=', $user->id)->orderBy('id')->get()
                : User::query()->whereIn('id', $validated['user_ids'] ?? [])->get();

            foreach ($targets as $target) {
                try {
                    $this->denyUnlessManagedUserAccessible($user, $target);
                } catch (HttpException) {
                    continue; // Hors perimetre : ignore.
                }

                $password = $this->passwordPolicy->generateInitialPassword();
                $this->passwordPolicy->persistPassword($target, $password, forceRenewal: true);
                $target->tokens()->delete();

                $generated[] = [
                    'name' => (string) $target->name,
                    'email' => (string) $target->email,
                    'matricule' => (string) $target->agent_matricule,
                    'password' => $password,
                ];
            }
        });

        $this->recordAudit($request, 'referentiel_utilisateur', 'bulk_password_reset', $user, null, [
            'count' => count($generated),
            'user_ids' => $validated['user_ids'],
        ]);

        return redirect()
            ->route('workspace.referentiel.utilisateurs.index')
            ->with('success', count($generated).' mot(s) de passe temporaire(s) genere(s). Changement requis a la premiere connexion.')
            ->with('bulk_reset_credentials', $generated);
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
        $matriculeRule = Rule::unique('users', 'agent_matricule');
        if (! $creating && $utilisateur !== null) {
            $emailRule = $emailRule->ignore($utilisateur->id);
            $matriculeRule = $matriculeRule->ignore($utilisateur->id);
        }

        $matricule = ! $creating && ! $request->exists('agent_matricule')
            ? $utilisateur?->agent_matricule
            : $request->input('agent_matricule');

        $request->merge([
            'agent_matricule' => User::normalizeAgentMatricule($matricule),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', $emailRule],
            'role' => ['required', Rule::in($this->acceptedRoleOptions($actor, $utilisateur))],
            'is_active' => ['nullable', 'boolean'],
            'agent_matricule' => [
                Rule::requiredIf(fn (): bool => $this->roleRegistry->baseRole((string) $request->input('role')) === User::ROLE_AGENT),
                'nullable',
                'string',
                'max:80',
                $matriculeRule,
            ],
            'agent_fonction' => ['nullable', 'string', 'max:120'],
            'agent_telephone' => ['nullable', 'string', 'max:40'],
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'unite_dg_id' => ['nullable', 'integer', 'exists:unites_dg,id'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'remove_profile_photo' => ['nullable', 'boolean'],
            // A la creation, le mot de passe est optionnel : si l admin ne saisit
            // rien, on applique le mot de passe par defaut (cf. utilisateursStore).
            'password' => $creating
                ? ['nullable', 'string', $this->passwordPolicy->rule(), 'confirmed']
                : ['nullable', 'string', $this->passwordPolicy->rule(false), 'confirmed'],
        ]);

        $validated['agent_matricule'] = User::normalizeAgentMatricule($validated['agent_matricule'] ?? null);
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
     * @param  array<string, mixed>  $validated
     */
    private function applyRoleScopeRules(array &$validated): void
    {
        $selectedRole = (string) $validated['role'];
        $role = $this->roleRegistry->baseRole($selectedRole);
        $validated['role'] = $role;
        $validated['custom_role_code'] = $this->roleRegistry->isCustomRole($selectedRole) ? $selectedRole : null;
        $directionId = isset($validated['direction_id']) ? (int) $validated['direction_id'] : null;
        $serviceId = isset($validated['service_id']) ? (int) $validated['service_id'] : null;

        // Matricule obligatoire pour TOUS les profils, sans exception.
        if (trim((string) ($validated['agent_matricule'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'agent_matricule' => 'Le matricule est obligatoire pour tout utilisateur.',
            ]);
        }
        $validated['agent_matricule'] = User::normalizeAgentMatricule($validated['agent_matricule']);

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
                if (trim((string) ($validated['agent_fonction'] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        'agent_fonction' => 'La fonction est obligatoire pour le role agent.',
                    ]);
                }
            } else {
                $validated['agent_fonction'] = null;
                $validated['agent_telephone'] = null;
            }

            return;
        }

        // Autres profils : le matricule reste requis (deja verifie ci-dessus),
        // seuls fonction/telephone (specifiques agent) sont neutralises.
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

        if ($role === User::ROLE_CHEF_PLANIFICATION) {
            if ($directionId === null) {
                throw ValidationException::withMessages([
                    'direction_id' => 'La direction est obligatoire pour le profil Chef planification.',
                ]);
            }

            $direction = Direction::query()->find($directionId);
            if ($direction === null) {
                throw ValidationException::withMessages([
                    'direction_id' => 'La direction selectionnee est invalide.',
                ]);
            }

            if ((string) $direction->code === 'DG') {
                if ($serviceId !== null) {
                    throw ValidationException::withMessages([
                        'service_id' => 'Le service doit etre vide pour un rattachement DG.',
                    ]);
                }

                return;
            }

            $this->ensureOperationalDirectionAllowed($directionId);

            if ($serviceId === null) {
                throw ValidationException::withMessages([
                    'service_id' => 'Le service est obligatoire pour un Chef planification hors DG.',
                ]);
            }

            $service = Service::query()->find($serviceId);
            if ($service === null || (int) $service->direction_id !== $directionId) {
                throw ValidationException::withMessages([
                    'service_id' => 'Le service doit appartenir a la direction selectionnee.',
                ]);
            }

            return;
        }

        if (in_array($role, [
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_CHEF_UNITE_CABINET,
            User::ROLE_CHEF_UNITE_DGA,
            User::ROLE_CHEF_UNITE_UCAS,
        ], true)) {
            if ($directionId === null) {
                throw ValidationException::withMessages([
                    'direction_id' => 'La direction DG est obligatoire pour ce profil chef.',
                ]);
            }

            $direction = Direction::query()->find($directionId);
            if (! $direction || (string) $direction->code !== 'DG') {
                throw ValidationException::withMessages([
                    'direction_id' => 'Ce profil chef doit etre rattache a la direction DG.',
                ]);
            }

            if ($serviceId !== null) {
                throw ValidationException::withMessages([
                    'service_id' => 'Le service doit etre vide pour ce profil chef DG.',
                ]);
            }

            $uniteDgId = isset($validated['unite_dg_id']) ? (int) $validated['unite_dg_id'] : null;
            if ($uniteDgId === null || ! UniteDg::query()->whereKey($uniteDgId)->exists()) {
                throw ValidationException::withMessages([
                    'unite_dg_id' => 'L unite DG est obligatoire pour ce profil chef.',
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

    /**
     * @param  callable(resource): void  $writer
     */
    private function streamCsv(string $prefix, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                abort(500, 'Impossible de générer le fichier du référentiel.');
            }

            try {
                $writer($stream);
            } finally {
                fclose($stream);
            }
        }, $prefix.'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
            return $subject instanceof User ? [$subject->effectiveRoleCode()] : [User::ROLE_AGENT];
        }

        if ($actor->isPlanningControlChief()) {
            return $this->planningControlChiefManagedRoles();
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
        if ($actor?->isPlanningControlChief()) {
            return $this->planningControlChiefManagedRoles();
        }

        return array_values(array_unique([
            ...$this->roleOptions($actor, $subject),
            ...array_keys($this->roleRegistry->deprecatedRoleMap()),
        ]));
    }

    private function denyUnlessManagedUserAccessible(User $actor, User $target): void
    {
        if ($actor->isPlanningControlChief()) {
            if (in_array($this->roleRegistry->baseRole((string) $target->role), $this->planningControlChiefManagedRoles(), true)) {
                return;
            }

            abort(403, 'Acces non autorise.');
        }

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
     * @return array<int, string>
     */
    private function planningControlChiefManagedRoles(): array
    {
        return [
            User::ROLE_DIRECTION,
            User::ROLE_SERVICE,
            User::ROLE_AGENT,
        ];
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
