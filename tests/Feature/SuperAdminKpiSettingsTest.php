<?php

namespace Tests\Feature;

use App\Services\ManagedKpiSettings;
use App\Models\Direction;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminKpiSettingsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_update_managed_kpis_and_reporting_reflects_them(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();
        $direction = Direction::query()->firstOrFail();
        $service = Service::query()->where('direction_id', $direction->id)->firstOrFail();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.kpis.edit'))
            ->assertOk()
            ->assertSee('KPI et statistiques');

        $payload = ['definitions' => []];
        foreach (app(ManagedKpiSettings::class)->all() as $code => $definition) {
            $formulaMode = match ($code) {
                'global' => 'weighted_average',
                'conformite' => 'gap_to_target',
                'qualite' => 'minimum',
                'progression' => 'maximum',
                'risque' => 'inverse',
                default => 'direct',
            };

            $payload['definitions'][$code] = [
                'code' => $code,
                'label' => strtoupper($code),
                'description' => 'Description '.$code,
                'weight' => $code === 'global' ? 30 : 10,
                'green_threshold' => 90,
                'orange_threshold' => 70,
                'source_metric' => match ($code) {
                    'delai' => 'performance',
                    'global' => 'global',
                    'conformite' => 'conformite',
                    'qualite' => 'qualite',
                    'progression' => 'progression',
                    'risque' => 'risque',
                    default => 'performance',
                },
                'formula_mode' => $formulaMode,
                'secondary_metric' => match ($code) {
                    'global' => 'performance',
                    'qualite' => 'progression',
                    'progression' => 'global',
                    default => '',
                },
                'tertiary_metric' => $code === 'global' ? 'delai' : '',
                'secondary_weight' => $code === 'global' ? 25 : 0,
                'tertiary_weight' => $code === 'global' ? 15 : 0,
                'target_value' => $code === 'conformite' ? 95 : '',
                'adjustment' => $code === 'global' ? 2 : 0,
                'visible' => '1',
                'target_profiles' => [],
                'target_direction_ids' => [$direction->id],
                'target_service_ids' => [$service->id],
            ];
        }

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.kpis.update'), $payload)
            ->assertRedirect(route('workspace.super-admin.kpis.edit'));

        $this->assertDatabaseHas('platform_settings', [
            'group' => 'managed_kpis',
            'key' => 'definitions',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'managed_kpis_update',
        ]);

        $settings = app(ManagedKpiSettings::class);
        $this->assertSame('GLOBAL', $settings->labels()['global']);
        $this->assertSame('performance', $settings->all()['delai']['source_metric']);
        $this->assertSame([$direction->id], $settings->all()['delai']['target_direction_ids']);
        $this->assertSame('weighted_average', $settings->all()['global']['formula_mode']);
        $this->assertSame('performance', $settings->all()['global']['secondary_metric']);

        $runtimeMetrics = $settings->buildRuntimeMetrics([
            'delai' => 75,
            'performance' => 88,
            'conformite' => 92,
            'qualite' => 81,
            'risque' => 18,
            'global' => 84,
            'progression' => 66,
        ], [
            'role' => $admin->effectiveRoleCode(),
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);

        $this->assertTrue(collect($runtimeMetrics)->contains(fn (array $metric): bool => $metric['label'] === 'GLOBAL'));
        $this->assertSame(85.65, collect($runtimeMetrics)->firstWhere('code', 'global')['value']);
        $this->assertSame(97.0, collect($runtimeMetrics)->firstWhere('code', 'conformite')['value']);
        $this->assertSame(66.0, collect($runtimeMetrics)->firstWhere('code', 'qualite')['value']);
        $this->assertSame(84.0, collect($runtimeMetrics)->firstWhere('code', 'progression')['value']);
        $this->assertSame(82.0, collect($runtimeMetrics)->firstWhere('code', 'risque')['value']);
        $this->assertSame('Cible 95', collect($runtimeMetrics)->firstWhere('code', 'conformite')['formula_summary']);
    }
}
