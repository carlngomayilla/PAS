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
        // Nouvelle logique métier ANBG : un seul mode workflow PAS/PAO/PTA = « canonical ».
        // Les anciens modes (direct_approval, approval_only, full) ont été supprimés
        // car non conformes au cycle métier validé (PAO validé automatiquement, etc.).
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.workflow.edit'))
            ->assertOk()
            ->assertSee('Workflow et validations');

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.workflow.update'), [
                'actions_service_validation_enabled' => '1',
                'actions_direction_validation_enabled' => '0',
                'actions_rejection_comment_required' => '0',
                'pas_workflow_mode' => 'canonical',
                'pao_workflow_mode' => 'canonical',
                'pta_workflow_mode' => 'canonical',
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
        $this->assertSame('canonical', app(WorkflowSettings::class)->planningWorkflowMode('pas'));
        $this->assertSame('canonical', app(WorkflowSettings::class)->planningWorkflowMode('pao'));
        $this->assertSame('canonical', app(WorkflowSettings::class)->planningWorkflowMode('pta'));
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
                'pas_workflow_mode' => 'canonical',
                'pao_workflow_mode' => 'canonical',
                'pta_workflow_mode' => 'canonical',
            ])
            ->assertForbidden();
    }
}
