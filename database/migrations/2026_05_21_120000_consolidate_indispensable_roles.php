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
        return ['DAF', 'DS', 'DSIC', 'DSIQ'];
    }
};
