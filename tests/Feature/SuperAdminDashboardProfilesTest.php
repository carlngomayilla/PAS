<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardProfileSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminDashboardProfilesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_configure_dashboard_profiles_and_service_dashboard_reflects_it(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();
        $serviceUser = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('workspace.super-admin.dashboard-profiles.edit'))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.dashboard-profiles.edit'))
            ->assertOk()
            ->assertSee('Dashboards par profil');

        $payload = ['profiles' => []];
        foreach (app(DashboardProfileSettings::class)->all() as $role => $profile) {
            $payload['profiles'][$role] = [
                'cards' => [],
            ];

            foreach (['overview_enabled', 'comparison_chart_enabled', 'status_chart_enabled', 'trend_chart_enabled', 'support_chart_enabled'] as $flag) {
                if ((bool) ($profile[$flag] ?? false)) {
                    $payload['profiles'][$role][$flag] = '1';
                }
            }

            foreach ($profile['cards'] as $card) {
                $payload['profiles'][$role]['cards'][$card['code']] = [
                    'order' => (string) $card['order'],
                    'size' => $card['code'] === 'actions_totales' ? 'lg' : ($card['size'] ?? 'md'),
                    'tone' => $card['code'] === 'actions_totales' ? 'info' : ($card['tone'] ?? 'auto'),
                    'target_route' => $card['code'] === 'actions_totales' ? 'actions' : ($card['target_route'] ?? ''),
                    'target_filters' => $card['code'] === 'actions_totales' ? 'statut=en_retard' : ($card['target_filters'] ?? ''),
                ];

                if ((bool) ($card['enabled'] ?? false)) {
                    $payload['profiles'][$role]['cards'][$card['code']]['enabled'] = '1';
                }
            }
        }

        unset($payload['profiles']['service']['overview_enabled']);
        unset($payload['profiles']['service']['cards']['actions_validees_service']['enabled']);

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.dashboard-profiles.update'), $payload)
            ->assertRedirect(route('workspace.super-admin.dashboard-profiles.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'dashboard_profiles',
            'key' => 'dashboard_profile_service',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'dashboard_profile_settings_update',
        ]);
        $this->assertTrue(
            collect(app(DashboardProfileSettings::class)->all())
                ->flatMap(fn (array $profile): array => $profile['cards'] ?? [])
                ->contains(fn (array $card): bool => ($card['size'] ?? null) === 'lg' && ($card['target_route'] ?? null) === 'actions')
        );

        $this->actingAs($serviceUser)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Pilotage du service')
            ->assertDontSee('Actions validees service')
            ->assertDontSee('Performance des agents');
    }
}
