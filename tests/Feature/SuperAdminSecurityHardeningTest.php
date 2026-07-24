<?php

namespace Tests\Feature;

use App\Models\JournalAudit;
use App\Models\PlatformSetting;
use App\Models\PlatformSettingSnapshot;
use App\Models\User;
use App\Services\PlatformMaintenanceService;
use App\Services\PlatformSnapshotService;
use App\Services\RoleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminSecurityHardeningTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_custom_role_code_cannot_promote_a_non_super_admin_account(): void
    {
        $forgedUser = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'custom_role_code' => User::ROLE_SUPER_ADMIN,
            'password_changed_at' => now(),
        ]);

        $this->assertFalse($forgedUser->isSuperAdmin());
        $this->assertFalse($forgedUser->hasRole(User::ROLE_SUPER_ADMIN));
        $this->assertSame(User::ROLE_AGENT, $forgedUser->effectiveRoleCode());
        $this->assertFalse($forgedUser->hasPermission('super_admin.access'));

        $this->actingAs($forgedUser)
            ->get(route('workspace.super-admin.index'))
            ->assertForbidden();
    }

    public function test_super_admin_cannot_be_selected_or_duplicated_as_custom_role_base(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.roles.registry.update'), [
                'custom_roles' => [[
                    'code' => 'administrateur_total',
                    'label' => 'Administrateur total',
                    'base_role' => User::ROLE_SUPER_ADMIN,
                    'description' => 'Tentative de délégation interdite.',
                    'active' => '1',
                ]],
            ])
            ->assertSessionHasErrors('custom_roles.0.base_role');

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.roles.registry.duplicate'), [
                'source_role' => User::ROLE_SUPER_ADMIN,
                'target_code' => 'copie_super_admin',
                'target_label' => 'Copie Super Admin',
            ])
            ->assertSessionHasErrors('source_role');

        $this->assertArrayNotHasKey('administrateur_total', app(RoleRegistryService::class)->customRoles());
        $this->assertArrayNotHasKey(User::ROLE_SUPER_ADMIN, app(RoleRegistryService::class)->customRoleBaseOptions());
    }

    public function test_forged_snapshot_group_is_rejected_by_request_and_domain_service(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        PlatformSetting::query()->updateOrCreate(
            ['key' => 'app_name'],
            [
                'group' => 'general',
                'value' => 'Configuration vivante',
            ],
        );
        $snapshot = PlatformSettingSnapshot::query()->create([
            'label' => 'Snapshot apparence',
            'payload' => [
                'settings' => [
                    ['group' => 'appearance', 'key' => 'default_theme', 'value' => 'light'],
                ],
            ],
            'created_by' => $superAdmin->id,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.snapshots.restore', $snapshot), [
                'partial_restore' => '1',
                'groups' => ['general'],
            ])
            ->assertSessionHasErrors('groups.0');

        try {
            app(PlatformSnapshotService::class)->restoreSnapshotGroups($snapshot, ['general'], $superAdmin);
            $this->fail('La restauration d’un groupe absent du snapshot aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('groups', $exception->errors());
        }

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'general',
            'key' => 'app_name',
            'value' => 'Configuration vivante',
        ]);
        $this->assertNull($snapshot->fresh()->last_restored_at);
    }

    public function test_maintenance_mode_requires_current_password_confirmation(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.maintenance.run', 'maintenance_on'))
            ->assertSessionHasErrors('current_password');

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.maintenance.run', 'maintenance_on'), [
                'current_password' => 'mot-de-passe-incorrect',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertDatabaseMissing('journal_audit', [
            'module' => 'super_admin',
            'action' => 'maintenance_maintenance_on',
        ]);
    }

    public function test_maintenance_activation_uses_a_new_random_secret_each_time(): void
    {
        $secrets = [];
        Artisan::shouldReceive('call')
            ->twice()
            ->withArgs(function (string $command, array $parameters) use (&$secrets): bool {
                if ($command !== 'down' || ! is_string($parameters['--secret'] ?? null)) {
                    return false;
                }

                $secrets[] = $parameters['--secret'];

                return true;
            })
            ->andReturn(0);
        Artisan::shouldReceive('output')->twice()->andReturn('Mode maintenance activé.');

        $service = app(PlatformMaintenanceService::class);
        $first = $service->perform('maintenance_on');
        $second = $service->perform('maintenance_on');

        $this->assertCount(2, $secrets);
        $this->assertSame(64, strlen($secrets[0]));
        $this->assertSame(64, strlen($secrets[1]));
        $this->assertNotSame($secrets[0], $secrets[1]);
        $this->assertStringNotContainsString('super-admin-bypass', (string) $first['bypass_url']);
        $this->assertStringNotContainsString('super-admin-bypass', (string) $second['bypass_url']);
    }

    public function test_maintenance_bypass_secret_is_redirected_once_but_not_persisted_in_audit(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $secret = 'secret-temporaire-qui-ne-doit-pas-etre-journalise';
        $bypassUrl = url('/'.$secret);
        $maintenanceService = Mockery::mock(PlatformMaintenanceService::class);
        $maintenanceService->shouldReceive('actions')->once()->andReturn([
            'maintenance_on' => 'Activer le mode maintenance',
        ]);
        $maintenanceService->shouldReceive('perform')->once()->with('maintenance_on')->andReturn([
            'action' => 'maintenance_on',
            'label' => 'Activer le mode maintenance',
            'exit_code' => 0,
            'output' => 'Mode maintenance activé.',
            'status' => ['maintenance_active' => true],
            'bypass_url' => $bypassUrl,
        ]);
        $this->app->instance(PlatformMaintenanceService::class, $maintenanceService);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.maintenance.run', 'maintenance_on'), [
                'current_password' => 'Pass@12345',
            ])
            ->assertRedirect($bypassUrl);

        $audit = JournalAudit::query()
            ->where('module', 'super_admin')
            ->where('action', 'maintenance_maintenance_on')
            ->latest('id')
            ->firstOrFail();
        $anchor = PlatformSetting::query()
            ->where('group', 'maintenance')
            ->where('key', 'maintenance_last_action')
            ->firstOrFail();

        $this->assertStringNotContainsString($secret, json_encode($audit->nouvelle_valeur, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($secret, (string) $anchor->value);
        $this->assertArrayNotHasKey('bypass_url', (array) $audit->nouvelle_valeur);
    }
}
