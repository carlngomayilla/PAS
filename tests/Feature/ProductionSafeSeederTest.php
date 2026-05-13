<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ProductionSafeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionSafeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_safe_seeder_populates_org_platform_and_legacy_completed_actions(): void
    {
        $this->seed(ProductionSafeSeeder::class);

        $this->assertSame(5, DB::table('directions')->where('actif', true)->count());
        $this->assertSame(19, DB::table('services')->count());
        $this->assertGreaterThanOrEqual(84, DB::table('users')->count());
        $this->assertEqualsCanonicalizing(
            ['DAF', 'DG', 'DIR021', 'DS', 'DSIC'],
            DB::table('directions')->where('actif', true)->pluck('code')->all()
        );
        $this->assertGreaterThan(0, DB::table('platform_settings')->count());
        $this->assertSame(2, DB::table('export_templates')->count());
        $this->assertSame(2, DB::table('pas')->count());
        $this->assertSame(8, DB::table('pas_axes')->count());
        $this->assertSame(18, DB::table('pas_objectifs')->count());
        $this->assertDatabaseHas('pas', [
            'titre' => 'PAS ANBG 2020-2025',
            'periode_debut' => 2020,
            'periode_fin' => 2025,
        ]);
        $this->assertDatabaseHas('pas_objectifs', [
            'libelle' => 'Nouer des partenariats pour favoriser l’insertion professionnelle des étudiants boursiers',
            'valeur_cible' => '2026-2028',
        ]);

        $this->assertGreaterThanOrEqual(9, DB::table('paos')->count());
        $this->assertDatabaseHas('paos', [
            'titre' => 'PAO DAF - validation financement 2026',
            'annee' => 2026,
            'statut' => 'soumis',
        ]);
        $this->assertGreaterThanOrEqual(9, DB::table('ptas')->count());
        $this->assertGreaterThanOrEqual(10, DB::table('actions')->count());
        $this->assertSame(3, DB::table('kpis')->count());
        $this->assertSame(3, DB::table('kpi_mesures')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('action_kpis')->count());
        $this->assertGreaterThanOrEqual(9, DB::table('objectifs_operationnels')->count());
        $this->assertSame(7, DB::table('delegations')->where('statut', 'active')->count());
        $this->assertGreaterThan(0, DB::table('sous_actions')->count());
        $this->assertSame(0, DB::table('pao_axes')->count());
        $this->assertSame(0, DB::table('pao_objectifs_strategiques')->count());
        $this->assertSame(0, DB::table('pao_objectifs_operationnels')->count());

        $legacyActions = DB::table('actions')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->join('paos', 'paos.id', '=', 'ptas.pao_id')
            ->join('pas', 'pas.id', '=', 'paos.pas_id')
            ->where('pas.periode_debut', 2020)
            ->where('pas.periode_fin', 2025)
            ->select('actions.statut', 'actions.statut_dynamique', 'actions.progression_reelle', 'actions.statut_validation')
            ->get();

        $this->assertCount(3, $legacyActions);
        $this->assertTrue($legacyActions->every(
            fn ($action): bool => $action->statut === 'termine'
                && in_array($action->statut_dynamique, ['acheve_dans_delai', 'acheve_hors_delai'], true)
                && (float) $action->progression_reelle === 100.0
                && $action->statut_validation === 'validee_direction'
        ));
        $this->assertPasCoversEveryActiveUser(2020, 2025);
        $this->assertPasCoversEveryActiveUser(2026, 2028);
    }

    public function test_production_safe_seeder_preserves_existing_passwords_for_known_users(): void
    {
        User::factory()->create([
            'name' => 'Legacy Super Admin',
            'email' => 'superadmin@anbg.ga',
            'password' => Hash::make('CustomPass@123'),
            'password_changed_at' => now(),
        ]);

        $this->seed(ProductionSafeSeeder::class);

        $user = User::query()
            ->where('email', 'superadmin@anbg.ga')
            ->firstOrFail();

        $this->assertTrue(Hash::check('CustomPass@123', $user->password));
        $this->assertSame(User::ROLE_SUPER_ADMIN, $user->role);
        $this->assertSame('Super Administrateur PAS', $user->name);
    }

    private function assertPasCoversEveryActiveUser(int $start, int $end): void
    {
        $pasId = DB::table('pas')
            ->where('periode_debut', $start)
            ->where('periode_fin', $end)
            ->value('id');

        $this->assertNotNull($pasId);

        $activeUsersQuery = DB::table('users')
            ->where('is_active', true);

        if (app()->environment('testing')) {
            $activeUsersQuery->whereIn('email', [
                'ingrid@anbg.ga',
                'loick.adan@anbg.ga',
                'hilaire.nguebet@anbg.ga',
                'directeur.daf@anbg.ga',
                'robert.ekomi@anbg.ga',
                'melissa.abogo@anbg.ga',
            ]);
        }

        $activeUserIds = $activeUsersQuery
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values();

        $actionIds = DB::table('actions')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->join('paos', 'paos.id', '=', 'ptas.pao_id')
            ->where('paos.pas_id', (int) $pasId)
            ->pluck('actions.id');

        $coveredUserIds = collect(DB::table('actions')
            ->whereIn('id', $actionIds)
            ->whereNotNull('responsable_id')
            ->pluck('responsable_id'))
            ->merge(DB::table('action_responsables')
                ->whereIn('action_id', $actionIds)
                ->pluck('user_id'))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $this->assertCount(0, $activeUserIds->diff($coveredUserIds));
    }
}
