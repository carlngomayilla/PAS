<?php

namespace Tests\Feature;

use App\Services\DynamicReferentialSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminDynamicReferentialsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_update_dynamic_referentials_and_the_action_form_reflects_them(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.referentials.edit'))
            ->assertOk()
            ->assertSee('Referentiels dynamiques');

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.referentials.update'), [
                'action_target_type_label_quantitative' => 'Quantifiee',
                'action_target_type_label_qualitative' => 'Qualifiee',
                'action_unit_suggestions' => "inspections terrain\nmissions de suivi\nrapports consolides",
                'pao_operational_priorities' => "moyenne renforcee\nhaute priorite\ncritique immediate",
                'kpi_unit_suggestions' => "%\npoints\njours",
                'justificatif_category_label_hebdomadaire' => 'Piece hebdomadaire',
                'justificatif_category_label_final' => 'Piece finale',
                'justificatif_category_label_evaluation_chef' => 'Validation chef',
                'justificatif_category_label_evaluation_direction' => 'Validation direction',
                'justificatif_category_label_financement' => 'Piece budgetaire',
                'alert_level_label_warning' => 'Vigilance',
                'alert_level_label_critical' => 'Critique',
                'alert_level_label_urgence' => 'Urgence',
                'alert_level_label_info' => 'Information',
                'validation_status_label_non_soumise' => 'Brouillon',
                'validation_status_label_soumise_chef' => 'Soumise service',
                'validation_status_label_rejetee_chef' => 'Retour service',
                'validation_status_label_validee_chef' => 'Validee service',
                'validation_status_label_rejetee_direction' => 'Retour direction',
                'validation_status_label_validee_direction' => 'Officielle',
            ])
            ->assertRedirect(route('workspace.super-admin.referentials.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'dynamic_referentials',
            'key' => 'action_unit_suggestions',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'dynamic_referentials_update',
        ]);

        $settings = app(DynamicReferentialSettings::class);
        $this->assertSame('Quantifiee', $settings->actionTargetTypeLabels()['quantitative']);
        $this->assertSame(
            ['moyenne renforcee', 'haute priorite', 'critique immediate'],
            $settings->paoOperationalPriorities()
        );
        $this->assertSame(['%', 'points', 'jours'], $settings->kpiUnitSuggestions());
        $this->assertSame('Piece finale', $settings->justificatifCategoryLabels()['final']);
        $this->assertSame('Vigilance', $settings->alertLevelLabels()['warning']);
        $this->assertSame('Officielle', $settings->validationStatusLabels()['validee_direction']);

        $this->actingAs($admin)
            ->get(route('workspace.actions.create'))
            ->assertOk()
            ->assertSee('Quantifiee')
            ->assertSee('Qualifiee')
            ->assertSee('inspections terrain')
            ->assertSee('%')
            ->assertSee('points');

        $this->actingAs($admin)
            ->get(route('workspace.kpi.create'))
            ->assertRedirect(route('workspace.actions.create').'#action-indicator-settings');
    }

    public function test_admin_cannot_access_dynamic_referential_settings(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('workspace.super-admin.referentials.edit'))
            ->assertForbidden();
    }
}
