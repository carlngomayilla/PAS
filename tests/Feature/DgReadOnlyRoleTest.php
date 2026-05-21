<?php

namespace Tests\Feature;

use App\Models\Pao;
use App\Models\Pas;
use App\Models\User;
use App\Services\RolePermissionSettings;
use Database\Seeders\ProductionSafeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

/**
 * Couvre A06 :
 *   - le profil DG est en lecture seule PURE (aucune permission d ecriture
 *     ni de pilotage strategique) ;
 *   - les profils Cabinet/Collaborateur/Supervisions n ont plus
 *     `planning.strategic.manage` ;
 *   - les endpoints workflow PAS::approve/lock et PAO::approve/lock refusent
 *     un user DG (seuls SUPER_ADMIN/ADMIN valident).
 */
class DgReadOnlyRoleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductionSafeSeeder::class);
    }

    public function test_dg_role_has_read_only_permissions_only(): void
    {
        /** @var RolePermissionSettings $registry */
        $registry = app(RolePermissionSettings::class);
        $permissions = $registry->forRole(User::ROLE_DG);

        $this->assertContains('scope.global.read', $permissions);
        $this->assertContains('planning.read', $permissions);
        $this->assertContains('reporting.read', $permissions);
        $this->assertContains('audit.read', $permissions);

        $forbiddenWritePermissions = [
            'scope.global.write',
            'planning.write.global',
            'planning.write.direction',
            'planning.write.service',
            'planning.strategic.manage',
            'referentiel.write',
            'users.manage',
            'delegations.manage',
        ];
        foreach ($forbiddenWritePermissions as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $permissions,
                "DG ne doit PAS avoir la permission {$forbidden} (lecture seule pure)."
            );
        }
    }

    public function test_cabinet_and_supervisions_have_lost_strategic_manage(): void
    {
        /** @var RolePermissionSettings $registry */
        $registry = app(RolePermissionSettings::class);

        $rolesThatLostStrategic = [
            User::ROLE_CABINET,
            User::ROLE_COLLABORATEUR,
            User::ROLE_CABINET_SUPERVISION,
            User::ROLE_DGA_SUPERVISION,
        ];

        foreach ($rolesThatLostStrategic as $role) {
            $this->assertNotContains(
                'planning.strategic.manage',
                $registry->forRole($role),
                "Le role {$role} ne doit plus avoir planning.strategic.manage."
            );
        }
    }

    public function test_dg_cannot_approve_pas(): void
    {
        $dg = User::factory()->create([
            'role' => User::ROLE_DG,
            'is_active' => true,
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->forceCreate([
            'titre' => 'PAS 2026-2030',
            'periode_debut' => 2026,
            'periode_fin' => 2030,
            'statut' => 'soumis',
            'created_by' => $dg->id,
            'valide_le' => null,
            'valide_par' => null,
        ]);

        $this->actingAs($dg)
            ->from(route('workspace.pas.index'))
            ->post(route('workspace.pas.approve', $pas))
            ->assertForbidden();

        $this->assertSame('soumis', $pas->fresh()->statut, 'Le PAS ne doit pas avoir ete approuve par le DG.');
    }

    public function test_dg_cannot_approve_pao(): void
    {
        $dg = User::factory()->create([
            'role' => User::ROLE_DG,
            'is_active' => true,
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->forceCreate([
            'titre' => 'PAS',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'verrouille',
            'created_by' => $dg->id,
        ]);

        $pao = Pao::query()->forceCreate([
            'pas_id' => $pas->id,
            'direction_id' => 1,
            'annee' => 2026,
            'titre' => 'PAO test',
            'statut' => 'soumis',
        ]);

        $this->actingAs($dg)
            ->from(route('workspace.pao.index'))
            ->post(route('workspace.pao.approve', $pao))
            ->assertForbidden();

        $this->assertSame('soumis', $pao->fresh()->statut, 'Le PAO ne doit pas avoir ete approuve par le DG.');
    }

    public function test_admin_can_still_approve_pas(): void
    {
        $admin = $this->createAdminUser();

        $pas = Pas::query()->forceCreate([
            'titre' => 'PAS',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'soumis',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->from(route('workspace.pas.index'))
            ->post(route('workspace.pas.approve', $pas))
            ->assertRedirect();

        $this->assertSame('valide', $pas->fresh()->statut);
    }
}
