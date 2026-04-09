<?php

namespace Tests\Feature;

use App\Services\WorkflowSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminWorkflowSettingsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_update_action_workflow_settings(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.workflow.edit'))
            ->assertOk()
            ->assertSee('Workflow et validations')
            ->assertSee('Agent -> Chef de service -> Direction');

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.workflow.update'), [
                'actions_service_validation_enabled' => '1',
                'actions_direction_validation_enabled' => '0',
                'actions_rejection_comment_required' => '0',
                'pas_workflow_mode' => 'approval_only',
                'pao_workflow_mode' => 'direct_approval',
                'pta_workflow_mode' => 'full',
            ])
            ->assertRedirect(route('workspace.super-admin.workflow.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'workflow',
            'key' => 'actions_direction_validation_enabled',
            'value' => '0',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'workflow_settings_update',
        ]);

        $summary = app(WorkflowSettings::class)->actionValidationSummary();
        $this->assertSame('service', $summary['final_stage']);
        $this->assertFalse($summary['rejection_comment_required']);
        $this->assertSame('approval_only', app(WorkflowSettings::class)->planningWorkflowMode('pas'));
        $this->assertSame('direct_approval', app(WorkflowSettings::class)->planningWorkflowMode('pao'));

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.workflow.edit'))
            ->assertOk()
            ->assertSee('Agent -> Chef de service')
            ->assertSee('Chef de service')
            ->assertSee('Optionnel')
            ->assertSee('Validation directe');
    }

    public function test_admin_cannot_access_or_update_workflow_settings(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('workspace.super-admin.workflow.edit'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('workspace.super-admin.workflow.update'), [
                'actions_service_validation_enabled' => '0',
                'actions_direction_validation_enabled' => '0',
                'actions_rejection_comment_required' => '0',
                'pas_workflow_mode' => 'full',
                'pao_workflow_mode' => 'full',
                'pta_workflow_mode' => 'full',
            ])
            ->assertForbidden();
    }
}
