<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UniteDg;
use App\Services\AccessScopeService;
use Database\Seeders\ProductionSafeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfficialOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_active_organization_matches_anbg_structure(): void
    {
        $this->seed(ProductionSafeSeeder::class);

        $structure = DB::table('directions')
            ->leftJoin('services', function ($join): void {
                $join->on('services.direction_id', '=', 'directions.id')
                    ->where('services.actif', true);
            })
            ->where('directions.actif', true)
            ->orderBy('directions.code')
            ->orderBy('services.code')
            ->get(['directions.code as direction_code', 'directions.libelle as direction_label', 'services.code as service_code'])
            ->groupBy('direction_code')
            ->map(fn ($rows) => $rows->pluck('service_code')->filter()->values()->all())
            ->all();

        $this->assertSame([
            'DAF' => ['AJARH', 'AMG', 'SFC'],
            'DG' => ['COLLAB', 'SCIQ', 'UCAS'],
            'DS' => ['EB', 'ENB', 'PLANIF'],
            'DSIC' => ['CRP', 'GDS', 'SIRS'],
        ], $structure);
    }

    public function test_planification_is_attached_to_ds_but_keeps_global_control_scope(): void
    {
        $this->seed(ProductionSafeSeeder::class);

        $user = User::query()
            ->with(['direction', 'service'])
            ->where('role', User::ROLE_PLANIFICATION)
            ->firstOrFail();

        $scope = app(AccessScopeService::class)->scopeFor($user);

        $this->assertSame('DS', $user->direction?->code);
        $this->assertSame('PLANIF', $user->service?->code);
        $this->assertSame(AccessScopeService::TYPE_GLOBAL, $scope['scope_type']);
        $this->assertTrue($scope['can_control_global']);
        $this->assertTrue($scope['has_dual_interface']);
    }

    public function test_sciq_collaborateur_and_ucas_interfaces_follow_business_rules(): void
    {
        $this->seed(ProductionSafeSeeder::class);

        $scopeService = app(AccessScopeService::class);

        $sciq = User::query()->with(['direction', 'service'])->where('email', 'kassirath.angue@anbg.ga')->firstOrFail();
        $collaborateur = User::query()->with(['direction', 'service'])->where('email', 'loick.adan@anbg.ga')->firstOrFail();
        $ucas = User::query()->with(['direction', 'service'])->where('email', 'ismene.lekosso@anbg.ga')->firstOrFail();

        $this->assertSame(User::ROLE_SCIQ, $sciq->role);
        $this->assertSame('DG', $sciq->direction?->code);
        $this->assertSame('SCIQ', $sciq->service?->code);
        $this->assertTrue($scopeService->scopeFor($sciq)['has_dual_interface']);
        $this->assertTrue($scopeService->scopeFor($sciq)['can_control_global']);

        $this->assertSame(User::ROLE_COLLABORATEUR, $collaborateur->role);
        $this->assertSame('COLLAB', $collaborateur->service?->code);
        $this->assertTrue($scopeService->scopeFor($collaborateur)['has_dual_interface']);
        $this->assertFalse($scopeService->scopeFor($collaborateur)['can_write_global']);

        $this->assertSame(User::ROLE_UCAS, $ucas->role);
        $this->assertSame('UCAS', $ucas->service?->code);
        $this->assertFalse($scopeService->scopeFor($ucas)['has_dual_interface']);
        $this->assertSame(AccessScopeService::TYPE_AGENT, $scopeService->scopeFor($ucas)['scope_type']);
    }

    public function test_dg_units_are_active_and_users_keep_service_unit_consistency(): void
    {
        $this->seed(ProductionSafeSeeder::class);

        $this->assertEqualsCanonicalizing(
            [UniteDg::CODE_CABINET, UniteDg::CODE_DGA, UniteDg::CODE_SCIQ, UniteDg::CODE_UCAS],
            UniteDg::query()->where('actif', true)->pluck('code')->all()
        );

        $expectedServiceByUnit = [
            UniteDg::CODE_CABINET => 'COLLAB',
            UniteDg::CODE_SCIQ => 'SCIQ',
            UniteDg::CODE_UCAS => 'UCAS',
        ];

        $users = User::query()
            ->with(['service', 'uniteDg'])
            ->whereIn('role', [
                User::ROLE_COLLABORATEUR,
                User::ROLE_CABINET,
                User::ROLE_CABINET_SUPERVISION,
                User::ROLE_CHEF_UNITE_CABINET,
                User::ROLE_SCIQ,
                User::ROLE_SCIQ_SUIVI_GLOBAL,
                User::ROLE_CHEF_UNITE_SCIQ,
                User::ROLE_UCAS,
                User::ROLE_CHEF_UNITE_UCAS,
            ])
            ->get();

        $this->assertNotEmpty($users);

        foreach ($users as $user) {
            $unitCode = $user->uniteDg?->code;
            $this->assertNotNull($unitCode, "Missing unite_dg_id for {$user->email}");
            $this->assertSame($expectedServiceByUnit[$unitCode], $user->service?->code);
        }
    }

    public function test_unit_scope_can_drive_dual_interface_without_service(): void
    {
        $this->seed(ProductionSafeSeeder::class);

        $dga = UniteDg::query()->where('code', UniteDg::CODE_DGA)->firstOrFail();
        $user = User::factory()->create([
            'role' => User::ROLE_DGA_SUPERVISION,
            'direction_id' => $dga->direction_id,
            'service_id' => null,
            'unite_dg_id' => $dga->id,
        ]);

        $this->assertTrue(app(AccessScopeService::class)->hasDualInterface($user));
    }
}
