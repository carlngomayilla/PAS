<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ProductionSafeSeeder;
use Database\Seeders\SyncOrgUsersPreservingPasswordsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionSafeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_safe_seeder_populates_only_org_and_platform_without_pas_demo_data(): void
    {
        $this->seed(ProductionSafeSeeder::class);

        $this->assertSame(4, DB::table('directions')->where('actif', true)->count());
        $this->assertSame(12, DB::table('services')->where('actif', true)->count());
        $this->assertSame(83, DB::table('users')->count());
        $this->assertEqualsCanonicalizing(
            ['DAF', 'DG', 'DS', 'DSIC'],
            DB::table('directions')->where('actif', true)->pluck('code')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['UCAS', 'SCIQ', 'COLLAB'],
            DB::table('services')
                ->join('directions', 'directions.id', '=', 'services.direction_id')
                ->where('directions.code', 'DG')
                ->where('services.actif', true)
                ->pluck('services.code')
                ->all()
        );
        $this->assertDatabaseHas('users', ['email' => 'claude.azizet@anbg.ga']);
        $this->assertDatabaseHas('users', ['email' => 'belinda.magnangani@anbg.ga']);

        $this->assertGreaterThan(0, DB::table('platform_settings')->count());
        $this->assertSame(2, DB::table('export_templates')->count());

        $this->assertSame(0, DB::table('pas')->count());
        $this->assertSame(0, DB::table('pas_axes')->count());
        $this->assertSame(0, DB::table('pas_objectifs')->count());
        $this->assertSame(0, DB::table('paos')->count());
        $this->assertSame(0, DB::table('ptas')->count());
        $this->assertSame(0, DB::table('objectifs_operationnels')->count());
        $this->assertSame(0, DB::table('actions')->whereNull('deleted_at')->count());
        $this->assertSame(0, DB::table('action_kpis')->count());
        $this->assertSame(0, DB::table('pao_axes')->count());
        $this->assertSame(0, DB::table('pao_objectifs_strategiques')->count());
        $this->assertSame(0, DB::table('pao_objectifs_operationnels')->count());
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

    public function test_sync_org_seeder_deletes_inactive_users_after_reassigning_actions_to_active_service_user(): void
    {
        $this->seed(ProductionSafeSeeder::class);

        $serviceUser = User::query()
            ->where('is_active', true)
            ->whereNotNull('service_id')
            ->orderBy('id')
            ->firstOrFail();

        $inactiveUser = User::factory()->create([
            'name' => 'Ancien agent service',
            'email' => 'ancien.agent.service@anbg.ga',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'is_active' => true,
            'direction_id' => $serviceUser->direction_id,
            'service_id' => $serviceUser->service_id,
            'agent_matricule' => 'OLD-001',
            'agent_fonction' => 'Ancien agent',
        ]);

        $replacementUserId = (int) DB::table('users')
            ->where('id', '!=', (int) $inactiveUser->id)
            ->where('service_id', (int) $inactiveUser->service_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderByRaw(
                "CASE WHEN role = ? THEN 0 WHEN role = ? THEN 1 ELSE 2 END",
                [User::ROLE_AGENT, User::ROLE_SERVICE]
            )
            ->orderBy('id')
            ->value('id');

        $pta = $this->createPtaForServiceUser($serviceUser);

        $actionId = DB::table('actions')->insertGetId([
            'pta_id' => (int) $pta->id,
            'pao_id' => (int) $pta->pao_id,
            'objectif_operationnel_id' => $pta->objectif_operationnel_id !== null ? (int) $pta->objectif_operationnel_id : null,
            'responsable_id' => (int) $inactiveUser->id,
            'libelle' => 'Action compte inactif a transferer',
            'description' => 'Action de test pour transfert avant suppression.',
            'date_debut' => now()->startOfYear()->toDateString(),
            'date_fin' => now()->endOfYear()->toDateString(),
            'date_echeance' => now()->endOfYear()->toDateString(),
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('action_responsables')->insert([
            'action_id' => $actionId,
            'user_id' => (int) $inactiveUser->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subActionId = DB::table('sous_actions')->insertGetId([
            'action_id' => $actionId,
            'agent_id' => (int) $inactiveUser->id,
            'libelle' => 'Sous-action compte inactif',
            'date_debut' => now()->startOfYear()->toDateString(),
            'date_fin' => now()->endOfYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seed(SyncOrgUsersPreservingPasswordsSeeder::class);

        $deletedUser = User::withTrashed()->findOrFail((int) $inactiveUser->id);
        $this->assertTrue($deletedUser->trashed());
        $this->assertFalse((bool) $deletedUser->is_active);

        $this->assertDatabaseHas('actions', [
            'id' => $actionId,
            'responsable_id' => $replacementUserId,
        ]);
        $this->assertDatabaseMissing('action_responsables', [
            'action_id' => $actionId,
            'user_id' => (int) $inactiveUser->id,
        ]);
        $this->assertDatabaseHas('action_responsables', [
            'action_id' => $actionId,
            'user_id' => $replacementUserId,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('sous_actions', [
            'id' => $subActionId,
            'agent_id' => $replacementUserId,
        ]);
    }

    private function createPtaForServiceUser(User $serviceUser): object
    {
        $now = now();

        $pasId = DB::table('pas')->insertGetId([
            'titre' => 'PAS test transfert inactif',
            'periode_debut' => (int) $now->year,
            'periode_fin' => (int) $now->year,
            'statut' => 'actif',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $paoId = DB::table('paos')->insertGetId([
            'pas_id' => $pasId,
            'direction_id' => (int) $serviceUser->direction_id,
            'service_id' => null,
            'annee' => (int) $now->year,
            'titre' => 'PAO test transfert inactif',
            'statut' => 'valide',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $ptaId = DB::table('ptas')->insertGetId([
            'pao_id' => $paoId,
            'direction_id' => (int) $serviceUser->direction_id,
            'service_id' => (int) $serviceUser->service_id,
            'titre' => 'PTA test transfert inactif',
            'statut' => 'en_cours',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('ptas')->where('id', $ptaId)->first();
    }
}
