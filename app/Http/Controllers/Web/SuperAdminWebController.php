<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Models\Direction;
use App\Models\ExportTemplate;
use App\Models\ExportTemplateAssignment;
use App\Models\ExportTemplateVersion;
use App\Models\JournalAudit;
use App\Models\PlatformSetting;
use App\Models\PlatformSettingSnapshot;
use App\Models\Service;
use App\Models\User;
use App\Services\AppearanceSettings;
use App\Services\ActionCalculationSettings;
use App\Services\ActionManagementSettings;
use App\Services\DashboardProfileSettings;
use App\Services\DocumentPolicySettings;
use App\Services\DynamicReferentialSettings;
use App\Services\Exports\ExportTemplatePublisher;
use App\Services\ManagedKpiSettings;
use App\Services\NotificationPolicySettings;
use App\Services\OrganizationGovernanceService;
use App\Services\PlatformDiagnosticService;
use App\Services\PlatformSimulationService;
use App\Services\PlatformSnapshotService;
use App\Services\PlatformSettings;
use App\Services\PlatformMaintenanceService;
use App\Services\RoleRegistryService;
use App\Services\RolePermissionSettings;
use App\Services\Security\PasswordPolicyService;
use App\Services\WorkflowSettings;
use App\Services\WorkspaceModuleSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SuperAdminWebController extends Controller
{
    use RecordsAuditTrail;

    public function __construct(
        private readonly ExportTemplatePublisher $templatePublisher,
        private readonly ActionCalculationSettings $actionCalculationSettings,
        private readonly ActionManagementSettings $actionManagementSettings,
        private readonly AppearanceSettings $appearanceSettings,
        private readonly DashboardProfileSettings $dashboardProfileSettings,
        private readonly DocumentPolicySettings $documentPolicySettings,
        private readonly PlatformSettings $platformSettings,
        private readonly PlatformMaintenanceService $maintenanceService,
        private readonly DynamicReferentialSettings $dynamicReferentialSettings,
        private readonly ManagedKpiSettings $managedKpiSettings,
        private readonly NotificationPolicySettings $notificationPolicySettings,
        private readonly OrganizationGovernanceService $organizationGovernanceService,
        private readonly PlatformDiagnosticService $platformDiagnosticService,
        private readonly PlatformSimulationService $platformSimulationService,
        private readonly PlatformSnapshotService $platformSnapshotService,
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly RoleRegistryService $roleRegistry,
        private readonly RolePermissionSettings $rolePermissionSettings,
        private readonly WorkflowSettings $workflowSettings,
        private readonly WorkspaceModuleSettings $workspaceModuleSettings
    ) {
    }

    public function index(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $templateQuery = ExportTemplate::query();

        return view('workspace.super_admin.index', [
            'summary' => [
                'active_users' => User::query()->where('is_active', true)->count(),
                'modules_active' => $this->workspaceModuleSettings->activeCount(),
                'templates_total' => (clone $templateQuery)->count(),
                'templates_published' => (clone $templateQuery)->where('status', ExportTemplate::STATUS_PUBLISHED)->count(),
                'assignments_active' => ExportTemplateAssignment::query()->where('is_active', true)->count(),
                'system_changes' => JournalAudit::query()->whereIn('module', ['super_admin', 'export_template'])->count(),
                'official_base' => $this->actionCalculationSettings->statisticalScopeLabel(),
                'default_theme' => $this->appearanceSettings->get('default_theme', 'dark'),
                'default_locale' => $this->platformSettings->locale(),
                'default_timezone' => $this->platformSettings->timezone(),
                'maintenance_active' => $this->maintenanceService->status()['maintenance_active'],
                'permission_groups' => count($this->rolePermissionSettings->groupedPermissions()),
                'dashboard_profiles' => count($this->dashboardProfileSettings->all()),
                'dynamic_referentials' => count($this->dynamicReferentialSettings->all()),
                'managed_kpis' => $this->managedKpiSettings->summary()['visible'],
                'notification_events_enabled' => $this->notificationPolicySettings->summary()['events_enabled'],
                'timeline_rules_enabled' => $this->notificationPolicySettings->summary()['timeline_rules_enabled'],
                'diagnostic_alerts' => collect($this->platformDiagnosticService->checks())
                    ->where('status', '!=', 'ok')
                    ->count(),
                'sessions_active' => $this->activeSessionsCount(),
                'configuration_snapshots' => PlatformSettingSnapshot::query()->count(),
                'action_policy_closure_threshold' => $this->actionManagementSettings->minProgressForClosure(),
            ],
            'recentAudits' => JournalAudit::query()
                ->with('user:id,name,email')
                ->whereIn('module', ['super_admin', 'export_template'])
                ->latest('id')
                ->limit(12)
                ->get(),
        ]);
    }

    public function settingsEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $settings = $this->platformSettings->editable();
        $publishedSettings = $this->platformSettings->all();

        return view('workspace.super_admin.settings', [
            'settings' => $settings,
            'publishedSettings' => $publishedSettings,
            'hasDraft' => $this->platformSettings->hasDraft(),
            'draftUpdatedAt' => $this->platformSettings->draftUpdatedAt(),
            'localeOptions' => $this->platformSettings->localeOptions(),
            'timezoneOptions' => $this->platformSettings->timezoneOptions(),
            'dateFormatOptions' => $this->platformSettings->dateFormatOptions(),
            'dateTimeFormatOptions' => $this->platformSettings->dateTimeFormatOptions(),
            'numberPrecisionOptions' => $this->platformSettings->numberPrecisionOptions(),
        ]);
    }

    public function settingsUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:120'],
            'app_short_name' => ['required', 'string', 'max:40'],
            'institution_label' => ['required', 'string', 'max:180'],
            'default_locale' => ['required', Rule::in(array_keys($this->platformSettings->localeOptions()))],
            'default_timezone' => ['required', Rule::in(array_keys($this->platformSettings->timezoneOptions()))],
            'date_format' => ['required', Rule::in(array_keys($this->platformSettings->dateFormatOptions()))],
            'datetime_format' => ['required', Rule::in(array_keys($this->platformSettings->dateTimeFormatOptions()))],
            'number_precision' => ['required', Rule::in(array_keys($this->platformSettings->numberPrecisionOptions()))],
            'number_decimal_separator' => ['required', 'string', 'max:2'],
            'number_thousands_separator' => ['required', 'string', 'max:2'],
            'sidebar_caption' => ['required', 'string', 'max:40'],
            'admin_header_eyebrow' => ['required', 'string', 'max:80'],
            'guest_space_label' => ['required', 'string', 'max:80'],
            'login_page_title' => ['required', 'string', 'max:120'],
            'login_welcome_title' => ['required', 'string', 'max:160'],
            'login_welcome_text' => ['required', 'string', 'max:255'],
            'login_form_title' => ['required', 'string', 'max:120'],
            'login_form_subtitle' => ['required', 'string', 'max:180'],
            'login_identifier_label' => ['required', 'string', 'max:80'],
            'login_identifier_placeholder' => ['required', 'string', 'max:120'],
            'login_helper_text' => ['required', 'string', 'max:255'],
            'footer_text' => ['required', 'string', 'max:255'],
            'logo_mark' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg'],
            'logo_wordmark' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg'],
            'logo_full' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg'],
            'favicon' => ['nullable', 'file', 'max:1024', 'mimes:png,ico,svg'],
        ]);

        $before = $this->platformSettings->all();
        $validated = array_merge($validated, $this->storeBrandingAssets($request));
        $after = $this->platformSettings->updateGeneral($validated, $user);
        $auditTarget = PlatformSetting::query()
            ->where('group', 'general')
            ->orderBy('id')
            ->first();

        if ($auditTarget instanceof PlatformSetting) {
            $this->recordAudit($request, 'super_admin', 'general_settings_update', $auditTarget, $before, $after);
        }

        return redirect()
            ->route('workspace.super-admin.settings.edit')
            ->with('success', 'Parametres generaux mis a jour.');
    }

    public function settingsDraftUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:120'],
            'app_short_name' => ['required', 'string', 'max:40'],
            'institution_label' => ['required', 'string', 'max:180'],
            'default_locale' => ['required', Rule::in(array_keys($this->platformSettings->localeOptions()))],
            'default_timezone' => ['required', Rule::in(array_keys($this->platformSettings->timezoneOptions()))],
            'date_format' => ['required', Rule::in(array_keys($this->platformSettings->dateFormatOptions()))],
            'datetime_format' => ['required', Rule::in(array_keys($this->platformSettings->dateTimeFormatOptions()))],
            'number_precision' => ['required', Rule::in(array_keys($this->platformSettings->numberPrecisionOptions()))],
            'number_decimal_separator' => ['required', 'string', 'max:2'],
            'number_thousands_separator' => ['required', 'string', 'max:2'],
            'sidebar_caption' => ['required', 'string', 'max:40'],
            'admin_header_eyebrow' => ['required', 'string', 'max:80'],
            'guest_space_label' => ['required', 'string', 'max:80'],
            'login_page_title' => ['required', 'string', 'max:120'],
            'login_welcome_title' => ['required', 'string', 'max:160'],
            'login_welcome_text' => ['required', 'string', 'max:255'],
            'login_form_title' => ['required', 'string', 'max:120'],
            'login_form_subtitle' => ['required', 'string', 'max:180'],
            'login_identifier_label' => ['required', 'string', 'max:80'],
            'login_identifier_placeholder' => ['required', 'string', 'max:120'],
            'login_helper_text' => ['required', 'string', 'max:255'],
            'footer_text' => ['required', 'string', 'max:255'],
            'logo_mark' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg'],
            'logo_wordmark' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg'],
            'logo_full' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg'],
            'favicon' => ['nullable', 'file', 'max:1024', 'mimes:png,ico,svg'],
        ]);

        $before = $this->platformSettings->editable();
        $validated = array_merge($validated, $this->storeBrandingAssets($request));
        $after = $this->platformSettings->updateDraft($validated, $user);
        $auditTarget = PlatformSetting::query()
            ->where('group', 'general_draft')
            ->orderBy('id')
            ->first()
            ?? $this->auditAnchor('general_draft', 'general_draft_app_name', 'general-settings-draft-update');

        $this->recordAudit($request, 'super_admin', 'general_settings_draft_update', $auditTarget, $before, $after);

        return redirect()
            ->route('workspace.super-admin.settings.edit')
            ->with('success', 'Brouillon des parametres generaux enregistre.');
    }

    public function settingsPublishDraft(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        if (! $this->platformSettings->hasDraft()) {
            return redirect()
                ->route('workspace.super-admin.settings.edit')
                ->with('success', 'Aucun brouillon a publier.');
        }

        $before = $this->platformSettings->all();
        $draft = $this->platformSettings->draft();
        $after = $this->platformSettings->publishDraft($user);
        $auditTarget = PlatformSetting::query()
            ->where('group', 'general')
            ->orderBy('id')
            ->first()
            ?? $this->auditAnchor('general', 'app_name', 'general-settings-draft-publish');

        $this->recordAudit($request, 'super_admin', 'general_settings_draft_publish', $auditTarget, [
            'published' => $before,
            'draft' => $draft,
        ], $after);

        return redirect()
            ->route('workspace.super-admin.settings.edit')
            ->with('success', 'Brouillon des parametres generaux publie.');
    }

    public function settingsDiscardDraft(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        if (! $this->platformSettings->hasDraft()) {
            return redirect()
                ->route('workspace.super-admin.settings.edit')
                ->with('success', 'Aucun brouillon a supprimer.');
        }

        $before = $this->platformSettings->draft();
        $auditTarget = PlatformSetting::query()
            ->where('group', 'general_draft')
            ->orderBy('id')
            ->first()
            ?? $this->auditAnchor('super_admin_meta', 'general_draft_discard_anchor', 'general-settings-draft-discard');
        $this->platformSettings->discardDraft();

        $this->recordAudit($request, 'super_admin', 'general_settings_draft_discard', $auditTarget, $before, null);

        return redirect()
            ->route('workspace.super-admin.settings.edit')
            ->with('success', 'Brouillon des parametres generaux supprime.');
    }

    public function modulesEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $editableModules = collect($this->workspaceModuleSettings->editable())
            ->sortBy('order')
            ->values();
        $publishedModules = collect($this->workspaceModuleSettings->all())
            ->sortBy('order')
            ->values();

        return view('workspace.super_admin.modules', [
            'modules' => $editableModules,
            'publishedModules' => $publishedModules,
            'hasDraft' => $this->workspaceModuleSettings->hasDraft(),
            'draftUpdatedAt' => $this->workspaceModuleSettings->draftUpdatedAt(),
        ]);
    }

    public function modulesUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*.label' => ['required', 'string', 'max:80'],
            'modules.*.description' => ['required', 'string', 'max:255'],
            'modules.*.order' => ['required', 'integer', 'min:1', 'max:999'],
            'modules.*.enabled' => ['nullable', 'in:0,1'],
        ]);

        $before = $this->workspaceModuleSettings->all();
        $after = $this->workspaceModuleSettings->updateModules($validated['modules'], $user);
        $auditTarget = $this->auditAnchor('workspace_modules', 'workspace_modules_registry', 'navigation-update');

        $this->recordAudit($request, 'super_admin', 'workspace_module_settings_update', $auditTarget, $before, $after);

        return redirect()
            ->route('workspace.super-admin.modules.edit')
            ->with('success', 'Modules et navigation mis a jour.');
    }

    public function modulesDraftUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*.label' => ['required', 'string', 'max:80'],
            'modules.*.description' => ['required', 'string', 'max:255'],
            'modules.*.order' => ['required', 'integer', 'min:1', 'max:999'],
            'modules.*.enabled' => ['nullable', 'in:0,1'],
        ]);

        $before = $this->workspaceModuleSettings->editable();
        $after = $this->workspaceModuleSettings->updateDraftModules($validated['modules'], $user);
        $auditTarget = $this->auditAnchor('workspace_modules_draft', 'workspace_module_draft_registry', 'navigation-draft-update');

        $this->recordAudit($request, 'super_admin', 'workspace_module_settings_draft_update', $auditTarget, $before, $after);

        return redirect()
            ->route('workspace.super-admin.modules.edit')
            ->with('success', 'Brouillon des modules enregistre.');
    }

    public function modulesPublishDraft(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        if (! $this->workspaceModuleSettings->hasDraft()) {
            return redirect()
                ->route('workspace.super-admin.modules.edit')
                ->with('success', 'Aucun brouillon a publier.');
        }

        $before = $this->workspaceModuleSettings->all();
        $draft = $this->workspaceModuleSettings->draft();
        $after = $this->workspaceModuleSettings->publishDraft($user);
        $auditTarget = $this->auditAnchor('workspace_modules', 'workspace_modules_registry', 'navigation-draft-publish');

        $this->recordAudit($request, 'super_admin', 'workspace_module_settings_draft_publish', $auditTarget, [
            'published' => $before,
            'draft' => $draft,
        ], $after);

        return redirect()
            ->route('workspace.super-admin.modules.edit')
            ->with('success', 'Brouillon des modules publie.');
    }

    public function modulesDiscardDraft(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        if (! $this->workspaceModuleSettings->hasDraft()) {
            return redirect()
                ->route('workspace.super-admin.modules.edit')
                ->with('success', 'Aucun brouillon a supprimer.');
        }

        $before = $this->workspaceModuleSettings->draft();
        $auditTarget = PlatformSetting::query()
            ->where('group', 'workspace_modules_draft')
            ->orderBy('id')
            ->first()
            ?? $this->auditAnchor('super_admin_meta', 'workspace_modules_draft_discard_anchor', 'navigation-draft-discard');
        $this->workspaceModuleSettings->discardDraft();

        $this->recordAudit($request, 'super_admin', 'workspace_module_settings_draft_discard', $auditTarget, $before, null);

        return redirect()
            ->route('workspace.super-admin.modules.edit')
            ->with('success', 'Brouillon des modules supprime.');
    }

    public function rolesEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $roles = $this->rolePermissionSettings->roles();
        $selectedRole = (string) $request->string('simulate_role', User::ROLE_ADMIN);
        if (! array_key_exists($selectedRole, $roles)) {
            $selectedRole = User::ROLE_ADMIN;
        }

        $compareLeftRole = (string) $request->string('compare_left_role', User::ROLE_ADMIN);
        if (! array_key_exists($compareLeftRole, $roles)) {
            $compareLeftRole = User::ROLE_ADMIN;
        }

        $compareRightRole = (string) $request->string('compare_right_role', User::ROLE_DIRECTION);
        if (! array_key_exists($compareRightRole, $roles)) {
            $compareRightRole = User::ROLE_DIRECTION;
        }

        return view('workspace.super_admin.roles', [
            'roles' => $roles,
            'customRoles' => $this->roleRegistry->customRoles(),
            'roleRegistryVersions' => $this->roleRegistry->versions(),
            'baseRoleOptions' => $this->roleRegistry->systemRoles(),
            'matrix' => $this->rolePermissionSettings->all(),
            'permissionGroups' => $this->rolePermissionSettings->groupedPermissions(),
            'selectedRole' => $selectedRole,
            'selectedRoleLabel' => $roles[$selectedRole] ?? $selectedRole,
            'selectedPermissions' => $this->rolePermissionSettings->forRole($selectedRole),
            'selectedModules' => $this->modulePreviewForRole($selectedRole),
            'compareLeftRole' => $compareLeftRole,
            'compareRightRole' => $compareRightRole,
            'roleComparison' => $this->compareRolePermissions($compareLeftRole, $compareRightRole),
            'duplicateSourceRole' => (string) $request->string('duplicate_source_role', User::ROLE_SERVICE),
        ]);
    }

    public function rolesRegistryUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'custom_roles' => ['nullable', 'array'],
            'custom_roles.*.code' => ['nullable', 'string', 'max:64'],
            'custom_roles.*.label' => ['nullable', 'string', 'max:80'],
            'custom_roles.*.base_role' => ['nullable', Rule::in(array_keys($this->roleRegistry->systemRoles()))],
            'custom_roles.*.description' => ['nullable', 'string', 'max:255'],
            'custom_roles.*.active' => ['nullable', 'boolean'],
        ]);

        $before = $this->roleRegistry->customRoles();
        $beforePermissions = $this->customRolePermissionSnapshot($this->rolePermissionSettings->all());
        $after = $this->roleRegistry->updateCustomRoles($validated['custom_roles'] ?? [], $user);
        $this->rolePermissionSettings->flush();
        $syncedMatrix = $this->rolePermissionSettings->update($this->rolePermissionSettings->all(), $user);
        $afterPermissions = $this->customRolePermissionSnapshot($syncedMatrix);
        $this->roleRegistry->recordVersionSnapshot($afterPermissions, $user, 'update', 'Mise a jour du registre');
        $auditTarget = $this->auditAnchor('role_registry', 'custom_roles', 'custom-role-update');

        $this->recordAudit($request, 'super_admin', 'role_registry_update', $auditTarget, [
            'roles' => $before,
            'permissions' => $beforePermissions,
        ], [
            'roles' => $after,
            'permissions' => $afterPermissions,
        ]);

        return redirect()
            ->route('workspace.super-admin.roles.edit')
            ->with('success', 'Registre des roles personnalises mis a jour.');
    }

    public function rolesRegistryDuplicate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'source_role' => ['required', Rule::in(array_keys($this->roleRegistry->allRoles()))],
            'target_code' => ['required', 'string', 'max:64'],
            'target_label' => ['required', 'string', 'max:80'],
            'target_description' => ['nullable', 'string', 'max:255'],
        ]);

        $beforeRoles = $this->roleRegistry->customRoles();
        $beforePermissions = $this->customRolePermissionSnapshot($this->rolePermissionSettings->all());
        $result = $this->roleRegistry->duplicateRole(
            (string) $validated['source_role'],
            (string) $validated['target_code'],
            (string) $validated['target_label'],
            $validated['target_description'] ?? null,
            $user
        );

        $this->rolePermissionSettings->flush();
        $matrix = $this->rolePermissionSettings->all();
        $matrix[$result['code']] = $matrix[$validated['source_role']] ?? ($this->rolePermissionSettings->defaults()[$validated['source_role']] ?? []);
        $afterMatrix = $this->rolePermissionSettings->update($matrix, $user);
        $afterPermissions = $this->customRolePermissionSnapshot($afterMatrix);
        $this->roleRegistry->recordVersionSnapshot(
            $afterPermissions,
            $user,
            'duplicate',
            'Duplication depuis '.(string) $validated['source_role'].' vers '.$result['code']
        );
        $auditTarget = $this->auditAnchor('role_registry', 'custom_roles', 'custom-role-duplicate');

        $this->recordAudit($request, 'super_admin', 'role_registry_duplicate', $auditTarget, [
            'roles' => $beforeRoles,
            'permissions' => $beforePermissions,
        ], [
            'roles' => $result['roles'],
            'permissions' => $afterPermissions,
        ]);

        return redirect()
            ->route('workspace.super-admin.roles.edit', ['simulate_role' => $result['code']])
            ->with('success', 'Role personnalise duplique.');
    }

    public function rolesRegistryRestore(Request $request, string $versionId): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $beforeRoles = $this->roleRegistry->customRoles();
        $beforePermissions = $this->customRolePermissionSnapshot($this->rolePermissionSettings->all());
        $version = $this->roleRegistry->restoreVersion($versionId, $user);

        $this->rolePermissionSettings->flush();
        $matrix = $this->rolePermissionSettings->all();
        foreach (array_keys($this->roleRegistry->customRoles()) as $roleCode) {
            $matrix[$roleCode] = $version['permissions'][$roleCode]
                ?? ($this->rolePermissionSettings->defaults()[$roleCode] ?? []);
        }

        $afterMatrix = $this->rolePermissionSettings->update($matrix, $user);
        $afterPermissions = $this->customRolePermissionSnapshot($afterMatrix);
        $this->roleRegistry->recordVersionSnapshot(
            $afterPermissions,
            $user,
            'restore',
            'Restauration version '.$versionId
        );
        $auditTarget = $this->auditAnchor('role_registry', 'custom_roles', 'custom-role-restore');

        $this->recordAudit($request, 'super_admin', 'role_registry_restore', $auditTarget, [
            'roles' => $beforeRoles,
            'permissions' => $beforePermissions,
        ], [
            'roles' => $this->roleRegistry->customRoles(),
            'permissions' => $afterPermissions,
            'version_id' => $versionId,
        ]);

        return redirect()
            ->route('workspace.super-admin.roles.edit')
            ->with('success', 'Version du registre de roles restauree.');
    }

    public function rolesUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $permissionCodes = array_keys($this->rolePermissionSettings->permissions());
        $rules = ['permissions' => ['required', 'array']];
        foreach (array_keys($this->rolePermissionSettings->roles()) as $role) {
            $rules['permissions.'.$role] = ['nullable', 'array'];
            $rules['permissions.'.$role.'.*'] = ['string', Rule::in($permissionCodes)];
        }

        $validated = $request->validate($rules);

        $before = $this->rolePermissionSettings->all();
        $after = $this->rolePermissionSettings->update($validated['permissions'], $user);
        $this->roleRegistry->recordVersionSnapshot(
            $this->customRolePermissionSnapshot($after),
            $user,
            'permissions',
            'Matrice de permissions'
        );
        $auditTarget = $this->auditAnchor('role_permissions', 'role_permissions_matrix', 'permissions-update');

        $this->recordAudit($request, 'super_admin', 'role_permission_settings_update', $auditTarget, $before, $after);

        return redirect()
            ->route('workspace.super-admin.roles.edit', [
                'simulate_role' => (string) $request->string('simulate_role', User::ROLE_ADMIN),
            ])
            ->with('success', 'Roles et permissions mis a jour.');
    }

    public function organizationIndex(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $filteredUserIds = (clone $this->organizationUserQuery($request))
            ->select('users.id')
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $users = $this->organizationUserQuery($request)
            ->with([
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
            ])
            ->paginate(15)
            ->withQueryString();
        $sessionSummaries = $this->sessionSummariesForUsers($users->getCollection()->pluck('id')->all());
        $users->getCollection()->transform(function (User $row) use ($sessionSummaries): User {
            $summary = $sessionSummaries[(int) $row->id] ?? ['sessions_total' => 0, 'last_activity' => null];
            $row->setAttribute('sessions_total', (int) ($summary['sessions_total'] ?? 0));
            $row->setAttribute('last_session_at', $summary['last_activity'] ?? null);

            return $row;
        });
        $loginHistory = $this->loginHistoryForUsers($filteredUserIds, $request)
            ->limit(25)
            ->get();

        $editingDirection = $request->filled('edit_direction')
            ? Direction::query()->find((int) $request->integer('edit_direction'))
            : null;
        $editingService = $request->filled('edit_service')
            ? Service::query()->find((int) $request->integer('edit_service'))
            : null;
        $editingUser = $request->filled('edit_user')
            ? User::query()->with(['direction:id,code,libelle', 'service:id,direction_id,code,libelle'])->find((int) $request->integer('edit_user'))
            : null;
        $mergeSimulation = null;
        if ($request->filled('merge_source_service_id') && $request->filled('merge_target_service_id')) {
            $mergeSimulation = $this->organizationGovernanceService->simulateServiceMerge(
                Service::query()->find((int) $request->integer('merge_source_service_id')),
                Service::query()->find((int) $request->integer('merge_target_service_id'))
            );
        }
        $transferSimulation = null;
        if ($request->filled('transfer_service_id') && $request->filled('transfer_direction_id')) {
            $transferSimulation = $this->organizationGovernanceService->simulateServiceTransfer(
                Service::query()->find((int) $request->integer('transfer_service_id')),
                Direction::query()->find((int) $request->integer('transfer_direction_id'))
            );
        }

        return view('workspace.super_admin.organization', [
            'summary' => [
                'directions_active' => Direction::query()->where('actif', true)->count(),
                'directions_inactive' => Direction::query()->where('actif', false)->count(),
                'services_active' => Service::query()->where('actif', true)->count(),
                'services_inactive' => Service::query()->where('actif', false)->count(),
                'users_active' => User::query()->where('is_active', true)->count(),
                'users_inactive' => User::query()->where('is_active', false)->count(),
                'users_suspended' => User::query()
                    ->whereNotNull('suspended_until')
                    ->where('suspended_until', '>', now())
                    ->count(),
                'users_without_scope' => User::query()
                    ->whereIn('role', [User::ROLE_DIRECTION, User::ROLE_SERVICE, User::ROLE_AGENT])
                    ->where(function (Builder $query): void {
                        $query->whereNull('direction_id')
                            ->orWhere(function (Builder $subQuery): void {
                                $subQuery->whereIn('role', [User::ROLE_SERVICE, User::ROLE_AGENT])
                                    ->whereNull('service_id');
                            });
                    })
                    ->count(),
                'sessions_active' => $this->activeSessionsCount(),
                'login_events_total' => JournalAudit::query()->where('module', 'auth')->count(),
            ],
            'directionRows' => Direction::query()
                ->withCount(['services', 'users'])
                ->orderBy('code')
                ->get(),
            'serviceRows' => Service::query()
                ->with(['direction:id,code,libelle'])
                ->withCount(['users', 'ptas'])
                ->orderBy('direction_id')
                ->orderBy('code')
                ->get(),
            'userRows' => $users,
            'loginHistory' => $loginHistory,
            'directionOptions' => Direction::query()->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => Service::query()->with('direction:id,code')->orderBy('direction_id')->orderBy('code')
                ->get(['id', 'direction_id', 'code', 'libelle']),
            'roleOptions' => $this->profileOptions(),
            'roleLabels' => $this->profileLabels(),
            'bulkActionOptions' => [
                'activate' => 'Activer',
                'deactivate' => 'Desactiver',
                'suspend' => 'Suspendre',
                'clear_suspension' => 'Lever suspension',
                'revoke_sessions' => 'Couper sessions',
                'reset_password' => 'Reset mot de passe',
                'assign_role' => 'Affecter role',
                'assign_direction' => 'Affecter direction',
                'assign_service' => 'Affecter service',
            ],
            'editingDirection' => $editingDirection,
            'editingService' => $editingService,
            'editingUser' => $editingUser,
            'orgHistory' => $this->organizationGovernanceService->recentHistory(),
            'mergeSimulation' => $mergeSimulation,
            'transferSimulation' => $transferSimulation,
            'filters' => [
                'q' => (string) $request->string('q'),
                'role' => (string) $request->string('role'),
                'direction_id' => $request->filled('direction_id') ? (int) $request->integer('direction_id') : null,
                'service_id' => $request->filled('service_id') ? (int) $request->integer('service_id') : null,
                'is_active' => $request->filled('is_active') ? (string) $request->string('is_active') : '',
                'suspension_state' => (string) $request->string('suspension_state'),
                'auth_action' => (string) $request->string('auth_action'),
                'auth_date_from' => (string) $request->string('auth_date_from'),
                'auth_date_to' => (string) $request->string('auth_date_to'),
            ],
        ]);
    }

    public function organizationDirectionStore(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $this->validateDirectionPayload($request);
        $direction = Direction::query()->create($validated);

        $this->recordAudit($request, 'super_admin', 'organization_direction_create', $direction, null, $direction->toArray());

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', 'Direction creee avec succes.');
    }

    public function organizationDirectionUpdate(Request $request, Direction $direction): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $this->validateDirectionPayload($request, $direction);
        $before = $direction->toArray();
        $direction->update($validated);

        $this->recordAudit($request, 'super_admin', 'organization_direction_update', $direction, $before, $direction->toArray());

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', 'Direction mise a jour.');
    }

    public function organizationServiceStore(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $this->validateServicePayload($request);
        $service = Service::query()->create($validated);
        $service->load('direction:id,code,libelle');

        $this->recordAudit($request, 'super_admin', 'organization_service_create', $service, null, $service->toArray());

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', 'Service cree avec succes.');
    }

    public function organizationServiceUpdate(Request $request, Service $service): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $this->validateServicePayload($request, $service);
        $before = $service->toArray();
        $service->update($validated);
        $service->load('direction:id,code,libelle');

        $this->recordAudit($request, 'super_admin', 'organization_service_update', $service, $before, $service->toArray());

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', 'Service mis a jour.');
    }

    public function organizationUserStore(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $this->validateOrganizationUserPayload($request);
        $temporaryPlaceholder = 'temp-password-placeholder';

        $managedUser = DB::transaction(function () use ($validated, $request, $temporaryPlaceholder): User {
            $payload = $this->normalizeManagedUserPayload($validated, $request);
            $plainPassword = (string) ($validated['password'] ?? '');

            $managedUser = User::query()->create([
                ...$payload,
                'password' => $temporaryPlaceholder,
                'password_changed_at' => now(),
            ]);

            $this->passwordPolicy->persistPassword($managedUser, $plainPassword);

            return $managedUser->fresh(['direction:id,code,libelle', 'service:id,direction_id,code,libelle']) ?? $managedUser;
        });

        $this->recordAudit($request, 'super_admin', 'organization_user_create', $managedUser, null, Arr::except($managedUser->toArray(), ['password']));

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', 'Utilisateur cree avec succes.');
    }

    public function organizationUserUpdate(Request $request, User $managedUser): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $this->validateOrganizationUserPayload($request, $managedUser);

        $targetBaseRole = isset($validated['role'])
            ? $this->roleRegistry->baseRole((string) $validated['role'])
            : $managedUser->role;
        if ((int) $managedUser->id === (int) $user->id && (string) $targetBaseRole !== User::ROLE_SUPER_ADMIN) {
            return back()->withErrors(['role' => 'Vous ne pouvez pas retirer le role Super Admin de votre propre compte.'])->withInput();
        }

        $before = Arr::except($managedUser->toArray(), ['password']);

        DB::transaction(function () use ($managedUser, $validated, $request): void {
            $payload = $this->normalizeManagedUserPayload($validated, $request, $managedUser);
            $plainPassword = isset($validated['password']) && is_string($validated['password']) && $validated['password'] !== ''
                ? (string) $validated['password']
                : null;

            $managedUser->forceFill($payload)->save();

            if ($plainPassword !== null) {
                $this->passwordPolicy->validateNotReused($managedUser, $plainPassword);
                $this->passwordPolicy->persistPassword($managedUser, $plainPassword);
                $this->revokeSessionsForUser($managedUser);
            }
        });

        $managedUser->refresh();
        $managedUser->loadMissing(['direction:id,code,libelle', 'service:id,direction_id,code,libelle']);

        $this->recordAudit($request, 'super_admin', 'organization_user_update', $managedUser, $before, Arr::except($managedUser->toArray(), ['password']));

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', 'Utilisateur mis a jour.');
    }

    public function organizationDirectionToggle(Request $request, Direction $direction): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $before = $direction->toArray();
        $direction->update(['actif' => ! $direction->actif]);

        $this->recordAudit($request, 'super_admin', 'organization_direction_toggle', $direction, $before, $direction->toArray());

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', 'Etat de la direction mis a jour.');
    }

    public function organizationServiceToggle(Request $request, Service $service): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $before = $service->toArray();
        $service->update(['actif' => ! $service->actif]);

        $this->recordAudit($request, 'super_admin', 'organization_service_toggle', $service, $before, $service->toArray());

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', 'Etat du service mis a jour.');
    }

    public function organizationUserToggle(Request $request, User $managedUser): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        if ((int) $managedUser->id === (int) $user->id && (bool) $managedUser->is_active) {
            return back()->withErrors(['general' => 'Vous ne pouvez pas desactiver votre propre compte.']);
        }

        $before = $managedUser->toArray();
        $managedUser->update(['is_active' => ! $managedUser->is_active]);

        $sessionCount = 0;
        if (! $managedUser->is_active) {
            $sessionCount = $this->revokeSessionsForUser($managedUser);
        }

        $this->recordAudit($request, 'super_admin', 'organization_user_toggle', $managedUser, $before, [
            ...$managedUser->toArray(),
            'revoked_sessions' => $sessionCount,
        ]);

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', 'Etat du compte utilisateur mis a jour.');
    }

    public function organizationUserResetPassword(Request $request, User $managedUser): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $temporaryPassword = $this->generateSecureTemporaryPassword();
        $before = Arr::except($managedUser->toArray(), ['password']);

        DB::transaction(function () use ($managedUser, $temporaryPassword): void {
            $this->passwordPolicy->persistPassword($managedUser, $temporaryPassword);
            $this->revokeSessionsForUser($managedUser);
        });
        $managedUser->refresh();

        $this->recordAudit($request, 'super_admin', 'organization_user_password_reset', $managedUser, $before, [
            ...Arr::except($managedUser->toArray(), ['password']),
            'temporary_password_reset' => true,
        ]);

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', 'Mot de passe temporaire reinitialise.')
            ->with('temporary_password_value', $temporaryPassword)
            ->with('temporary_password_user', $managedUser->email);
    }

    public function organizationUserRevokeSessions(Request $request, User $managedUser): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $revoked = $this->revokeSessionsForUser($managedUser);

        $this->recordAudit($request, 'super_admin', 'organization_user_revoke_sessions', $managedUser, null, [
            'user_id' => $managedUser->id,
            'revoked_sessions' => $revoked,
        ]);

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', 'Sessions actives revoquees : '.$revoked.'.');
    }

    public function organizationUsersExport(Request $request): StreamedResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $query = $this->organizationUserQuery($request)
            ->with([
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
            ]);

        return Response::streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'name',
                'email',
                'role',
                'base_role',
                'custom_role_code',
                'direction_code',
                'service_code',
                'agent_matricule',
                'agent_fonction',
                'agent_telephone',
                'is_active',
                'is_agent',
                'suspended_until',
                'suspension_reason',
            ], ';');

            foreach ($query->lazy(500) as $row) {
                fputcsv($handle, [
                    (string) $row->name,
                    (string) $row->email,
                    (string) $row->effectiveRoleCode(),
                    (string) $row->role,
                    (string) ($row->custom_role_code ?? ''),
                    (string) ($row->direction?->code ?? ''),
                    (string) ($row->service?->code ?? ''),
                    (string) ($row->agent_matricule ?? ''),
                    (string) ($row->agent_fonction ?? ''),
                    (string) ($row->agent_telephone ?? ''),
                    $row->is_active ? '1' : '0',
                    $row->is_agent ? '1' : '0',
                    optional($row->suspended_until)?->toDateString() ?? '',
                    (string) ($row->suspension_reason ?? ''),
                ], ';');
            }

            fclose($handle);
        }, 'utilisateurs-super-admin-'.now()->format('Ymd_His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function organizationLoginHistoryExport(Request $request): StreamedResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $userIds = (clone $this->organizationUserQuery($request))
            ->select('users.id')
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $rows = $this->loginHistoryForUsers($userIds, $request)
            ->latest('id')
            ->get();

        return Response::streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'date',
                'user_name',
                'user_email',
                'action',
                'adresse_ip',
                'user_agent',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($handle, [
                    optional($row->created_at)?->toDateTimeString(),
                    (string) ($row->user?->name ?? ''),
                    (string) ($row->user?->email ?? ''),
                    (string) $row->action,
                    (string) ($row->adresse_ip ?? ''),
                    (string) ($row->user_agent ?? ''),
                ], ';');
            }

            fclose($handle);
        }, 'connexions-utilisateurs-'.now()->format('Ymd_His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function organizationUsersImport(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'users_file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['users_file'];
        $rows = $this->parseUserImportFile($file);

        if ($rows === []) {
            return back()->withErrors(['users_file' => 'Le fichier importe ne contient aucune ligne exploitable.']);
        }

        if (count($rows) > 2000) {
            return back()->withErrors(['users_file' => 'Le fichier depasse la limite de 2 000 lignes. Decoupez-le en plusieurs imports.']);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $request, $user, &$created, &$updated, &$skipped): void {
            foreach ($rows as $row) {
                $email = trim((string) ($row['email'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));
                $selectedRole = trim((string) ($row['role'] ?? ''));

                if ($email === '' || $name === '' || ! in_array($selectedRole, $this->profileOptions(), true)) {
                    $skipped++;
                    continue;
                }

                $existing = User::query()->where('email', $email)->first();
                if ($existing?->isSuperAdmin()) {
                    $skipped++;
                    continue;
                }

                $directionId = $this->resolveDirectionIdFromCode($row['direction_code'] ?? null);
                $serviceId = $this->resolveServiceIdFromCodes($row['service_code'] ?? null, $row['direction_code'] ?? null);
                $baseRole = $this->roleRegistry->baseRole($selectedRole);
                $customRoleCode = $this->roleRegistry->isCustomRole($selectedRole) ? $selectedRole : null;
                $isActive = $this->normalizeBooleanValue($row['is_active'] ?? '1');
                $isAgent = $this->normalizeBooleanValue($row['is_agent'] ?? ($baseRole === User::ROLE_AGENT ? '1' : '0'));
                $suspendedUntil = trim((string) ($row['suspended_until'] ?? '')) !== ''
                    ? \Illuminate\Support\Carbon::parse((string) $row['suspended_until'])->endOfDay()
                    : null;

                $payload = [
                    'name' => $name,
                    'email' => $email,
                    'role' => $baseRole,
                    'custom_role_code' => $customRoleCode,
                    'direction_id' => $directionId,
                    'service_id' => $serviceId,
                    'agent_matricule' => trim((string) ($row['agent_matricule'] ?? '')) ?: null,
                    'agent_fonction' => trim((string) ($row['agent_fonction'] ?? '')) ?: null,
                    'agent_telephone' => trim((string) ($row['agent_telephone'] ?? '')) ?: null,
                    'is_active' => $isActive,
                    'is_agent' => $isAgent,
                    'suspended_until' => $suspendedUntil,
                    'suspension_reason' => $suspendedUntil !== null
                        ? trim((string) ($row['suspension_reason'] ?? '')) ?: null
                        : null,
                ];

                if (in_array($baseRole, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET], true)) {
                    $payload['direction_id'] = null;
                    $payload['service_id'] = null;
                } elseif ($baseRole === User::ROLE_DIRECTION) {
                    $payload['service_id'] = null;
                }

                if ($existing instanceof User) {
                    $before = Arr::except($existing->toArray(), ['password']);
                    $existing->forceFill($payload)->save();

                    $password = trim((string) ($row['password'] ?? ''));
                    if ($password !== '') {
                        $this->passwordPolicy->validateNotReused($existing, $password);
                        $this->passwordPolicy->persistPassword($existing, $password);
                        $this->revokeSessionsForUser($existing);
                    }

                    $updated++;
                    $this->recordAudit($request, 'super_admin', 'organization_user_import_update', $existing, $before, Arr::except($existing->fresh()->toArray(), ['password']));
                    continue;
                }

                $password = trim((string) ($row['password'] ?? ''));
                if ($password === '') {
                    $password = 'TempPass@'.now()->format('mdY');
                }

                $createdUser = User::query()->create([
                    ...$payload,
                    'password' => 'temp-password-placeholder',
                    'password_changed_at' => now(),
                ]);
                $this->passwordPolicy->persistPassword($createdUser, $password);

                $created++;
                $this->recordAudit($request, 'super_admin', 'organization_user_import_create', $createdUser, null, Arr::except($createdUser->fresh()->toArray(), ['password']));
            }
        });

        return redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', sprintf('Import utilisateurs termine. Crees : %d | Mis a jour : %d | Ignores : %d.', $created, $updated, $skipped));
    }

    public function organizationUsersBulk(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'bulk_action' => ['required', Rule::in(['activate', 'deactivate', 'suspend', 'clear_suspension', 'revoke_sessions', 'reset_password', 'assign_role', 'assign_direction', 'assign_service'])],
            'bulk_role' => ['nullable', Rule::in($this->profileOptions())],
            'bulk_direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'bulk_service_id' => ['nullable', 'integer', 'exists:services,id'],
            'bulk_suspended_until' => ['nullable', 'date'],
            'bulk_suspension_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $users = User::query()
            ->whereIn('id', $validated['user_ids'])
            ->get();

        $processed = 0;
        $skipped = 0;
        $temporaryPassword = null;

        DB::transaction(function () use ($users, $validated, $request, $user, &$processed, &$skipped, &$temporaryPassword): void {
            foreach ($users as $managedUser) {
                if ($managedUser->isSuperAdmin() && (string) $validated['bulk_action'] !== 'revoke_sessions') {
                    $skipped++;
                    continue;
                }

                if ((int) $managedUser->id === (int) $user->id && (string) $validated['bulk_action'] === 'deactivate') {
                    $skipped++;
                    continue;
                }

                $before = Arr::except($managedUser->toArray(), ['password']);

                switch ((string) $validated['bulk_action']) {
                    case 'activate':
                        $managedUser->forceFill(['is_active' => true])->save();
                        break;
                    case 'deactivate':
                        $managedUser->forceFill(['is_active' => false])->save();
                        $this->revokeSessionsForUser($managedUser);
                        break;
                    case 'suspend':
                        $suspendedUntil = ! empty($validated['bulk_suspended_until'])
                            ? \Illuminate\Support\Carbon::parse((string) $validated['bulk_suspended_until'])->endOfDay()
                            : now()->addDays(7)->endOfDay();
                        $managedUser->forceFill([
                            'suspended_until' => $suspendedUntil,
                            'suspension_reason' => trim((string) ($validated['bulk_suspension_reason'] ?? '')) ?: 'Suspension appliquee par action de masse.',
                        ])->save();
                        $this->revokeSessionsForUser($managedUser);
                        break;
                    case 'clear_suspension':
                        $managedUser->forceFill([
                            'suspended_until' => null,
                            'suspension_reason' => null,
                        ])->save();
                        break;
                    case 'revoke_sessions':
                        $this->revokeSessionsForUser($managedUser);
                        break;
                    case 'reset_password':
                        $temporaryPassword ??= $this->generateSecureTemporaryPassword();
                        $this->passwordPolicy->persistPassword($managedUser, $temporaryPassword);
                        $this->revokeSessionsForUser($managedUser);
                        break;
                    case 'assign_role':
                        if (! isset($validated['bulk_role'])) {
                            $skipped++;
                            continue 2;
                        }
                        $selectedRole = (string) $validated['bulk_role'];
                        $baseRole = $this->roleRegistry->baseRole($selectedRole);
                        $customRoleCode = $this->roleRegistry->isCustomRole($selectedRole) ? $selectedRole : null;
                        if ((int) $managedUser->id === (int) $user->id && $baseRole !== User::ROLE_SUPER_ADMIN) {
                            $skipped++;
                            continue 2;
                        }
                        $payload = [
                            'role' => $baseRole,
                            'custom_role_code' => $customRoleCode,
                            'is_agent' => $baseRole === User::ROLE_AGENT,
                        ];
                        if (in_array($baseRole, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET], true)) {
                            $payload['direction_id'] = null;
                            $payload['service_id'] = null;
                        } elseif ($baseRole === User::ROLE_DIRECTION) {
                            $payload['service_id'] = null;
                        }

                        $managedUser->forceFill($payload)->save();
                        break;
                    case 'assign_direction':
                        $managedUser->forceFill([
                            'direction_id' => $validated['bulk_direction_id'] ?? null,
                            'service_id' => null,
                        ])->save();
                        break;
                    case 'assign_service':
                        $service = isset($validated['bulk_service_id'])
                            ? Service::query()->find($validated['bulk_service_id'])
                            : null;
                        if (! $service instanceof Service) {
                            $skipped++;
                            continue 2;
                        }
                        $managedUser->forceFill([
                            'direction_id' => $service->direction_id,
                            'service_id' => $service->id,
                        ])->save();
                        break;
                }

                $processed++;
                $this->recordAudit(
                    $request,
                    'super_admin',
                    'organization_user_bulk_'.(string) $validated['bulk_action'],
                    $managedUser,
                    $before,
                    Arr::except($managedUser->fresh()->toArray(), ['password'])
                );
            }
        });

        $message = sprintf('Action de masse terminee. Traites : %d | Ignores : %d.', $processed, $skipped);

        $redirect = redirect()
            ->route('workspace.super-admin.organization.index')
            ->with('success', $message);

        if ($temporaryPassword !== null) {
            $redirect = $redirect
                ->with('temporary_password_value', $temporaryPassword)
                ->with('temporary_password_bulk', true);
        }

        return $redirect;
    }

    public function dashboardProfilesEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $profiles = $this->dashboardProfileSettings->all();

        return view('workspace.super_admin.dashboard_profiles', [
            'profiles' => $profiles,
            'roleOptions' => $this->dashboardProfileSettings->roleOptions(),
            'summary' => [
                'profiles_total' => count($profiles),
                'cards_total' => collect($profiles)->sum(fn (array $profile): int => count($profile['cards'] ?? [])),
                'overviews_enabled' => collect($profiles)->where('overview_enabled', true)->count(),
            ],
            'cardSizeOptions' => [
                'sm' => 'Compacte',
                'md' => 'Standard',
                'lg' => 'Large',
            ],
            'cardToneOptions' => [
                'auto' => 'Automatique',
                'neutral' => 'Neutre',
                'info' => 'Info',
                'success' => 'Succes',
                'warning' => 'Alerte',
                'danger' => 'Critique',
            ],
            'cardTargetRouteOptions' => [
                '' => 'Lien conserve',
                'dashboard' => 'Dashboard',
                'actions' => 'Actions',
                'alertes' => 'Alertes',
                'reporting' => 'Reporting',
                'pilotage' => 'Pilotage',
            ],
        ]);
    }

    public function dashboardProfilesUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $rules = ['profiles' => ['required', 'array']];
        foreach ($this->dashboardProfileSettings->defaults() as $role => $profile) {
            $rules['profiles.'.$role] = ['required', 'array'];
            $rules['profiles.'.$role.'.overview_enabled'] = ['nullable', 'in:0,1'];
            $rules['profiles.'.$role.'.comparison_chart_enabled'] = ['nullable', 'in:0,1'];
            $rules['profiles.'.$role.'.status_chart_enabled'] = ['nullable', 'in:0,1'];
            $rules['profiles.'.$role.'.trend_chart_enabled'] = ['nullable', 'in:0,1'];
            $rules['profiles.'.$role.'.support_chart_enabled'] = ['nullable', 'in:0,1'];

            foreach ($profile['cards'] as $card) {
                $code = (string) $card['code'];
                $rules['profiles.'.$role.'.cards.'.$code.'.enabled'] = ['nullable', 'in:0,1'];
                $rules['profiles.'.$role.'.cards.'.$code.'.order'] = ['required', 'integer', 'min:1', 'max:999'];
                $rules['profiles.'.$role.'.cards.'.$code.'.size'] = ['nullable', Rule::in(['sm', 'md', 'lg'])];
                $rules['profiles.'.$role.'.cards.'.$code.'.tone'] = ['nullable', Rule::in(['auto', 'neutral', 'info', 'success', 'warning', 'danger'])];
                $rules['profiles.'.$role.'.cards.'.$code.'.target_route'] = ['nullable', Rule::in(['dashboard', 'actions', 'alertes', 'reporting', 'pilotage', ''])];
                $rules['profiles.'.$role.'.cards.'.$code.'.target_filters'] = ['nullable', 'string', 'max:255'];
            }
        }

        $validated = $request->validate($rules);
        $before = $this->dashboardProfileSettings->all();
        $after = $this->dashboardProfileSettings->update($validated['profiles'], $user);
        $auditTarget = $this->auditAnchor('dashboard_profiles', 'dashboard_profiles_registry', json_encode($after, JSON_UNESCAPED_SLASHES));

        $this->recordAudit($request, 'super_admin', 'dashboard_profile_settings_update', $auditTarget, $before, $after);

        return redirect()
            ->route('workspace.super-admin.dashboard-profiles.edit')
            ->with('success', 'Dashboards par profil mis a jour.');
    }

    public function referentialsEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $settings = $this->dynamicReferentialSettings->all();

        return view('workspace.super_admin.referentials', [
            'settings' => $settings,
            'summary' => [
                'priority_count' => count($this->dynamicReferentialSettings->paoOperationalPriorities()),
                'target_type_count' => count($this->dynamicReferentialSettings->actionTargetTypeLabels()),
                'unit_count' => count($this->dynamicReferentialSettings->actionUnitSuggestions()),
                'kpi_unit_count' => count($this->dynamicReferentialSettings->kpiUnitSuggestions()),
            ],
        ]);
    }

    public function kpisEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        return view('workspace.super_admin.kpis', [
            'settings' => $this->managedKpiSettings->all(),
            'summary' => $this->managedKpiSettings->summary(),
            'profileOptions' => $this->profileLabels(),
            'directionOptions' => Direction::query()->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => Service::query()
                ->with('direction:id,code')
                ->orderBy('direction_id')
                ->orderBy('code')
                ->get(['id', 'direction_id', 'code', 'libelle']),
            'sourceMetricOptions' => $this->managedKpiSettings->sourceMetricOptions(),
            'formulaModeOptions' => $this->managedKpiSettings->formulaModeOptions(),
        ]);
    }

    public function kpisUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $rules = ['definitions' => ['required', 'array']];
        foreach ($this->managedKpiSettings->defaults() as $code => $definition) {
            $rules['definitions.'.$code.'.code'] = ['required', 'string', Rule::in($this->managedKpiSettings->codes())];
            $rules['definitions.'.$code.'.label'] = ['required', 'string', 'max:60'];
            $rules['definitions.'.$code.'.description'] = ['nullable', 'string', 'max:180'];
            $rules['definitions.'.$code.'.weight'] = ['required', 'integer', 'min:0', 'max:100'];
            $rules['definitions.'.$code.'.green_threshold'] = ['required', 'numeric', 'min:0', 'max:100'];
            $rules['definitions.'.$code.'.orange_threshold'] = ['required', 'numeric', 'min:0', 'max:100'];
            $rules['definitions.'.$code.'.visible'] = ['nullable', 'in:0,1'];
            $rules['definitions.'.$code.'.target_profiles'] = ['nullable', 'array'];
            $rules['definitions.'.$code.'.target_profiles.*'] = ['string', Rule::in($this->profileOptions())];
            $rules['definitions.'.$code.'.source_metric'] = ['required', Rule::in(array_keys($this->managedKpiSettings->sourceMetricOptions()))];
            $rules['definitions.'.$code.'.formula_mode'] = ['required', Rule::in(array_keys($this->managedKpiSettings->formulaModeOptions()))];
            $rules['definitions.'.$code.'.secondary_metric'] = ['nullable', Rule::in(array_keys($this->managedKpiSettings->sourceMetricOptions()))];
            $rules['definitions.'.$code.'.tertiary_metric'] = ['nullable', Rule::in(array_keys($this->managedKpiSettings->sourceMetricOptions()))];
            $rules['definitions.'.$code.'.secondary_weight'] = ['nullable', 'integer', 'min:0', 'max:100'];
            $rules['definitions.'.$code.'.tertiary_weight'] = ['nullable', 'integer', 'min:0', 'max:100'];
            $rules['definitions.'.$code.'.target_value'] = ['nullable', 'numeric', 'min:0', 'max:100'];
            $rules['definitions.'.$code.'.adjustment'] = ['nullable', 'numeric', 'min:-100', 'max:100'];
            $rules['definitions.'.$code.'.target_direction_ids'] = ['nullable', 'array'];
            $rules['definitions.'.$code.'.target_direction_ids.*'] = ['integer', 'exists:directions,id'];
            $rules['definitions.'.$code.'.target_service_ids'] = ['nullable', 'array'];
            $rules['definitions.'.$code.'.target_service_ids.*'] = ['integer', 'exists:services,id'];
        }

        $validated = $request->validate($rules);
        $definitions = collect($validated['definitions'])
            ->map(function (array $definition, string $code) use ($request): array {
                return [
                    ...$definition,
                    'code' => $code,
                    'visible' => $request->boolean('definitions.'.$code.'.visible'),
                ];
            })
            ->values()
            ->all();

        $before = $this->managedKpiSettings->all();
        $after = $this->managedKpiSettings->update(['definitions' => $definitions], $user);
        $auditTarget = $this->auditAnchor('managed_kpis', 'managed_kpis_registry', json_encode($after, JSON_UNESCAPED_SLASHES));

        $this->recordAudit($request, 'super_admin', 'managed_kpis_update', $auditTarget, $before, $after);

        return redirect()
            ->route('workspace.super-admin.kpis.edit')
            ->with('success', 'Registre KPI mis a jour.');
    }

    public function referentialsUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'action_target_type_label_quantitative' => ['required', 'string', 'max:40'],
            'action_target_type_label_qualitative' => ['required', 'string', 'max:40'],
            'action_unit_suggestions' => ['required', 'string', 'max:2000'],
            'pao_operational_priorities' => ['required', 'string', 'max:1000'],
            'kpi_unit_suggestions' => ['required', 'string', 'max:2000'],
            'justificatif_category_label_hebdomadaire' => ['required', 'string', 'max:60'],
            'justificatif_category_label_final' => ['required', 'string', 'max:60'],
            'justificatif_category_label_evaluation_chef' => ['required', 'string', 'max:60'],
            'justificatif_category_label_evaluation_direction' => ['required', 'string', 'max:60'],
            'justificatif_category_label_financement' => ['required', 'string', 'max:60'],
            'alert_level_label_warning' => ['required', 'string', 'max:60'],
            'alert_level_label_critical' => ['required', 'string', 'max:60'],
            'alert_level_label_urgence' => ['required', 'string', 'max:60'],
            'alert_level_label_info' => ['required', 'string', 'max:60'],
            'validation_status_label_non_soumise' => ['required', 'string', 'max:60'],
            'validation_status_label_soumise_chef' => ['required', 'string', 'max:60'],
            'validation_status_label_rejetee_chef' => ['required', 'string', 'max:60'],
            'validation_status_label_validee_chef' => ['required', 'string', 'max:60'],
            'validation_status_label_rejetee_direction' => ['required', 'string', 'max:60'],
            'validation_status_label_validee_direction' => ['required', 'string', 'max:60'],
        ]);

        $before = $this->dynamicReferentialSettings->all();
        $after = $this->dynamicReferentialSettings->update($validated, $user);
        $auditTarget = $this->auditAnchor('dynamic_referentials', 'dynamic_referentials_registry', json_encode($after, JSON_UNESCAPED_SLASHES));

        $this->recordAudit($request, 'super_admin', 'dynamic_referentials_update', $auditTarget, $before, $after);

        return redirect()
            ->route('workspace.super-admin.referentials.edit')
            ->with('success', 'Referentiels dynamiques mis a jour.');
    }

    public function documentsEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        return view('workspace.super_admin.documents', [
            'settings' => $this->documentPolicySettings->all(),
            'summary' => $this->documentPolicySettings->summary(),
            'roleLabels' => $this->profileLabels(),
            'categoryLabels' => $this->dynamicReferentialSettings->justificatifCategoryLabels(),
        ]);
    }

    public function documentsUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'allowed_extensions' => ['required', 'string', 'max:1000'],
            'max_upload_mb' => ['required', 'integer', 'min:1', 'max:50'],
            'retention_days' => ['required', 'integer', 'min:30', 'max:3650'],
            'upload_roles' => ['required', 'array'],
            'upload_roles.*' => ['string', Rule::in($this->profileOptions())],
            'view_roles' => ['required', 'array'],
            'view_roles.*' => ['string', Rule::in($this->profileOptions())],
            'category_visibility' => ['nullable', 'array'],
            'category_visibility.*' => ['nullable', 'array'],
            'category_visibility.*.*' => ['string', Rule::in($this->profileOptions())],
        ]);

        $before = $this->documentPolicySettings->all();
        $after = $this->documentPolicySettings->update($validated, $user);
        $auditTarget = $this->auditAnchor('document_policy', 'document_policy_registry', json_encode($after, JSON_UNESCAPED_SLASHES));

        $this->recordAudit($request, 'super_admin', 'document_policy_update', $auditTarget, $before, $after);

        return redirect()
            ->route('workspace.super-admin.documents.edit')
            ->with('success', 'Politique documentaire mise a jour.');
    }

    public function notificationsEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        return view('workspace.super_admin.notifications', [
            'settings' => $this->notificationPolicySettings->all(),
            'summary' => $this->notificationPolicySettings->summary(),
            'events' => $this->notificationPolicySettings->eventDefinitions(),
            'eventChannels' => $this->notificationPolicySettings->eventChannelOptions(),
            'alertLevels' => $this->notificationPolicySettings->alertLevelDefinitions(),
            'roleOptions' => $this->notificationPolicySettings->escalationRoleOptions(),
            'ruleTargetOptions' => $this->notificationPolicySettings->escalationTargetRoleOptions(),
        ]);
    }

    public function notificationsUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $rules = [];
        foreach (array_keys($this->notificationPolicySettings->eventDefinitions()) as $event) {
            $rules['event_'.$event.'_enabled'] = ['nullable', 'in:0,1'];
            $rules['event_'.$event.'_title'] = ['nullable', 'string', 'max:120'];
            $rules['event_'.$event.'_message'] = ['nullable', 'string', 'max:255'];
            $rules['event_'.$event.'_channels'] = ['nullable', 'array'];
            $rules['event_'.$event.'_channels.*'] = ['string', Rule::in(array_keys($this->notificationPolicySettings->eventChannelOptions()))];
        }
        foreach (array_keys($this->notificationPolicySettings->alertLevelDefinitions()) as $level) {
            $rules['alert_'.$level.'_enabled'] = ['nullable', 'in:0,1'];
            $rules['alert_'.$level.'_roles'] = ['nullable', 'array'];
            $rules['alert_'.$level.'_roles.*'] = ['string', Rule::in(array_keys($this->notificationPolicySettings->escalationRoleOptions()))];
        }
        $rules['escalation_rules'] = ['nullable', 'array'];
        $rules['escalation_rules.*.level'] = ['nullable', Rule::in(array_keys($this->notificationPolicySettings->alertLevelDefinitions()))];
        $rules['escalation_rules.*.target_role'] = ['nullable', Rule::in(array_keys($this->notificationPolicySettings->escalationTargetRoleOptions()))];
        $rules['escalation_rules.*.message_template'] = ['nullable', 'string', 'max:255'];
        $rules['escalation_rules.*.active'] = ['nullable', 'in:0,1'];
        $rules['timeline_rules'] = ['nullable', 'array'];
        $rules['timeline_rules.*.offset_days'] = ['nullable', 'integer', 'min:-365', 'max:365'];
        $rules['timeline_rules.*.level'] = ['nullable', Rule::in(array_keys($this->notificationPolicySettings->alertLevelDefinitions()))];
        $rules['timeline_rules.*.target_role'] = ['nullable', Rule::in(array_keys($this->notificationPolicySettings->escalationTargetRoleOptions()))];
        $rules['timeline_rules.*.message_template'] = ['nullable', 'string', 'max:255'];
        $rules['timeline_rules.*.active'] = ['nullable', 'in:0,1'];

        $validated = $request->validate($rules);

        $before = $this->notificationPolicySettings->all();
        $after = $this->notificationPolicySettings->update($validated, $user);
        $auditTarget = $this->auditAnchor('notification_policy', 'notification_policy_registry', json_encode($after, JSON_UNESCAPED_SLASHES));

        $this->recordAudit($request, 'super_admin', 'notification_policy_update', $auditTarget, $before, $after);

        return redirect()
            ->route('workspace.super-admin.notifications.edit')
            ->with('success', 'Alertes et notifications mises a jour.');
    }

    public function actionPoliciesEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        return view('workspace.super_admin.action_policies', [
            'settings' => $this->actionManagementSettings->all(),
            'summary' => $this->actionManagementSettings->summary(),
        ]);
    }

    public function actionPoliciesUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'actions_risk_plan_required' => ['nullable', 'in:0,1'],
            'actions_manual_suspend_enabled' => ['nullable', 'in:0,1'],
            'actions_auto_complete_when_target_reached' => ['nullable', 'in:0,1'],
            'actions_final_justificatif_required' => ['nullable', 'in:0,1'],
            'actions_min_progress_for_closure' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $before = $this->actionManagementSettings->all();
        $after = $this->actionManagementSettings->update([
            'actions_risk_plan_required' => $request->boolean('actions_risk_plan_required') ? '1' : '0',
            'actions_manual_suspend_enabled' => $request->boolean('actions_manual_suspend_enabled') ? '1' : '0',
            'actions_auto_complete_when_target_reached' => $request->boolean('actions_auto_complete_when_target_reached') ? '1' : '0',
            'actions_final_justificatif_required' => $request->boolean('actions_final_justificatif_required') ? '1' : '0',
            'actions_min_progress_for_closure' => (string) $validated['actions_min_progress_for_closure'],
        ], $user);

        $auditTarget = PlatformSetting::query()
            ->where('group', 'action_management')
            ->orderBy('id')
            ->first();

        if ($auditTarget instanceof PlatformSetting) {
            $this->recordAudit($request, 'super_admin', 'action_management_settings_update', $auditTarget, $before, $after);
        }

        return redirect()
            ->route('workspace.super-admin.action-policies.edit')
            ->with('success', 'Parametres metier des actions mis a jour.');
    }

    public function snapshotsIndex(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $compare = null;
        $compareLeftId = $request->filled('compare_left') ? (int) $request->integer('compare_left') : null;
        $compareRightId = $request->filled('compare_right') ? (int) $request->integer('compare_right') : null;
        if ($compareLeftId !== null && $compareRightId !== null && $compareLeftId !== $compareRightId) {
            $left = PlatformSettingSnapshot::query()->find($compareLeftId);
            $right = PlatformSettingSnapshot::query()->find($compareRightId);
            if ($left instanceof PlatformSettingSnapshot && $right instanceof PlatformSettingSnapshot) {
                $compare = $this->platformSnapshotService->compareSnapshots($left, $right);
            }
        }

        return view('workspace.super_admin.snapshots', [
            'summary' => [
                'snapshots_total' => PlatformSettingSnapshot::query()->count(),
                'settings_total' => PlatformSetting::query()->count(),
                'last_restored_at' => PlatformSettingSnapshot::query()->max('last_restored_at'),
            ],
            'rows' => PlatformSettingSnapshot::query()
                ->with(['creator:id,name,email', 'restorer:id,name,email'])
                ->latest('id')
                ->paginate(12),
            'allSnapshots' => PlatformSettingSnapshot::query()
                ->orderByDesc('id')
                ->get(['id', 'label', 'created_at']),
            'compare' => $compare,
            'compareLeftId' => $compareLeftId,
            'compareRightId' => $compareRightId,
        ]);
    }

    public function snapshotsStore(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $snapshot = $this->platformSnapshotService->createSnapshot(
            (string) $validated['label'],
            ($validated['description'] ?? null) ?: null,
            $user
        );

        $this->recordAudit($request, 'super_admin', 'configuration_snapshot_create', $snapshot, null, $snapshot->toArray());

        return redirect()
            ->route('workspace.super-admin.snapshots.index')
            ->with('success', 'Snapshot de configuration cree.');
    }

    public function snapshotsRestore(Request $request, PlatformSettingSnapshot $snapshot): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'partial_restore' => ['nullable', 'in:0,1'],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['string', 'max:80'],
        ]);

        $selectedGroups = collect($validated['groups'] ?? [])
            ->map(fn ($group): string => trim((string) $group))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($request->boolean('partial_restore') && $selectedGroups === []) {
            return back()->withErrors([
                'groups' => 'Selectionnez au moins un groupe a restaurer.',
            ]);
        }

        $before = $this->platformSnapshotService->currentPayload();
        $restoredSnapshot = $selectedGroups === []
            ? $this->platformSnapshotService->restoreSnapshot($snapshot, $user)
            : $this->platformSnapshotService->restoreSnapshotGroups($snapshot, $selectedGroups, $user);

        $this->recordAudit($request, 'super_admin', 'configuration_snapshot_restore', $restoredSnapshot, $before, [
            'snapshot_id' => $restoredSnapshot->id,
            'snapshot_label' => $restoredSnapshot->label,
            'restored_at' => optional($restoredSnapshot->last_restored_at)->toIso8601String(),
            'groups' => $selectedGroups,
        ]);

        return redirect()
            ->route('workspace.super-admin.snapshots.index')
            ->with('success', $selectedGroups === []
                ? 'Configuration restauree depuis le snapshot selectionne.'
                : 'Configuration restauree partiellement pour les groupes selectionnes.');
    }

    public function simulationIndex(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        return view('workspace.super_admin.simulation', [
            'defaults' => [
                'actions_service_validation_enabled' => $this->workflowSettings->serviceValidationEnabled() ? '1' : '0',
                'actions_direction_validation_enabled' => $this->workflowSettings->directionValidationEnabled() ? '1' : '0',
                'actions_auto_complete_when_target_reached' => $this->actionManagementSettings->autoCompleteWhenTargetReached() ? '1' : '0',
                'actions_min_progress_for_closure' => (string) $this->actionManagementSettings->minProgressForClosure(),
            ],
            'officialBasisLabel' => $this->actionCalculationSettings->statisticalScopeLabel(),
            'simulation' => null,
        ]);
    }

    public function simulationRun(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'actions_service_validation_enabled' => ['nullable', 'in:0,1'],
            'actions_direction_validation_enabled' => ['nullable', 'in:0,1'],
            'actions_auto_complete_when_target_reached' => ['nullable', 'in:0,1'],
            'actions_min_progress_for_closure' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $simulation = $this->platformSimulationService->simulate([
            'actions_service_validation_enabled' => $request->boolean('actions_service_validation_enabled') ? '1' : '0',
            'actions_direction_validation_enabled' => $request->boolean('actions_direction_validation_enabled') ? '1' : '0',
            'actions_auto_complete_when_target_reached' => $request->boolean('actions_auto_complete_when_target_reached') ? '1' : '0',
            'actions_min_progress_for_closure' => (string) $validated['actions_min_progress_for_closure'],
        ]);

        $auditTarget = $this->auditAnchor('simulation', 'platform_simulation_last_run', json_encode($simulation['payload'] ?? [], JSON_UNESCAPED_SLASHES));
        $this->recordAudit($request, 'super_admin', 'platform_simulation_run', $auditTarget, null, $simulation);

        return redirect()
            ->route('workspace.super-admin.simulation.index')
            ->with('simulation_result', $simulation)
            ->withInput($simulation['payload'] ?? []);
    }

    public function appearanceEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $settings = $this->appearanceSettings->editable();
        $publishedSettings = $this->appearanceSettings->all();

        return view('workspace.super_admin.appearance', [
            'settings' => $settings,
            'publishedSettings' => $publishedSettings,
            'hasDraft' => $this->appearanceSettings->hasDraft(),
            'draftUpdatedAt' => $this->appearanceSettings->draftUpdatedAt(),
            'fontOptions' => $this->appearanceSettings->fontOptions(),
            'headingFontOptions' => $this->appearanceSettings->headingFontOptions(),
            'themeOptions' => $this->appearanceSettings->themeOptions(),
            'sidebarStyleOptions' => $this->appearanceSettings->sidebarStyleOptions(),
            'headerStyleOptions' => $this->appearanceSettings->headerStyleOptions(),
            'pageBackgroundStyleOptions' => $this->appearanceSettings->pageBackgroundStyleOptions(),
            'cardStyleOptions' => $this->appearanceSettings->cardStyleOptions(),
            'buttonStyleOptions' => $this->appearanceSettings->buttonStyleOptions(),
            'inputStyleOptions' => $this->appearanceSettings->inputStyleOptions(),
            'tableStyleOptions' => $this->appearanceSettings->tableStyleOptions(),
            'shadowOptions' => $this->appearanceSettings->cardShadowOptions(),
            'densityOptions' => $this->appearanceSettings->densityOptions(),
            'contentWidthOptions' => $this->appearanceSettings->contentWidthOptions(),
            'sidebarWidthOptions' => $this->appearanceSettings->sidebarWidthOptions(),
            'previewPayload' => $this->appearancePreviewPayload($settings),
            'publishedPreviewPayload' => $this->appearancePreviewPayload($publishedSettings),
        ]);
    }

    public function appearancePreview(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $settings = $this->appearanceSettings->resolveDraft($request->all());

        return response()->json($this->appearancePreviewPayload($settings));
    }

    public function appearanceUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate($this->appearanceValidationRules());

        $before = $this->appearanceSettings->all();
        $after = $this->appearanceSettings->updateAppearance($validated, $user);
        $auditTarget = $this->auditAnchor('appearance', 'appearance_palette', 'appearance-update');

        $this->recordAudit($request, 'super_admin', 'appearance_settings_update', $auditTarget, $before, $after);

        return redirect()
            ->route('workspace.super-admin.appearance.edit')
            ->with('success', 'Apparence de la plateforme mise a jour.');
    }

    public function appearanceDraftUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate($this->appearanceValidationRules());

        $before = $this->appearanceSettings->editable();
        $after = $this->appearanceSettings->updateDraft($validated, $user);
        $auditTarget = $this->auditAnchor('appearance_draft', 'appearance_draft_palette', 'appearance-draft-update');

        $this->recordAudit($request, 'super_admin', 'appearance_settings_draft_update', $auditTarget, $before, $after);

        return redirect()
            ->route('workspace.super-admin.appearance.edit')
            ->with('success', 'Brouillon d apparence enregistre.');
    }

    public function appearancePublishDraft(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        if (! $this->appearanceSettings->hasDraft()) {
            return redirect()
                ->route('workspace.super-admin.appearance.edit')
                ->with('success', 'Aucun brouillon a publier.');
        }

        $before = $this->appearanceSettings->all();
        $draft = $this->appearanceSettings->draft();
        $after = $this->appearanceSettings->publishDraft($user);
        $auditTarget = $this->auditAnchor('appearance', 'appearance_palette', 'appearance-draft-publish');

        $this->recordAudit($request, 'super_admin', 'appearance_settings_draft_publish', $auditTarget, [
            'published' => $before,
            'draft' => $draft,
        ], $after);

        return redirect()
            ->route('workspace.super-admin.appearance.edit')
            ->with('success', 'Brouillon d apparence publie.');
    }

    public function appearanceDiscardDraft(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        if (! $this->appearanceSettings->hasDraft()) {
            return redirect()
                ->route('workspace.super-admin.appearance.edit')
                ->with('success', 'Aucun brouillon a supprimer.');
        }

        $before = $this->appearanceSettings->draft();
        $auditTarget = PlatformSetting::query()
            ->where('group', 'appearance_draft')
            ->orderBy('id')
            ->first()
            ?? $this->auditAnchor('super_admin_meta', 'appearance_draft_discard_anchor', 'appearance-draft-discard');
        $this->appearanceSettings->discardDraft();

        $this->recordAudit($request, 'super_admin', 'appearance_settings_draft_discard', $auditTarget, $before, null);

        return redirect()
            ->route('workspace.super-admin.appearance.edit')
            ->with('success', 'Brouillon d apparence supprime.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function appearanceValidationRules(): array
    {
        return [
            'primary_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'surface_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'success_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'warning_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'danger_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'text_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'muted_text_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'border_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'card_background_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'input_background_color' => ['required', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'font_family' => ['required', Rule::in($this->appearanceSettings->fontOptions())],
            'heading_font_family' => ['required', Rule::in($this->appearanceSettings->headingFontOptions())],
            'default_theme' => ['required', Rule::in(array_keys($this->appearanceSettings->themeOptions()))],
            'sidebar_style' => ['required', Rule::in(array_keys($this->appearanceSettings->sidebarStyleOptions()))],
            'header_style' => ['required', Rule::in(array_keys($this->appearanceSettings->headerStyleOptions()))],
            'page_background_style' => ['required', Rule::in(array_keys($this->appearanceSettings->pageBackgroundStyleOptions()))],
            'card_style' => ['required', Rule::in(array_keys($this->appearanceSettings->cardStyleOptions()))],
            'button_style' => ['required', Rule::in(array_keys($this->appearanceSettings->buttonStyleOptions()))],
            'input_style' => ['required', Rule::in(array_keys($this->appearanceSettings->inputStyleOptions()))],
            'table_style' => ['required', Rule::in(array_keys($this->appearanceSettings->tableStyleOptions()))],
            'card_shadow_strength' => ['required', Rule::in(array_keys($this->appearanceSettings->cardShadowOptions()))],
            'card_radius' => ['required', 'regex:/^\d+(\.\d+)?(rem|px)$/'],
            'button_radius' => ['required', 'regex:/^\d+(\.\d+)?(rem|px)$/'],
            'input_radius' => ['required', 'regex:/^\d+(\.\d+)?(rem|px)$/'],
            'card_blur' => ['required', 'regex:/^\d+(\.\d+)?px$/'],
            'visual_density' => ['required', Rule::in(array_keys($this->appearanceSettings->densityOptions()))],
            'content_width' => ['required', Rule::in(array_keys($this->appearanceSettings->contentWidthOptions()))],
            'sidebar_width' => ['required', Rule::in(array_keys($this->appearanceSettings->sidebarWidthOptions()))],
        ];
    }

    /**
     * @param  array<string, string>  $settings
     * @return array<string, mixed>
     */
    private function appearancePreviewPayload(array $settings): array
    {
        return [
            'settings' => $settings,
            'css_variables' => $this->appearanceSettings->cssVariables($settings),
        ];
    }

    public function maintenanceIndex(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        return view('workspace.super_admin.maintenance', [
            'status' => $this->maintenanceService->status(),
            'actions' => $this->maintenanceService->actions(),
            'recentAudits' => JournalAudit::query()
                ->with('user:id,name,email')
                ->where('module', 'super_admin')
                ->where('action', 'like', 'maintenance_%')
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }

    public function maintenanceRun(Request $request, string $action): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        abort_unless(array_key_exists($action, $this->maintenanceService->actions()), 404);

        $result = $this->maintenanceService->perform($action);
        $auditTarget = $this->auditAnchor('maintenance', 'maintenance_last_action', json_encode($result, JSON_UNESCAPED_SLASHES));

        $this->recordAudit($request, 'super_admin', 'maintenance_'.$action, $auditTarget, null, $result);

        $redirect = redirect()->route('workspace.super-admin.maintenance.index');

        if ($action === 'maintenance_on' && is_string($result['bypass_url'] ?? null)) {
            $redirect = redirect()->to((string) $result['bypass_url']);
        }

        return $redirect->with('success', $result['label'].' execute.');
    }

    public function templatesIndex(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $query = ExportTemplate::query()
            ->with(['creator:id,name,email', 'updater:id,name,email'])
            ->withCount(['assignments', 'versions'])
            ->latest('updated_at');

        $query->when($request->filled('format'), fn (Builder $builder) => $builder->where('format', (string) $request->string('format')));
        $query->when($request->filled('module'), fn (Builder $builder) => $builder->where('module', (string) $request->string('module')));
        $query->when($request->filled('status'), fn (Builder $builder) => $builder->where('status', (string) $request->string('status')));
        $query->when($request->filled('target_profile'), fn (Builder $builder) => $builder->where('target_profile', (string) $request->string('target_profile')));
        $query->when($request->filled('q'), function (Builder $builder) use ($request): void {
            $search = trim((string) $request->string('q'));
            $builder->where(function (Builder $scoped) use ($search): void {
                $scoped->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('report_type', 'like', "%{$search}%");
            });
        });

        return view('workspace.super_admin.templates.index', [
            'rows' => $query->paginate(15)->withQueryString(),
            'filters' => [
                'q' => (string) $request->string('q'),
                'format' => (string) $request->string('format'),
                'module' => (string) $request->string('module'),
                'status' => (string) $request->string('status'),
                'target_profile' => (string) $request->string('target_profile'),
            ],
            'formatOptions' => ExportTemplate::formatOptions(),
            'statusOptions' => ExportTemplate::statusOptions(),
            'moduleOptions' => $this->moduleOptions(),
            'profileOptions' => $this->profileOptions(),
        ]);
    }

    public function workflowEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        return view('workspace.super_admin.workflow', [
            'settings' => $this->workflowSettings->all(),
            'summary' => $this->workflowSettings->actionValidationSummary(),
            'planningModes' => $this->workflowSettings->planningWorkflowModes(),
            'planningWorkflows' => [
                'pas' => $this->workflowSettings->planningWorkflowSummary('pas'),
                'pao' => $this->workflowSettings->planningWorkflowSummary('pao'),
                'pta' => $this->workflowSettings->planningWorkflowSummary('pta'),
            ],
        ]);
    }

    public function workflowUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $before = $this->workflowSettings->all();
        $validated = $request->validate([
            'actions_service_validation_enabled' => ['nullable', 'in:0,1'],
            'actions_direction_validation_enabled' => ['nullable', 'in:0,1'],
            'actions_rejection_comment_required' => ['nullable', 'in:0,1'],
            'pas_workflow_mode' => ['required', Rule::in(array_keys($this->workflowSettings->planningWorkflowModes()))],
            'pao_workflow_mode' => ['required', Rule::in(array_keys($this->workflowSettings->planningWorkflowModes()))],
            'pta_workflow_mode' => ['required', Rule::in(array_keys($this->workflowSettings->planningWorkflowModes()))],
        ]);

        $after = $this->workflowSettings->updateActionWorkflow([
            'actions_service_validation_enabled' => $request->boolean('actions_service_validation_enabled') ? '1' : '0',
            'actions_direction_validation_enabled' => $request->boolean('actions_direction_validation_enabled') ? '1' : '0',
            'actions_rejection_comment_required' => $request->boolean('actions_rejection_comment_required') ? '1' : '0',
        ], $user);

        $auditTarget = PlatformSetting::query()
            ->where('group', 'workflow')
            ->orderBy('id')
            ->first();

        if ($auditTarget instanceof PlatformSetting) {
            $this->recordAudit($request, 'super_admin', 'workflow_settings_update', $auditTarget, $before, $after);
        }

        $planningBefore = $this->workflowSettings->all();
        $planningAfter = $this->workflowSettings->updatePlanningWorkflow([
            'pas_workflow_mode' => (string) $validated['pas_workflow_mode'],
            'pao_workflow_mode' => (string) $validated['pao_workflow_mode'],
            'pta_workflow_mode' => (string) $validated['pta_workflow_mode'],
        ], $user);
        $planningAuditTarget = PlatformSetting::query()
            ->where('group', 'workflow')
            ->whereIn('key', ['pas_workflow_mode', 'pao_workflow_mode', 'pta_workflow_mode'])
            ->orderBy('id')
            ->first();

        if ($planningAuditTarget instanceof PlatformSetting) {
            $this->recordAudit($request, 'super_admin', 'planning_workflow_settings_update', $planningAuditTarget, $planningBefore, $planningAfter);
        }

        return redirect()
            ->route('workspace.super-admin.workflow.edit')
            ->with('success', 'Workflow et validations mis a jour.');
    }

    public function auditDiagnosticIndex(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        return view('workspace.super_admin.audit_diagnostic', [
            'summary' => $this->platformDiagnosticService->auditSummary(),
            'checks' => $this->platformDiagnosticService->checks(),
            'recentAudits' => JournalAudit::query()
                ->with('user:id,name,email')
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function calculationEdit(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        return view('workspace.super_admin.calculation', [
            'settings' => $this->actionCalculationSettings->all(),
            'summary' => [
                'official_threshold_label' => $this->actionCalculationSettings->statisticalScopeLabel(),
                'official_scope_summary' => $this->actionCalculationSettings->statisticalScopeSummary(),
                'official_route_filters' => $this->actionCalculationSettings->statisticalRouteFilters(),
            ],
            'statusOptions' => $this->actionCalculationSettings->statisticalScopeOptions(),
        ]);
    }

    public function calculationUpdate(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $before = $this->actionCalculationSettings->all();
        $after = $this->actionCalculationSettings->updateStatisticalPolicy([
            'actions_statistical_scope' => ActionCalculationSettings::STATISTICAL_SCOPE_ALL_VISIBLE,
        ], $user);
        $auditTarget = PlatformSetting::query()
            ->where('group', 'action_calculation')
            ->orderBy('id')
            ->first();

        if ($auditTarget instanceof PlatformSetting) {
            $this->recordAudit($request, 'super_admin', 'action_calculation_policy_update', $auditTarget, $before, $after);
        }

        return redirect()
            ->route('workspace.super-admin.calculation.edit')
            ->with('success', 'Politique de calcul des actions mise a jour.');
    }

    public function templatesCreate(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        return view('workspace.super_admin.templates.form', $this->formPayload(new ExportTemplate(), 'create'));
    }

    public function templatesStore(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $this->validateTemplate($request);
        $template = ExportTemplate::query()->create(array_merge(
            $this->templatePayload($validated, $request),
            [
                'status' => ExportTemplate::STATUS_DRAFT,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]
        ));

        if ($request->boolean('create_default_assignment', true)) {
            $template->assignments()->create([
                'module' => $template->module,
                'report_type' => $template->report_type,
                'format' => $template->format,
                'target_profile' => $template->target_profile,
                'reading_level' => $template->reading_level,
                'is_default' => (bool) $template->is_default,
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        $this->recordAudit($request, 'export_template', 'create', $template, null, $template->toArray());

        return redirect()
            ->route('workspace.super-admin.templates.show', $template)
            ->with('success', 'Template d export cree avec succes.');
    }

    public function templatesShow(Request $request, ExportTemplate $template): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $template->load([
            'creator:id,name,email',
            'updater:id,name,email',
            'versions.creator:id,name,email',
            'assignments.direction:id,code,libelle',
            'assignments.service:id,code,libelle',
        ]);

        return view('workspace.super_admin.templates.show', [
            'template' => $template,
            'assignmentDefaults' => [
                'module' => $template->module,
                'report_type' => $template->report_type,
                'format' => $template->format,
                'target_profile' => $template->target_profile,
                'reading_level' => $template->reading_level,
            ],
            'moduleOptions' => $this->moduleOptions(),
            'profileOptions' => $this->profileOptions(),
            'readingLevelOptions' => $this->readingLevelOptions(),
            'directionOptions' => Direction::query()->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => Service::query()->with('direction:id,code')->orderBy('direction_id')->orderBy('code')->get(['id', 'direction_id', 'code', 'libelle']),
        ]);
    }

    public function templatesEdit(Request $request, ExportTemplate $template): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        return view('workspace.super_admin.templates.form', $this->formPayload($template, 'edit'));
    }

    public function templatesUpdate(Request $request, ExportTemplate $template): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $this->validateTemplate($request, $template);
        $before = $template->toArray();
        $template->forceFill(array_merge(
            $this->templatePayload($validated, $request, $template),
            ['updated_by' => $user->id]
        ))->save();

        $this->recordAudit($request, 'export_template', 'update', $template, $before, $template->toArray());

        return redirect()
            ->route('workspace.super-admin.templates.show', $template)
            ->with('success', 'Template d export mis a jour avec succes.');
    }

    public function templatesPublish(Request $request, ExportTemplate $template): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);
        $before = $template->toArray();

        if (! $template->assignments()->exists()) {
            $this->ensureBaseAssignment($template, $user);
        }

        if ($request->boolean('mark_as_default')) {
            $this->clearDefaultTemplates($template);
            $template->is_default = true;
            $template->save();
            $this->ensureBaseAssignment($template, $user);
            $this->clearDefaultAssignments(
                $template->module,
                $template->report_type,
                $template->format,
                $template->target_profile,
                $template->reading_level
            );
            $template->assignments()->update(['is_default' => true, 'updated_by' => $user->id]);
        }

        $version = $this->templatePublisher->publish($template, $user, (string) $request->string('note'));
        $template->refresh();

        $this->recordAudit($request, 'export_template', 'publish', $template, $before, [
            ...$template->toArray(),
            'version_number' => $version->version_number,
        ]);

        return redirect()
            ->route('workspace.super-admin.templates.show', $template)
            ->with('success', 'Template publie avec succes.');
    }

    public function templatesArchive(Request $request, ExportTemplate $template): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $before = $template->toArray();
        $this->templatePublisher->archive($template, $user, (string) $request->string('note'));
        $template->refresh();

        $this->recordAudit($request, 'export_template', 'archive', $template, $before, $template->toArray());

        return redirect()
            ->route('workspace.super-admin.templates.show', $template)
            ->with('success', 'Template archive avec succes.');
    }

    public function templatesDuplicate(Request $request, ExportTemplate $template): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $copy = $this->templatePublisher->duplicate($template, $user);
        $this->recordAudit($request, 'export_template', 'duplicate', $copy, null, $copy->toArray());

        return redirect()
            ->route('workspace.super-admin.templates.edit', $copy)
            ->with('success', 'Template duplique. Ajustez le brouillon avant publication.');
    }

    public function templatesPreview(Request $request, ExportTemplate $template): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $preview = $this->buildTemplatePreview($template, $user);

        return view('workspace.super_admin.templates.preview', [
            'template' => $template,
            'preview' => $preview,
        ]);
    }

    public function templatesExportJson(Request $request, ExportTemplate $template): StreamedResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $json = json_encode([
            'code' => $template->code,
            'name' => $template->name,
            'description' => $template->description,
            'format' => $template->format,
            'module' => $template->module,
            'report_type' => $template->report_type,
            'target_profile' => $template->target_profile,
            'reading_level' => $template->reading_level,
            'blocks_config' => $template->blocks_config ?? [],
            'layout_config' => $template->layout_config ?? [],
            'content_config' => $template->content_config ?? [],
            'style_config' => $template->style_config ?? [],
            'meta_config' => $template->meta_config ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response()->streamDownload(static function () use ($json): void {
            echo $json;
        }, $template->code.'.json', [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public function templatesImportJson(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'template_json' => ['nullable', 'string'],
            'template_file' => ['nullable', 'file', 'max:2048', 'mimetypes:application/json,text/plain'],
        ]);

        $json = trim((string) ($validated['template_json'] ?? ''));
        if ($json === '' && $request->file('template_file') instanceof UploadedFile) {
            $json = (string) file_get_contents($request->file('template_file')->getRealPath());
        }

        if ($json === '') {
            throw ValidationException::withMessages([
                'template_json' => 'Fournissez un JSON en texte ou en fichier.',
            ]);
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'template_json' => 'Le JSON fourni est invalide.',
            ]);
        }

        $imported = array_merge([
            'name' => 'Template importe',
            'code' => 'template-importe',
            'description' => null,
            'format' => ExportTemplate::FORMAT_PDF,
            'module' => 'reporting',
            'report_type' => 'consolidated_reporting',
            'target_profile' => null,
            'reading_level' => null,
            'blocks_config' => [],
            'layout_config' => [],
            'content_config' => [],
            'style_config' => [],
            'meta_config' => [],
        ], $decoded);

        $baseCode = Str::lower(Str::slug((string) $imported['code'], '-'));
        $code = $baseCode !== '' ? $baseCode : 'template-importe';
        if (ExportTemplate::query()->where('code', $code)->exists()) {
            $code .= '-'.now()->format('YmdHis');
        }

        $template = ExportTemplate::query()->create([
            'code' => $code,
            'name' => Str::limit((string) ($imported['name'] ?? 'Template importe'), 160, ''),
            'description' => ($imported['description'] ?? null) ?: null,
            'format' => in_array((string) ($imported['format'] ?? ''), ExportTemplate::formatOptions(), true) ? (string) $imported['format'] : ExportTemplate::FORMAT_PDF,
            'module' => in_array((string) ($imported['module'] ?? ''), $this->moduleOptions(), true) ? (string) $imported['module'] : 'reporting',
            'report_type' => Str::limit((string) ($imported['report_type'] ?? 'consolidated_reporting'), 80, ''),
            'target_profile' => in_array((string) ($imported['target_profile'] ?? ''), $this->profileOptions(), true) ? (string) $imported['target_profile'] : null,
            'reading_level' => in_array((string) ($imported['reading_level'] ?? ''), $this->readingLevelOptions(), true) ? (string) $imported['reading_level'] : null,
            'status' => ExportTemplate::STATUS_DRAFT,
            'is_default' => false,
            'is_active' => true,
            'blocks_config' => is_array($imported['blocks_config'] ?? null) ? $imported['blocks_config'] : [],
            'layout_config' => is_array($imported['layout_config'] ?? null) ? $imported['layout_config'] : [],
            'content_config' => is_array($imported['content_config'] ?? null) ? $imported['content_config'] : [],
            'style_config' => is_array($imported['style_config'] ?? null) ? $imported['style_config'] : [],
            'meta_config' => is_array($imported['meta_config'] ?? null) ? $imported['meta_config'] : [],
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->recordAudit($request, 'export_template', 'import_json', $template, null, $template->toArray());

        return redirect()
            ->route('workspace.super-admin.templates.edit', $template)
            ->with('success', 'Template JSON importe en brouillon.');
    }

    public function templateVersionRestore(Request $request, ExportTemplate $template, ExportTemplateVersion $version): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        abort_unless((int) $version->export_template_id === (int) $template->id, 404);

        $before = $template->toArray();
        $this->templatePublisher->restoreVersion($template, $version, $user, (string) $request->string('note'));
        $template->refresh();

        $this->recordAudit($request, 'export_template', 'restore_version', $template, $before, [
            ...$template->toArray(),
            'restored_version_number' => $version->version_number,
        ]);

        return redirect()
            ->route('workspace.super-admin.templates.show', $template)
            ->with('success', 'Version restauree dans le brouillon courant.');
    }

    public function assignmentStore(Request $request, ExportTemplate $template): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $validated = $request->validate([
            'module' => ['required', Rule::in($this->moduleOptions())],
            'report_type' => ['required', 'string', 'max:80'],
            'format' => ['required', Rule::in(ExportTemplate::formatOptions())],
            'target_profile' => ['nullable', Rule::in($this->profileOptions())],
            'reading_level' => ['nullable', Rule::in($this->readingLevelOptions())],
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $assignment = $template->assignments()->create([
            'module' => (string) $validated['module'],
            'report_type' => (string) $validated['report_type'],
            'format' => (string) $validated['format'],
            'target_profile' => $validated['target_profile'] ?: null,
            'reading_level' => $validated['reading_level'] ?: null,
            'direction_id' => $validated['direction_id'] ?? null,
            'service_id' => $validated['service_id'] ?? null,
            'is_default' => $request->boolean('is_default', false),
            'is_active' => $request->boolean('is_active', true),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        if ($assignment->is_default) {
            $this->clearDefaultAssignments(
                $assignment->module,
                $assignment->report_type,
                $assignment->format,
                $assignment->target_profile,
                $assignment->reading_level,
                $assignment->direction_id,
                $assignment->service_id,
                $assignment->id
            );
        }

        $this->recordAudit($request, 'export_template', 'assign', $assignment, null, $assignment->toArray());

        return redirect()
            ->route('workspace.super-admin.templates.show', $template)
            ->with('success', 'Affectation ajoutee.');
    }

    public function assignmentToggle(Request $request, ExportTemplateAssignment $assignment): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessSuperAdmin($user);

        $before = $assignment->toArray();
        $assignment->forceFill([
            'is_active' => ! $assignment->is_active,
            'updated_by' => $user->id,
        ])->save();

        $this->recordAudit($request, 'export_template', 'assignment_toggle', $assignment, $before, $assignment->toArray());

        return redirect()
            ->route('workspace.super-admin.templates.show', $assignment->template)
            ->with('success', 'Etat de l affectation mis a jour.');
    }

    private function authUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    private function denyUnlessSuperAdmin(User $user): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    /**
     * @return array{code:string,libelle:string,actif:bool}
     */
    private function validateDirectionPayload(Request $request, ?Direction $direction = null): array
    {
        $codeRule = Rule::unique('directions', 'code');
        if ($direction instanceof Direction) {
            $codeRule = $codeRule->ignore($direction->id);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', $codeRule],
            'libelle' => ['required', 'string', 'max:255'],
            'actif' => ['nullable', 'boolean'],
        ]);

        return [
            'code' => strtoupper(trim((string) $validated['code'])),
            'libelle' => trim((string) $validated['libelle']),
            'actif' => $request->boolean('actif', $direction?->actif ?? true),
        ];
    }

    /**
     * @return array{direction_id:int,code:string,libelle:string,actif:bool}
     */
    private function validateServicePayload(Request $request, ?Service $service = null): array
    {
        $directionId = (int) $request->integer('direction_id');
        $codeRule = Rule::unique('services', 'code')
            ->where(fn ($query) => $query->where('direction_id', $directionId));

        if ($service instanceof Service) {
            $codeRule = $codeRule->ignore($service->id);
        }

        $validated = $request->validate([
            'direction_id' => ['required', 'integer', 'exists:directions,id'],
            'code' => ['required', 'string', 'max:30', $codeRule],
            'libelle' => ['required', 'string', 'max:255'],
            'actif' => ['nullable', 'boolean'],
        ]);

        return [
            'direction_id' => (int) $validated['direction_id'],
            'code' => strtoupper(trim((string) $validated['code'])),
            'libelle' => trim((string) $validated['libelle']),
            'actif' => $request->boolean('actif', $service?->actif ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOrganizationUserPayload(Request $request, ?User $managedUser = null): array
    {
        $emailRule = Rule::unique('users', 'email');
        $matriculeRule = Rule::unique('users', 'agent_matricule');

        if ($managedUser instanceof User) {
            $emailRule = $emailRule->ignore($managedUser->id);
            $matriculeRule = $matriculeRule->ignore($managedUser->id);
        }

        $creating = ! ($managedUser instanceof User);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', $emailRule],
            'role' => ['required', Rule::in($this->profileOptions())],
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'is_active' => ['nullable', 'boolean'],
            'is_agent' => ['nullable', 'boolean'],
            'agent_matricule' => ['nullable', 'string', 'max:80', $matriculeRule],
            'agent_fonction' => ['nullable', 'string', 'max:255'],
            'agent_telephone' => ['nullable', 'string', 'max:80'],
            'suspended_until' => ['nullable', 'date'],
            'suspension_reason' => ['nullable', 'string', 'max:255'],
            'password' => $creating
                ? ['required', 'string', $this->passwordPolicy->rule(), 'confirmed']
                : ['nullable', 'string', $this->passwordPolicy->rule(false), 'confirmed'],
        ]);

        $selectedRole = (string) $validated['role'];
        $role = $this->roleRegistry->baseRole($selectedRole);
        $directionId = isset($validated['direction_id']) ? (int) $validated['direction_id'] : null;
        $serviceId = isset($validated['service_id']) ? (int) $validated['service_id'] : null;

        if ($managedUser instanceof User) {
            if (! array_key_exists('direction_id', $validated) && $managedUser->direction_id !== null) {
                $validated['direction_id'] = (int) $managedUser->direction_id;
                $directionId = (int) $managedUser->direction_id;
            }

            if (! array_key_exists('service_id', $validated) && $managedUser->service_id !== null) {
                $validated['service_id'] = (int) $managedUser->service_id;
                $serviceId = (int) $managedUser->service_id;
            }
        }

        if ($serviceId !== null) {
            $service = Service::query()->find($serviceId);
            if (! $service instanceof Service) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'service_id' => 'Le service selectionne est introuvable.',
                ]);
            }

            if ($directionId === null) {
                $validated['direction_id'] = $service->direction_id;
                $directionId = (int) $service->direction_id;
            } elseif ((int) $service->direction_id !== $directionId) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'service_id' => 'Le service selectionne ne correspond pas a la direction choisie.',
                ]);
            }
        }

        if ($role === User::ROLE_DIRECTION && $directionId === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'direction_id' => 'Une direction doit etre affectee a ce profil.',
            ]);
        }

        if (in_array($role, [User::ROLE_SERVICE, User::ROLE_AGENT], true) && ($directionId === null || $serviceId === null)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'service_id' => 'Ce profil doit etre rattache a un service.',
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeManagedUserPayload(array $validated, Request $request, ?User $managedUser = null): array
    {
        $selectedRole = (string) $validated['role'];
        $role = $this->roleRegistry->baseRole($selectedRole);
        $customRoleCode = $this->roleRegistry->isCustomRole($selectedRole) ? $selectedRole : null;
        $directionId = isset($validated['direction_id']) ? (int) $validated['direction_id'] : null;
        $serviceId = isset($validated['service_id']) ? (int) $validated['service_id'] : null;

        if (in_array($role, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET], true)) {
            $directionId = null;
            $serviceId = null;
        } elseif ($role === User::ROLE_DIRECTION) {
            $serviceId = null;
        }

        $suspendedUntil = ! empty($validated['suspended_until'])
            ? \Illuminate\Support\Carbon::parse((string) $validated['suspended_until'])->endOfDay()
            : null;

        return [
            'name' => trim((string) $validated['name']),
            'email' => Str::lower(trim((string) $validated['email'])),
            'role' => $role,
            'custom_role_code' => $customRoleCode,
            'direction_id' => $directionId,
            'service_id' => $serviceId,
            'is_active' => $request->boolean('is_active', $managedUser?->is_active ?? true),
            'is_agent' => $request->boolean('is_agent', $role === User::ROLE_AGENT),
            'agent_matricule' => (($validated['agent_matricule'] ?? null) ?: null),
            'agent_fonction' => (($validated['agent_fonction'] ?? null) ?: null),
            'agent_telephone' => (($validated['agent_telephone'] ?? null) ?: null),
            'suspended_until' => $suspendedUntil,
            'suspension_reason' => $suspendedUntil !== null ? (($validated['suspension_reason'] ?? null) ?: null) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(ExportTemplate $template, string $mode): array
    {
        return [
            'mode' => $mode,
            'template' => $template,
            'formatOptions' => ExportTemplate::formatOptions(),
            'statusOptions' => ExportTemplate::statusOptions(),
            'moduleOptions' => $this->moduleOptions(),
            'profileOptions' => $this->profileOptions(),
            'readingLevelOptions' => $this->readingLevelOptions(),
            'dynamicVariableOptions' => ExportTemplate::allowedDynamicVariables(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemplate(Request $request, ?ExportTemplate $template = null): array
    {
        $codeRule = Rule::unique('export_templates', 'code');
        if ($template instanceof ExportTemplate) {
            $codeRule = $codeRule->ignore($template->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9\-_.]+$/', $codeRule],
            'description' => ['nullable', 'string'],
            'format' => ['required', Rule::in(ExportTemplate::formatOptions())],
            'module' => ['required', Rule::in($this->moduleOptions())],
            'report_type' => ['required', 'string', 'max:80'],
            'target_profile' => ['nullable', Rule::in($this->profileOptions())],
            'reading_level' => ['nullable', Rule::in($this->readingLevelOptions())],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'document_title' => ['nullable', 'string', 'max:160'],
            'document_subtitle' => ['nullable', 'string', 'max:255'],
            'filename_prefix' => ['nullable', 'string', 'max:120'],
            'paper_size' => ['nullable', 'string', 'max:20'],
            'orientation' => ['nullable', Rule::in(['portrait', 'landscape'])],
            'header_text' => ['nullable', 'string', 'max:255'],
            'footer_text' => ['nullable', 'string', 'max:255'],
            'watermark_text' => ['nullable', 'string', 'max:120'],
            'excel_freeze_header' => ['nullable', 'boolean'],
            'excel_auto_filter' => ['nullable', 'boolean'],
            'excel_detail_sheet_name' => ['nullable', 'string', 'max:31'],
            'excel_graph_sheet_name' => ['nullable', 'string', 'max:31'],
            'pdf_show_level_legend' => ['nullable', 'boolean'],
            'pdf_show_kpi_cards' => ['nullable', 'boolean'],
            'word_include_toc' => ['nullable', 'boolean'],
            'word_page_break_after_summary' => ['nullable', 'boolean'],
            'color_primary' => ['nullable', 'string', 'max:20'],
            'color_secondary' => ['nullable', 'string', 'max:20'],
            'font_family' => ['nullable', 'string', 'max:80'],
            'include_cover' => ['nullable', 'boolean'],
            'include_summary' => ['nullable', 'boolean'],
            'include_detail_table' => ['nullable', 'boolean'],
            'include_charts' => ['nullable', 'boolean'],
            'include_alerts' => ['nullable', 'boolean'],
            'include_signatures' => ['nullable', 'boolean'],
            'visible_columns' => ['nullable', 'string'],
            'dynamic_variables' => ['nullable', 'string'],
            'create_default_assignment' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function templatePayload(array $validated, Request $request, ?ExportTemplate $template = null): array
    {
        $visibleColumns = collect(explode(',', (string) ($validated['visible_columns'] ?? '')))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->values()
            ->all();
        $dynamicVariables = collect(preg_split('/\r\n|\r|\n/', (string) ($validated['dynamic_variables'] ?? '')) ?: [])
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $unknownVariables = collect($dynamicVariables)
            ->reject(fn (string $variable): bool => in_array($variable, ExportTemplate::allowedDynamicVariables(), true))
            ->values();
        if ($unknownVariables->isNotEmpty()) {
            throw ValidationException::withMessages([
                'dynamic_variables' => 'Variables dynamiques non supportees : '.$unknownVariables->implode(', '),
            ]);
        }

        return [
            'code' => Str::lower((string) $validated['code']),
            'name' => (string) $validated['name'],
            'description' => ($validated['description'] ?? null) ?: null,
            'format' => (string) $validated['format'],
            'module' => (string) $validated['module'],
            'report_type' => (string) $validated['report_type'],
            'target_profile' => ($validated['target_profile'] ?? null) ?: null,
            'reading_level' => ($validated['reading_level'] ?? null) ?: null,
            'is_default' => $request->boolean('is_default', (bool) ($template?->is_default ?? false)),
            'is_active' => $request->boolean('is_active', (bool) ($template?->is_active ?? true)),
            'blocks_config' => [
                'include_cover' => $request->boolean('include_cover', (bool) Arr::get($template?->blocks_config ?? [], 'include_cover', true)),
                'include_summary' => $request->boolean('include_summary', (bool) Arr::get($template?->blocks_config ?? [], 'include_summary', true)),
                'include_detail_table' => $request->boolean('include_detail_table', (bool) Arr::get($template?->blocks_config ?? [], 'include_detail_table', true)),
                'include_charts' => $request->boolean('include_charts', (bool) Arr::get($template?->blocks_config ?? [], 'include_charts', true)),
                'include_alerts' => $request->boolean('include_alerts', (bool) Arr::get($template?->blocks_config ?? [], 'include_alerts', true)),
                'include_signatures' => $request->boolean('include_signatures', (bool) Arr::get($template?->blocks_config ?? [], 'include_signatures', false)),
            ],
            'layout_config' => [
                'paper_size' => (string) (($validated['paper_size'] ?? null) ?: 'a4'),
                'orientation' => (string) (($validated['orientation'] ?? null) ?: 'landscape'),
                'header_text' => (string) (($validated['header_text'] ?? null) ?: ''),
                'footer_text' => (string) (($validated['footer_text'] ?? null) ?: ''),
                'watermark_text' => (string) (($validated['watermark_text'] ?? null) ?: ''),
                'excel_freeze_header' => $request->boolean('excel_freeze_header', (bool) Arr::get($template?->layout_config ?? [], 'excel_freeze_header', true)),
                'excel_auto_filter' => $request->boolean('excel_auto_filter', (bool) Arr::get($template?->layout_config ?? [], 'excel_auto_filter', true)),
                'excel_detail_sheet_name' => Str::limit((string) (($validated['excel_detail_sheet_name'] ?? null) ?: 'Reporting'), 31, ''),
                'excel_graph_sheet_name' => Str::limit((string) (($validated['excel_graph_sheet_name'] ?? null) ?: 'Synthese graphique'), 31, ''),
                'pdf_show_level_legend' => $request->boolean('pdf_show_level_legend', (bool) Arr::get($template?->layout_config ?? [], 'pdf_show_level_legend', true)),
                'pdf_show_kpi_cards' => $request->boolean('pdf_show_kpi_cards', (bool) Arr::get($template?->layout_config ?? [], 'pdf_show_kpi_cards', true)),
                'word_include_toc' => $request->boolean('word_include_toc', (bool) Arr::get($template?->layout_config ?? [], 'word_include_toc', false)),
                'word_page_break_after_summary' => $request->boolean('word_page_break_after_summary', (bool) Arr::get($template?->layout_config ?? [], 'word_page_break_after_summary', false)),
            ],
            'content_config' => [
                'visible_columns' => $visibleColumns,
                'dynamic_variables' => $dynamicVariables,
            ],
            'style_config' => [
                'color_primary' => (string) (($validated['color_primary'] ?? null) ?: '#1E3A8A'),
                'color_secondary' => (string) (($validated['color_secondary'] ?? null) ?: '#3B82F6'),
                'font_family' => (string) (($validated['font_family'] ?? null) ?: 'Inter'),
            ],
            'meta_config' => [
                'document_title' => (string) (($validated['document_title'] ?? null) ?: $validated['name']),
                'document_subtitle' => (string) (($validated['document_subtitle'] ?? null) ?: ''),
                'filename_prefix' => (string) (($validated['filename_prefix'] ?? null) ?: 'reporting_anbg'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTemplatePreview(ExportTemplate $template, User $user): array
    {
        if ($template->module === 'reporting' && $template->report_type === 'consolidated_reporting') {
            $payload = app(\App\Services\Analytics\ReportingAnalyticsService::class)->buildPayload($user, true, true);
            $payload['exportTemplate'] = $template;

            if ($template->format === ExportTemplate::FORMAT_PDF) {
                return [
                    'type' => 'html',
                    'label' => 'Apercu PDF',
                    'html' => view('workspace.monitoring.reporting-pdf', $payload)->render(),
                ];
            }

            if ($template->format === ExportTemplate::FORMAT_WORD) {
                return [
                    'type' => 'html',
                    'label' => 'Apercu Word',
                    'html' => view('workspace.monitoring.reporting-word', $payload)->render(),
                ];
            }

            if ($template->format === ExportTemplate::FORMAT_EXCEL) {
                return [
                    'type' => 'excel',
                    'label' => 'Apercu Excel',
                    'sheets' => [
                        ['name' => 'Synthese', 'enabled' => true],
                        ['name' => 'Graphiques', 'enabled' => (bool) ($template->blocks_config['include_charts'] ?? true)],
                    ],
                    'summary' => [
                        'title' => $template->documentTitle(),
                        'subtitle' => $template->documentSubtitle(),
                        'columns' => $template->content_config['visible_columns'] ?? [],
                        'variables' => $template->content_config['dynamic_variables'] ?? [],
                        'advanced_options' => [
                            'excel_freeze_header' => (bool) ($template->layout_config['excel_freeze_header'] ?? true),
                            'excel_auto_filter' => (bool) ($template->layout_config['excel_auto_filter'] ?? true),
                            'excel_detail_sheet_name' => $template->layout_config['excel_detail_sheet_name'] ?? 'Reporting',
                            'excel_graph_sheet_name' => $template->layout_config['excel_graph_sheet_name'] ?? 'Synthese graphique',
                        ],
                    ],
                ];
            }
        }

        return [
            'type' => 'generic',
            'label' => 'Apercu configuration',
            'summary' => [
                'format' => $template->formatLabel(),
                'module' => $template->module,
                'report_type' => $template->report_type,
                'reading_level' => $template->reading_level ?: 'non borne',
                'blocks' => $template->blocks_config ?? [],
                'layout' => $template->layout_config ?? [],
            ],
        ];
    }

    private function clearDefaultTemplates(ExportTemplate $template): void
    {
        $query = ExportTemplate::query()
            ->where('module', $template->module)
            ->where('report_type', $template->report_type)
            ->where('format', $template->format)
            ->where('id', '!=', $template->id);

        foreach ([
            'target_profile' => $template->target_profile,
            'reading_level' => $template->reading_level,
        ] as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $value);
            }
        }

        $query->update(['is_default' => false]);
    }

    private function clearDefaultAssignments(
        string $module,
        string $reportType,
        string $format,
        ?string $targetProfile = null,
        ?string $readingLevel = null,
        ?int $directionId = null,
        ?int $serviceId = null,
        ?int $exceptId = null
    ): void {
        $query = ExportTemplateAssignment::query()
            ->where('module', $module)
            ->where('report_type', $reportType)
            ->where('format', $format)
            ->where('is_default', true);

        foreach ([
            'target_profile' => $targetProfile,
            'reading_level' => $readingLevel,
            'direction_id' => $directionId,
            'service_id' => $serviceId,
        ] as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $value);
            }
        }

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $query->update(['is_default' => false]);
    }

    private function ensureBaseAssignment(ExportTemplate $template, User $user): void
    {
        $template->assignments()->firstOrCreate(
            [
                'module' => $template->module,
                'report_type' => $template->report_type,
                'format' => $template->format,
                'target_profile' => $template->target_profile,
                'reading_level' => $template->reading_level,
                'direction_id' => null,
                'service_id' => null,
            ],
            [
                'is_default' => true,
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]
        );
    }

    private function organizationUserQuery(Request $request): Builder
    {
        $query = User::query()->orderBy('name');

        $query->when($request->filled('q'), function (Builder $builder) use ($request): void {
            $search = trim((string) $request->string('q'));
            $builder->where(function (Builder $subQuery) use ($search): void {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('agent_matricule', 'like', "%{$search}%");
            });
        });
        $query->when(
            $request->filled('role'),
            function (Builder $builder) use ($request): void {
                $selectedRole = (string) $request->string('role');

                if ($this->roleRegistry->isCustomRole($selectedRole)) {
                    $builder->where('custom_role_code', $selectedRole);

                    return;
                }

                $builder
                    ->where('role', $selectedRole)
                    ->where(function (Builder $subQuery): void {
                        $subQuery->whereNull('custom_role_code')
                            ->orWhere('custom_role_code', '');
                    });
            }
        );
        $query->when(
            $request->filled('direction_id'),
            fn (Builder $builder) => $builder->where('direction_id', (int) $request->integer('direction_id'))
        );
        $query->when(
            $request->filled('service_id'),
            fn (Builder $builder) => $builder->where('service_id', (int) $request->integer('service_id'))
        );
        $query->when(
            $request->filled('is_active'),
            fn (Builder $builder) => $builder->where('is_active', $request->string('is_active') === '1')
        );
        $query->when($request->filled('suspension_state'), function (Builder $builder) use ($request): void {
            $state = (string) $request->string('suspension_state');

            if ($state === 'suspended') {
                $builder->whereNotNull('suspended_until')->where('suspended_until', '>', now());
            }

            if ($state === 'not_suspended') {
                $builder->where(function (Builder $subQuery): void {
                    $subQuery->whereNull('suspended_until')
                        ->orWhere('suspended_until', '<=', now());
                });
            }
        });

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function moduleOptions(): array
    {
        return ['reporting', 'alertes', 'actions', 'pas', 'pao', 'pta'];
    }

    /**
     * @return array<int, string>
     */
    private function profileOptions(): array
    {
        return $this->roleRegistry->codes();
    }

    /**
     * @return array<string, string>
     */
    private function profileLabels(): array
    {
        return $this->roleRegistry->labels();
    }

    /**
     * @return array<int, string>
     */
    private function readingLevelOptions(): array
    {
        return ['interne', 'provisoire', 'valide', 'officiel'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function modulePreviewForRole(string $role): array
    {
        $permissions = $this->rolePermissionSettings->forRole($role);
        $baseRole = $this->roleRegistry->baseRole($role);

        return collect($this->workspaceModuleSettings->configuredModules())
            ->sortBy('order')
            ->filter(function (array $module) use ($permissions, $baseRole): bool {
                if (! ($module['enabled'] ?? false) && ($module['code'] ?? null) !== 'super_admin') {
                    return false;
                }

                return match ((string) ($module['code'] ?? '')) {
                    'super_admin' => $baseRole === User::ROLE_SUPER_ADMIN,
                    'messagerie' => in_array('messagerie.read', $permissions, true),
                    'pas', 'pao', 'pta' => in_array('planning.read', $permissions, true),
                    'execution' => in_array('planning.read', $permissions, true) || $baseRole === User::ROLE_AGENT,
                    'alertes' => in_array('planning.read', $permissions, true) && in_array('alerts.read', $permissions, true),
                    'reporting' => in_array('planning.read', $permissions, true) && in_array('reporting.read', $permissions, true),
                    'referentiel' => collect(['referentiel.read', 'referentiel.write', 'users.manage', 'users.manage_roles'])
                        ->contains(fn (string $permission): bool => in_array($permission, $permissions, true)),
                    'audit' => in_array('audit.read', $permissions, true),
                    'api_docs' => in_array('api_docs.read', $permissions, true),
                    'retention' => collect(['retention.read', 'retention.manage'])
                        ->contains(fn (string $permission): bool => in_array($permission, $permissions, true)),
                    'delegations' => in_array('delegations.manage', $permissions, true),
                    default => false,
                };
            })
            ->map(fn (array $module): array => Arr::only($module, ['code', 'label', 'description']))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function compareRolePermissions(string $leftRole, string $rightRole): array
    {
        $leftPermissions = collect($this->rolePermissionSettings->forRole($leftRole))->values();
        $rightPermissions = collect($this->rolePermissionSettings->forRole($rightRole))->values();

        return [
            'shared' => $leftPermissions->intersect($rightPermissions)->values()->all(),
            'left_only' => $leftPermissions->diff($rightPermissions)->values()->all(),
            'right_only' => $rightPermissions->diff($leftPermissions)->values()->all(),
            'left_modules' => collect($this->modulePreviewForRole($leftRole))->pluck('label')->values()->all(),
            'right_modules' => collect($this->modulePreviewForRole($rightRole))->pluck('label')->values()->all(),
        ];
    }

    private function activeSessionsCount(): int
    {
        $table = $this->sessionTable();
        if ($table === null) {
            return 0;
        }

        return (int) \Illuminate\Support\Facades\Cache::remember(
            'super_admin:active_sessions_count',
            now()->addSeconds(30),
            fn (): int => (int) DB::table($table)->count()
        );
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array{sessions_total:int,last_activity:\Illuminate\Support\Carbon|null}>
     */
    private function sessionSummariesForUsers(array $userIds): array
    {
        $table = $this->sessionTable();
        if ($table === null || $userIds === []) {
            return [];
        }

        return DB::table($table)
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, COUNT(*) as sessions_total, MAX(last_activity) as last_activity')
            ->groupBy('user_id')
            ->get()
            ->mapWithKeys(function (object $row): array {
                return [
                    (int) $row->user_id => [
                        'sessions_total' => (int) ($row->sessions_total ?? 0),
                        'last_activity' => isset($row->last_activity)
                            ? \Illuminate\Support\Carbon::createFromTimestamp((int) $row->last_activity)
                            : null,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, int>  $userIds
     * @return \Illuminate\Support\Collection<int, JournalAudit>
     */
    private function loginHistoryForUsers(array $userIds, ?Request $request = null)
    {
        if ($userIds === []) {
            return JournalAudit::query()->whereRaw('1 = 0');
        }

        $query = JournalAudit::query()
            ->with('user:id,name,email')
            ->where('module', 'auth')
            ->whereIn('user_id', $userIds)
            ->whereIn('action', ['login_success', 'logout']);

        if ($request instanceof Request) {
            $query->when(
                $request->filled('auth_action'),
                fn ($builder) => $builder->where('action', (string) $request->string('auth_action'))
            );
            $query->when(
                $request->filled('auth_date_from'),
                fn ($builder) => $builder->whereDate('created_at', '>=', (string) $request->string('auth_date_from'))
            );
            $query->when(
                $request->filled('auth_date_to'),
                fn ($builder) => $builder->whereDate('created_at', '<=', (string) $request->string('auth_date_to'))
            );
        }

        return $query->latest('id');
    }

    private function revokeSessionsForUser(User $managedUser): int
    {
        // Revoke Sanctum API tokens
        $managedUser->tokens()->delete();

        // Revoke web sessions
        $table = $this->sessionTable();
        if ($table === null) {
            return 0;
        }

        return (int) DB::table($table)
            ->where('user_id', $managedUser->id)
            ->delete();
    }

    private function sessionTable(): ?string
    {
        $table = (string) config('session.table', 'sessions');

        return Schema::hasTable($table) ? $table : null;
    }

    /**
     * Generates a cryptographically random temporary password.
     * Format: 4 uppercase + 4 digits + 2 symbols + 2 lowercase = 12 characters.
     */
    private function generateSecureTemporaryPassword(): string
    {
        $upper   = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 4);
        $digits  = substr(str_shuffle('23456789'), 0, 4);
        $symbols = substr(str_shuffle('@#$!%&'), 0, 2);
        $lower   = substr(str_shuffle('abcdefghjkmnpqrstuvwxyz'), 0, 2);

        return str_shuffle($upper.$digits.$symbols.$lower);
    }

    private function auditAnchor(string $group, string $key, string $value): PlatformSetting
    {
        return PlatformSetting::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value]
        );
    }

    /**
     * @return array<string, string>
     */
    private function storeBrandingAssets(Request $request): array
    {
        $paths = [];

        foreach ([
            'logo_mark' => 'logo_mark_path',
            'logo_wordmark' => 'logo_wordmark_path',
            'logo_full' => 'logo_full_path',
            'favicon' => 'favicon_path',
        ] as $input => $settingKey) {
            $file = $request->file($input);
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $paths[$settingKey] = $file->store('branding', 'public');
        }

        return $paths;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseUserImportFile(UploadedFile $file): array
    {
        $contents = trim((string) file_get_contents($file->getRealPath()));
        if ($contents === '') {
            return [];
        }

        $lines = preg_split("/\\r\\n|\\n|\\r/", $contents) ?: [];
        if ($lines === []) {
            return [];
        }

        $delimiter = substr_count((string) ($lines[0] ?? ''), ';') >= substr_count((string) ($lines[0] ?? ''), ',')
            ? ';'
            : ',';

        $header = array_map(
            static fn ($value): string => Str::lower(trim((string) $value)),
            str_getcsv(array_shift($lines), $delimiter)
        );

        return collect($lines)
            ->map(function (string $line) use ($header, $delimiter): ?array {
                if (trim($line) === '') {
                    return null;
                }

                $cells = str_getcsv($line, $delimiter);
                if ($cells === [] || $cells === [null]) {
                    return null;
                }

                $row = [];
                foreach ($header as $index => $key) {
                    if ($key === '') {
                        continue;
                    }

                    $row[$key] = trim((string) ($cells[$index] ?? ''));
                }

                return $row;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function resolveDirectionIdFromCode(mixed $directionCode): ?int
    {
        $code = trim((string) $directionCode);
        if ($code === '') {
            return null;
        }

        return Direction::query()
            ->where('code', $code)
            ->value('id');
    }

    private function resolveServiceIdFromCodes(mixed $serviceCode, mixed $directionCode = null): ?int
    {
        $code = trim((string) $serviceCode);
        if ($code === '') {
            return null;
        }

        $query = Service::query()->where('code', $code);

        $directionId = $this->resolveDirectionIdFromCode($directionCode);
        if ($directionId !== null) {
            $query->where('direction_id', $directionId);
        }

        return $query->value('id');
    }

    private function normalizeBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = Str::lower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'oui', 'yes'], true);
    }
}
