<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RolePermissionSettings;
use Database\Seeders\ProductionSafeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_role_permissions_match_corrected_matrix(): void
    {
        $settings = app(RolePermissionSettings::class);

        $expected = [
            User::ROLE_SUPER_ADMIN => array_keys($settings->permissions()),
            User::ROLE_ADMIN_FONCTIONNEL => [
                'scope.global.read',
                'scope.global.write',
                'planning.read',
                'planning.write.global',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'referentiel.write',
                'users.manage',
                'users.manage_roles',
                'delegations.manage',
                'retention.read',
                'retention.manage',
                'api_docs.read',
                'audit.read',
                'messagerie.read',
            ],
            User::ROLE_AUDITEUR => [
                'scope.global.read',
                'planning.read',
                'reporting.read',
                'alerts.read',
                'audit.read',
                'messagerie.read',
            ],
            // Regle metier ANBG : le DG pilote l'agence — autorite ecriture/suppression
            // globale sur PAS / PAO / PTA / Actions sans restriction de perimetre.
            User::ROLE_DG => [
                'scope.global.read',
                'scope.global.write',
                'planning.read',
                'planning.write.global',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'audit.read',
                'messagerie.read',
            ],
            User::ROLE_PLANIFICATION => [
                'scope.global.read',
                'planning.read',
                'planning.write.global',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'referentiel.write',
                'users.manage',
                'users.manage_roles',
                'delegations.manage',
                'audit.read',
                'messagerie.read',
            ],
            User::ROLE_CABINET => [
                'scope.global.read',
                'planning.read',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'audit.read',
                'messagerie.read',
            ],
            User::ROLE_CHEF_UNITE_CABINET => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'messagerie.read',
            ],
            User::ROLE_CHEF_PLANIFICATION => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'messagerie.read',
            ],
            User::ROLE_CHEF_UNITE_SCIQ => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'messagerie.read',
            ],
            User::ROLE_CHEF_UNITE_DGA => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'messagerie.read',
            ],
            User::ROLE_DGA_SUPERVISION => [
                'scope.global.read',
                'planning.read',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'messagerie.read',
            ],
            User::ROLE_CHEF_UNITE_UCAS => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'messagerie.read',
            ],
            User::ROLE_UCAS => [
                'planning.read',
                'reporting.read',
                'alerts.read',
                'messagerie.read',
            ],
            User::ROLE_SCIQ => [
                'scope.global.read',
                'planning.read',
                'planning.write.global',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'referentiel.write',
                'delegations.manage',
                'messagerie.read',
            ],
            User::ROLE_DIRECTION => [
                'planning.read',
                'planning.write.direction',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'delegations.manage',
                'messagerie.read',
            ],
            User::ROLE_SERVICE => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'delegations.manage',
                'messagerie.read',
            ],
            User::ROLE_AGENT => [
                'planning.read',
                'reporting.read',
                'alerts.read',
                'messagerie.read',
            ],
        ];

        foreach ($expected as $role => $permissions) {
            $this->assertEqualsCanonicalizing($permissions, $settings->forRole($role), 'Permissions du role '.$role);
        }
    }

    public function test_workspace_modules_match_corrected_visibility_matrix_for_all_profiles(): void
    {
        $expected = [
            User::ROLE_SUPER_ADMIN => ['pilotage', 'super_admin', 'imports_excel', 'referentiel', 'roles_permissions', 'organisation', 'exercices', 'workflows', 'audit', 'retention', 'notifications'],
            User::ROLE_ADMIN_FONCTIONNEL => ['pilotage', 'super_admin', 'referentiel', 'roles_permissions', 'organisation', 'exercices', 'workflows', 'audit', 'retention', 'notifications'],
            User::ROLE_DG => ['pilotage', 'mes_taches', 'synthese_agence', 'arbitrages', 'deverrouillages', 'financements_critiques', 'rapports_consolides', 'alertes', 'notifications'],
            // Fusion modules 2026-05-28 : 'mes_actions' supprime — fusionne avec 'execution'
            // (label "Action") qui couvre les deux vues via les onglets de la page.
            User::ROLE_PLANIFICATION => ['pilotage', 'mes_taches', 'pas', 'pao', 'pta', 'imports_excel', 'execution', 'controle', 'reporting', 'notifications'],
            User::ROLE_SCIQ => ['pilotage', 'mes_taches', 'pas', 'pao', 'pta', 'imports_excel', 'execution', 'controle', 'reporting', 'notifications'],
            User::ROLE_CHEF_UNITE_SCIQ => ['pilotage', 'mes_taches', 'pta', 'execution', 'validations', 'agents', 'reporting', 'notifications'],
            User::ROLE_CHEF_PLANIFICATION => ['pilotage', 'mes_taches', 'pta', 'execution', 'validations', 'agents', 'reporting', 'notifications'],
            User::ROLE_CABINET => ['pilotage', 'mes_taches', 'synthese_agence', 'supervision', 'rapports_consolides', 'execution', 'alertes', 'notifications'],
            User::ROLE_CHEF_UNITE_CABINET => ['pilotage', 'mes_taches', 'pta', 'execution', 'validations', 'agents', 'reporting', 'notifications'],
            User::ROLE_DGA_SUPERVISION => ['pilotage', 'mes_taches', 'synthese_agence', 'supervision', 'rapports_consolides', 'execution', 'alertes', 'notifications'],
            User::ROLE_CHEF_UNITE_UCAS => ['pilotage', 'mes_taches', 'pta', 'execution', 'validations', 'agents', 'reporting', 'notifications'],
            User::ROLE_UCAS => ['pilotage', 'mes_taches', 'pta', 'execution', 'validations', 'agents', 'reporting', 'notifications'],
            User::ROLE_DIRECTION => ['pilotage', 'mes_taches', 'pao', 'pta', 'execution', 'services_agents', 'reporting', 'notifications'],
            User::ROLE_SERVICE => ['pilotage', 'mes_taches', 'pta', 'execution', 'validations', 'agents', 'reporting', 'notifications'],
            User::ROLE_AGENT => ['pilotage', 'execution', 'corrections', 'notifications'],
            User::ROLE_AUDITEUR => ['pilotage', 'execution', 'corrections', 'notifications'],
        ];

        foreach ($expected as $role => $modules) {
            $user = User::factory()->create([
                'role' => $role,
                'is_active' => true,
            ]);

            $this->assertEqualsCanonicalizing(
                $modules,
                collect($user->workspaceModules())->pluck('code')->all(),
                'Modules visibles du role '.$role
            );
        }
    }

    public function test_chef_planification_has_same_menu_as_chef_sciq(): void
    {
        $chefPlanification = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
        ]);
        $chefSciq = User::factory()->create([
            'role' => User::ROLE_CHEF_UNITE_SCIQ,
            'is_active' => true,
        ]);

        $this->assertSame(
            $chefSciq->workspaceModules(),
            $chefPlanification->workspaceModules(),
            'Le menu chef_planification doit rester identique au menu chef_unite_sciq.'
        );
    }

    public function test_seeded_agent_ossa_uses_agent_visibility_matrix(): void
    {
        $this->seed(ProductionSafeSeeder::class);

        $ossa = User::query()
            ->where('email', 'charles.ossa@anbg.ga')
            ->firstOrFail();

        $this->assertSame(User::ROLE_AGENT, $ossa->role);
        $this->assertTrue((bool) $ossa->is_agent);
        $this->assertEqualsCanonicalizing(
            // Fusion 2026-05-28 : mes_actions remplace par execution (label "Action").
            ['pilotage', 'execution', 'corrections', 'notifications'],
            collect($ossa->workspaceModules())->pluck('code')->all()
        );
    }

    public function test_production_seed_creates_functional_admin_account(): void
    {
        $this->seed(ProductionSafeSeeder::class);

        $expectedEmail = strtolower(trim((string) env('ADMIN_FONCTIONNEL_EMAIL', 'admin.fonctionnel@anbg.ga')));

        $admin = User::query()
            ->where('email', $expectedEmail)
            ->firstOrFail();

        $this->assertSame(User::ROLE_ADMIN_FONCTIONNEL, $admin->role);
        $this->assertTrue((bool) $admin->is_active);
        $this->assertFalse((bool) $admin->is_agent);
        $this->assertNull($admin->direction_id);
        $this->assertNull($admin->service_id);
        $this->assertEqualsCanonicalizing(
            ['pilotage', 'super_admin', 'referentiel', 'roles_permissions', 'organisation', 'exercices', 'workflows', 'audit', 'retention', 'notifications'],
            collect($admin->workspaceModules())->pluck('code')->all()
        );
    }
}
