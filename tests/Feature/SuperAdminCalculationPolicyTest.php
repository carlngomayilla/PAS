<?php

namespace Tests\Feature;

use App\Services\ActionCalculationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminCalculationPolicyTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    public function test_super_admin_can_update_action_calculation_policy(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.calculation.edit'))
            ->assertOk()
            ->assertSee('Politique de calcul des actions')
            ->assertSee('Validation finale SCIQ / Planification');

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.calculation.update'), [
                'actions_official_validation_status' => ActionCalculationSettings::LEVEL_VALIDATION_SCIQ,
            ])
            ->assertRedirect(route('workspace.super-admin.calculation.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'action_calculation',
            'key' => 'actions_official_validation_status',
            'value' => ActionCalculationSettings::LEVEL_VALIDATION_SCIQ,
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'action_calculation_policy_update',
        ]);

        $settings = app(ActionCalculationSettings::class);
        $this->assertSame(ActionCalculationSettings::LEVEL_VALIDATION_SCIQ, $settings->officialValidationStatus());
        $this->assertSame(['statut_validation' => 'validee_controle'], $settings->officialRouteFilters());

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.calculation.edit'))
            ->assertOk()
            ->assertSee('Validation finale SCIQ / Planification')
            ->assertSee('Filtre actif');
    }

    public function test_admin_can_access_and_update_action_calculation_policy(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('workspace.super-admin.calculation.edit'))
            ->assertOk()
            ->assertSee('Politique de calcul des actions');

        $this->actingAs($admin)
            ->put(route('workspace.super-admin.calculation.update'), [
                'actions_official_validation_status' => ActionCalculationSettings::LEVEL_VALIDATION_CHEF,
            ])
            ->assertRedirect(route('workspace.super-admin.calculation.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'action_calculation',
            'key' => 'actions_official_validation_status',
            'value' => ActionCalculationSettings::LEVEL_VALIDATION_CHEF,
        ]);
    }
}
