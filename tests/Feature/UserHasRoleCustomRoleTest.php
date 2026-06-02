<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre A04 : hasRole() doit reconnaitre le custom_role_code en plus du
 * role de base, pour s aligner avec RolePermissionSettings::forUser() qui
 * resout les permissions a partir du role effectif.
 */
class UserHasRoleCustomRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_role_recognizes_base_role_when_no_custom_code(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'custom_role_code' => null,
        ]);

        $this->assertTrue($user->hasRole(User::ROLE_SERVICE));
        $this->assertFalse($user->hasRole(User::ROLE_DIRECTION));
        $this->assertFalse($user->hasRole(User::ROLE_PLANIFICATION));
    }

    public function test_has_role_recognizes_custom_role_pointing_to_system_role(): void
    {
        // Avant A04 : un agent avec custom_role_code=planification voyait ses
        // permissions resoulues comme planification mais les policies (qui
        // appelaient hasRole) le traitaient toujours en agent.
        $user = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'custom_role_code' => User::ROLE_PLANIFICATION,
        ]);

        // Le role de base reste reconnu (continuite metier).
        $this->assertTrue($user->hasRole(User::ROLE_AGENT));
        // Le role effectif est desormais reconnu aussi (correction A04).
        $this->assertTrue($user->hasRole(User::ROLE_PLANIFICATION));
        // Mais un role non sollicite reste rejete.
        $this->assertFalse($user->hasRole(User::ROLE_DG));
    }

    public function test_has_effective_role_returns_only_the_effective_code(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'custom_role_code' => User::ROLE_PLANIFICATION,
        ]);

        $this->assertTrue($user->hasEffectiveRole(User::ROLE_PLANIFICATION));
        $this->assertFalse($user->hasEffectiveRole(User::ROLE_AGENT));
    }

    public function test_is_agent_uses_base_role_only(): void
    {
        // Un agent reste un agent meme s il porte un custom_role_code superieur.
        $user = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'custom_role_code' => User::ROLE_PLANIFICATION,
        ]);

        $this->assertTrue($user->isAgent());
    }

    public function test_has_role_resolves_custom_role_base_role(): void
    {
        // Les rôles SYSTEMES (cf. RoleRegistryService::systemRoles) sont leur
        // propre base_role. Un user avec custom_role_code=chef_unite_sciq verra
        // donc reconnu hasRole(chef_unite_sciq) (cas du litteral) et
        // implicitement aussi hasRole(base_role) qui vaut chef_unite_sciq.
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'custom_role_code' => User::ROLE_CHEF_UNITE_SCIQ,
        ]);

        $this->assertTrue($user->hasRole(User::ROLE_SERVICE));
        $this->assertTrue($user->hasRole(User::ROLE_CHEF_UNITE_SCIQ));
    }

    public function test_unit_chief_roles_are_treated_as_service_scoped_profiles(): void
    {
        foreach ([User::ROLE_CHEF_UNITE_SCIQ, User::ROLE_CHEF_PLANIFICATION] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'custom_role_code' => null,
            ]);

            $this->assertTrue($user->hasRole(User::ROLE_SERVICE), 'Role '.$role);
            $this->assertTrue($user->isServiceOrUnitChief(), 'Role '.$role);
            $this->assertFalse($user->hasPermission('scope.global.read'), 'Role '.$role);
            $this->assertFalse($user->hasPermission('planning.write.global'), 'Role '.$role);
            $this->assertFalse($user->hasPermission('planning.strategic.manage'), 'Role '.$role);
        }
    }
}
