<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminOrgUsersTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_export_import_and_bulk_manage_users(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $direction = Direction::query()->firstOrFail();
        $service = Service::query()->where('direction_id', $direction->id)->firstOrFail();
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'email' => 'bulk.agent@anbg.test',
            'agent_matricule' => 'BULK-001',
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.organization.users.export', ['role' => User::ROLE_AGENT]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = implode(PHP_EOL, [
            'name;email;role;direction_code;service_code;agent_matricule;agent_fonction;agent_telephone;is_active;is_agent;suspended_until;suspension_reason;password',
            'Importe ANBG;importe.user@anbg.test;agent;'.$direction->code.';'.$service->code.';IMP-001;Charge import;060101010;1;1;;;Password-Import@123',
            'Bulk Agent MAJ;bulk.agent@anbg.test;service;'.$direction->code.';'.$service->code.';BULK-001;Responsable bulk;060202020;1;0;'.now()->addDays(2)->toDateString().';Suspension import;',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.import'), [
                'users_file' => UploadedFile::fake()->createWithContent('users-import.csv', $csv),
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $imported = User::query()->where('email', 'importe.user@anbg.test')->firstOrFail();
        $this->assertSame(User::ROLE_AGENT, $imported->role);
        $this->assertTrue(Hash::check('Password-Import@123', $imported->password));

        $agent->refresh();
        $this->assertSame(User::ROLE_SERVICE, $agent->role);
        $this->assertSame('Responsable bulk', $agent->agent_fonction);
        $this->assertTrue($agent->isSuspended());

        $secondAgent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'email' => 'bulk.second@anbg.test',
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.bulk'), [
                'user_ids' => [$agent->id, $secondAgent->id],
                'bulk_action' => 'assign_role',
                'bulk_role' => User::ROLE_DIRECTION,
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $this->assertSame(User::ROLE_DIRECTION, $agent->fresh()->role);
        $this->assertSame(User::ROLE_DIRECTION, $secondAgent->fresh()->role);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.bulk'), [
                'user_ids' => [$agent->id, $secondAgent->id],
                'bulk_action' => 'clear_suspension',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $this->assertNull($agent->fresh()->suspended_until);

        $firstPasswordHash = (string) $agent->fresh()->password;
        $secondPasswordHash = (string) $secondAgent->fresh()->password;

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.users.bulk'), [
                'user_ids' => [$agent->id, $secondAgent->id],
                'bulk_action' => 'reset_password',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        // Le mot de passe est aleatoire: on verifie seulement qu il a change sur chaque compte.
        $agent->refresh();
        $secondAgent->refresh();
        $this->assertNotEmpty($agent->password);
        $this->assertNotEmpty($secondAgent->password);
        $this->assertNotSame($firstPasswordHash, $agent->password);
        $this->assertNotSame($secondPasswordHash, $secondAgent->password);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'organization_user_bulk_reset_password',
        ]);

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'organization_user_bulk_assign_role',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'organization_user_import_create',
        ]);
    }

    public function test_super_admin_can_export_filtered_login_history(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'email' => 'history.agent@anbg.test',
        ]);

        \App\Models\JournalAudit::query()->create([
            'user_id' => $agent->id,
            'module' => 'auth',
            'entite_type' => User::class,
            'entite_id' => $agent->id,
            'action' => 'login_success',
            'nouvelle_valeur' => ['login_identifier' => $agent->email],
            'adresse_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);

        $response = $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.organization.login-history.export', [
                'q' => 'history.agent',
                'auth_action' => 'login_success',
            ]));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('history.agent@anbg.test', $csv);
        $this->assertStringContainsString('login_success', $csv);
    }
}


