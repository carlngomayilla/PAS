<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RolePermissionSettings;
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
            User::ROLE_DG => [
                'scope.global.read',
                'planning.read',
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
                'messagerie.read',
            ],
            User::ROLE_SERVICE => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
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
}
