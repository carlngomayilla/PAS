<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $roleMap = [
        User::ROLE_ADMIN => User::ROLE_ADMIN_FONCTIONNEL,
        User::ROLE_CABINET => User::ROLE_DG,
        User::ROLE_CABINET_SUPERVISION => User::ROLE_DG,
        User::ROLE_CHEF_UNITE_CABINET => User::ROLE_DG,
        User::ROLE_COLLABORATEUR => User::ROLE_DG,
        User::ROLE_DGA_SUPERVISION => User::ROLE_DG,
        User::ROLE_CHEF_UNITE_DGA => User::ROLE_DG,
        User::ROLE_CHEF_UNITE => User::ROLE_DG,
        User::ROLE_CHEF_UNITE_UCAS => User::ROLE_DG,
        User::ROLE_UCAS => User::ROLE_DG,
        User::ROLE_SCIQ => User::ROLE_PLANIFICATION,
        User::ROLE_SCIQ_SUIVI_GLOBAL => User::ROLE_PLANIFICATION,
        User::ROLE_CHEF_UNITE_SCIQ => User::ROLE_PLANIFICATION,
        User::ROLE_INVITE_LECTURE => User::ROLE_AUDITEUR,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $hasCustomRoleCode = Schema::hasColumn('users', 'custom_role_code');

        foreach ($this->roleMap as $legacyRole => $targetRole) {
            $payload = [
                'role' => $targetRole,
                'service_id' => null,
                'unite_dg_id' => null,
                'is_agent' => $targetRole === User::ROLE_AGENT,
            ];

            if ($hasCustomRoleCode) {
                $payload['custom_role_code'] = null;
            }

            DB::table('users')
                ->where('role', $legacyRole)
                ->update($payload);

            if ($hasCustomRoleCode) {
                DB::table('users')
                    ->where('custom_role_code', $legacyRole)
                    ->update([
                        'custom_role_code' => null,
                    ]);
            }
        }

        $fallbackPayload = [
            'role' => User::ROLE_AUDITEUR,
            'service_id' => null,
            'unite_dg_id' => null,
            'is_agent' => false,
        ];

        if ($hasCustomRoleCode) {
            $fallbackPayload['custom_role_code'] = null;
        }

        DB::table('users')
            ->whereNotIn('role', $this->indispensableRoles())
            ->update($fallbackPayload);

        DB::table('users')
            ->whereIn('role', [User::ROLE_DIRECTION, User::ROLE_SERVICE, User::ROLE_AGENT])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('directions')
                    ->whereColumn('directions.id', 'users.direction_id')
                    ->whereNotIn('directions.code', $this->operationalDirectionCodes());
            })
            ->update([
                'role' => User::ROLE_DG,
                'service_id' => null,
                'unite_dg_id' => null,
                'is_agent' => false,
            ]);

        if (Schema::hasTable('platform_settings')) {
            DB::table('platform_settings')->where('group', 'role_permissions')
                ->whereNotIn('key', array_map(
                    static fn (string $role): string => 'role_permissions_'.$role,
                    $this->indispensableRoles()
                ))
                ->delete();

            foreach ($this->defaultPermissions() as $role => $permissions) {
                DB::table('platform_settings')->updateOrInsert(
                    ['group' => 'role_permissions', 'key' => 'role_permissions_'.$role],
                    ['value' => json_encode($permissions, JSON_UNESCAPED_SLASHES), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        // Consolidation volontairement non reversible : les anciens roles ont
        // ete fusionnes dans les roles indispensables.
    }

    /**
     * @return array<int, string>
     */
    private function indispensableRoles(): array
    {
        return [
            User::ROLE_SUPER_ADMIN,
            User::ROLE_ADMIN_FONCTIONNEL,
            User::ROLE_DG,
            User::ROLE_PLANIFICATION,
            User::ROLE_DIRECTION,
            User::ROLE_SERVICE,
            User::ROLE_AGENT,
            User::ROLE_AUDITEUR,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function operationalDirectionCodes(): array
    {
        return ['DAF', 'DSIC', 'DS'];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function defaultPermissions(): array
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
                'audit.read',
            ],
            User::ROLE_DG => ['scope.global.read', 'planning.read', 'reporting.read', 'alerts.read', 'audit.read'],
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
            ],
            User::ROLE_DIRECTION => ['planning.read', 'planning.write.direction', 'reporting.read', 'alerts.read'],
            User::ROLE_SERVICE => ['planning.read', 'planning.write.service', 'reporting.read', 'alerts.read'],
            User::ROLE_AGENT => ['reporting.read'],
            User::ROLE_AUDITEUR => ['planning.read', 'reporting.read', 'audit.read'],
        ];
    }
};
