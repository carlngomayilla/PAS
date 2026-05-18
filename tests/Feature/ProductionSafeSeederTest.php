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

    public function test_production_safe_seeder_populates_org_and_platform_data_without_demo_pas(): void
    {
        $this->seed(ProductionSafeSeeder::class);

        // Structure organisationnelle attendue en prod.
        $this->assertSame(5, DB::table('directions')->where('actif', true)->count());
        $this->assertSame(19, DB::table('services')->count());
        $this->assertGreaterThanOrEqual(84, DB::table('users')->count());
        $this->assertEqualsCanonicalizing(
            ['DAF', 'DG', 'DIR021', 'DS', 'DSIC'],
            DB::table('directions')->where('actif', true)->pluck('code')->all()
        );

        // Paramètres plateforme et templates seedés en prod.
        $this->assertGreaterThan(0, DB::table('platform_settings')->count());
        $this->assertSame(2, DB::table('export_templates')->count());

        // Aucune donnée métier PAS/PAO/PTA/Action en prod : le client les crée via l'interface.
        $this->assertSame(0, DB::table('pas')->count());
        $this->assertSame(0, DB::table('pas_axes')->count());
        $this->assertSame(0, DB::table('pas_objectifs')->count());
        $this->assertSame(0, DB::table('paos')->count());
        $this->assertSame(0, DB::table('ptas')->count());
        $this->assertSame(0, DB::table('actions')->count());
        $this->assertSame(0, DB::table('sous_actions')->count());
        $this->assertSame(0, DB::table('objectifs_operationnels')->count());
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

}
