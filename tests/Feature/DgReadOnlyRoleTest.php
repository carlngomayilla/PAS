<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RolePermissionSettings;
use Database\Seeders\ProductionSafeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

/**
 * Couvre la regle metier ANBG (2026-05-28) :
 *   - le profil DG est en PILOTAGE COMPLET (ecriture + suppression cascade
 *     sur PAS / PAO / PTA / Actions, scope global) ;
 *   - les profils Cabinet/Collaborateur/Supervisions n ont plus
 *     `planning.strategic.manage` ;
 *   - les anciens endpoints workflow PAS/PAO sont neutralises et ne mutent
 *     plus les statuts metier (les transitions passent par les routes
 *     close/archive/destroy).
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

    public function test_dg_role_has_full_pilotage_permissions(): void
    {
        /** @var RolePermissionSettings $registry */
        $registry = app(RolePermissionSettings::class);
        $permissions = $registry->forRole(User::ROLE_DG);

        // Lectures inchangees.
        $this->assertContains('scope.global.read', $permissions);
        $this->assertContains('planning.read', $permissions);
        $this->assertContains('reporting.read', $permissions);
        $this->assertContains('audit.read', $permissions);

        // Nouveaux droits d'ecriture (regle metier 2026-05-28).
        $expectedWritePermissions = [
            'scope.global.write',
            'planning.write.global',
            'planning.strategic.manage',
        ];
        foreach ($expectedWritePermissions as $required) {
            $this->assertContains(
                $required,
                $permissions,
                "DG doit avoir la permission {$required} (pilotage complet de l'agence)."
            );
        }

        // Permissions deliberement non accordees au DG (reservees aux
        // administrateurs fonctionnels / SCIQ).
        $reservedAdminPermissions = [
            'planning.write.direction',
            'planning.write.service',
            'referentiel.write',
            'users.manage',
            'delegations.manage',
        ];
        foreach ($reservedAdminPermissions as $reserved) {
            $this->assertNotContains(
                $reserved,
                $permissions,
                "DG ne doit pas avoir {$reserved} (reserve aux admins fonctionnels)."
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

    // Note (2026-05-29) : tests legacy 'approve route does not mutate' SUPPRIMES —
    // les routes workspace.pas.approve / workspace.pao.approve / workspace.pta.approve
    // (ainsi que submit/lock/reopen) ont ete entierement retirees de routes/web.php.
    // Plus de no-op stubs a tester.
}
