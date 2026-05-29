<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        foreach ($this->matrix() as $role => $permissions) {
            DB::table('platform_settings')->updateOrInsert(
                ['group' => 'role_permissions', 'key' => 'role_permissions_'.$role],
                [
                    'value' => json_encode(array_values($permissions), JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // Intentional forward-only correction of the default RBAC matrix.
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function matrix(): array
    {
        $all = [
            'scope.global.read',
            'scope.global.write',
            'planning.read',
            'planning.write.global',
            'planning.write.direction',
            'planning.write.service',
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
        ];

        return [
            User::ROLE_SUPER_ADMIN => $all,
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
    }
};
