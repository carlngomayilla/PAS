<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RolePermissionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminRolePermissionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_manage_role_permission_matrix_and_admin_cannot_access_it(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.roles.edit'))
            ->assertOk()
            ->assertSee('Roles et permissions');

        $this->actingAs($admin)
            ->get(route('workspace.super-admin.roles.edit'))
            ->assertForbidden();

        $payload = app(RolePermissionSettings::class)->all();
        $payload[User::ROLE_CABINET] = array_values(array_diff($payload[User::ROLE_CABINET], ['audit.read']));

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.roles.update', ['simulate_role' => User::ROLE_CABINET]), [
                'permissions' => $payload,
            ])
            ->assertRedirect(route('workspace.super-admin.roles.edit', ['simulate_role' => User::ROLE_CABINET]));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'role_permissions',
            'key' => 'role_permissions_'.User::ROLE_CABINET,
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'role_permission_settings_update',
        ]);
    }

    public function test_service_role_without_reporting_permission_loses_reporting_module_and_route_access(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $serviceUser = User::query()
            ->where('role', User::ROLE_SERVICE)
            ->whereNotNull('service_id')
            ->firstOrFail();

        $payload = app(RolePermissionSettings::class)->all();
        $payload[User::ROLE_SERVICE] = array_values(array_diff($payload[User::ROLE_SERVICE], ['reporting.read']));

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.roles.update'), [
                'permissions' => $payload,
            ])
            ->assertRedirect();

        $this->actingAs($serviceUser)
            ->get('/workspace')
            ->assertOk()
            ->assertDontSee('Reporting');

        $this->actingAs($serviceUser)
            ->get(route('workspace.reporting'))
            ->assertForbidden();
    }

    public function test_direction_role_can_be_granted_scoped_user_management_access(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $directionUser = User::query()
            ->where('role', User::ROLE_DIRECTION)
            ->whereNotNull('direction_id')
            ->firstOrFail();
        $colleague = User::query()
            ->where('direction_id', $directionUser->direction_id)
            ->where('id', '!=', $directionUser->id)
            ->firstOrFail();
        $outsider = User::query()
            ->where('direction_id', '!=', $directionUser->direction_id)
            ->firstOrFail();

        $payload = app(RolePermissionSettings::class)->all();
        $payload[User::ROLE_DIRECTION] = array_values(array_unique(array_merge(
            $payload[User::ROLE_DIRECTION],
            ['users.manage']
        )));

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.roles.update'), [
                'permissions' => $payload,
            ])
            ->assertRedirect();

        $this->actingAs($directionUser)
            ->get(route('workspace.referentiel.utilisateurs.index'))
            ->assertOk()
            ->assertSee((string) $colleague->email)
            ->assertDontSee((string) $outsider->email);
    }

    public function test_admin_loses_user_management_route_when_permissions_are_revoked(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();

        $payload = app(RolePermissionSettings::class)->all();
        $payload[User::ROLE_ADMIN] = array_values(array_diff(
            $payload[User::ROLE_ADMIN],
            ['users.manage', 'users.manage_roles']
        ));

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.roles.update'), [
                'permissions' => $payload,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('workspace.referentiel.utilisateurs.index'))
            ->assertForbidden();
    }
}
