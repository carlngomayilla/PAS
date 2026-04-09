<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminSimulationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_run_platform_simulation(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.simulation.index'))
            ->assertOk()
            ->assertSee('Mode simulation');

        $this->followingRedirects()
            ->actingAs($superAdmin)
            ->post(route('workspace.super-admin.simulation.run'), [
                'actions_service_validation_enabled' => '0',
                'actions_direction_validation_enabled' => '0',
                'actions_auto_complete_when_target_reached' => '1',
                'actions_min_progress_for_closure' => '70',
            ])
            ->assertOk()
            ->assertSee('Toutes les actions visibles')
            ->assertSee('Agent -> cloture directe')
            ->assertSee('Vigilances de simulation')
            ->assertSee('Apercu dashboard')
            ->assertSee('Apercu exports');

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'platform_simulation_run',
        ]);
    }
}
