<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const IMPORT_PERMISSIONS = [
        'ai_pta_import.view',
        'ai_pta_import.upload',
        'ai_pta_import.analyze',
        'ai_pta_import.preview',
        'ai_pta_import.correct',
        'ai_pta_import.validate',
        'ai_pta_import.import',
        'ai_pta_import.export',
        'ai_pta_import.history',
    ];

    /**
     * @var list<string>
     */
    private const REPORT_PERMISSIONS = [
        'ai_reports.view',
        'ai_reports.generate',
        'ai_reports.edit',
        'ai_reports.validate',
        'ai_reports.export',
        'ai_reports.archive',
    ];

    /**
     * @var list<string>
     */
    private const FULL_ROLES = [
        User::ROLE_SUPER_ADMIN,
        User::ROLE_ADMIN,
        User::ROLE_ADMIN_FONCTIONNEL,
        User::ROLE_PLANIFICATION,
        User::ROLE_SCIQ,
        User::ROLE_SCIQ_SUIVI_GLOBAL,
        User::ROLE_CHEF_PLANIFICATION,
        User::ROLE_CHEF_UNITE_SCIQ,
    ];

    /**
     * @var list<string>
     */
    private const OPERATIONAL_ROLES = [
        User::ROLE_DIRECTION,
        User::ROLE_SERVICE,
        User::ROLE_CHEF_UNITE,
        User::ROLE_CHEF_UNITE_DGA,
        User::ROLE_CHEF_UNITE_CABINET,
        User::ROLE_CHEF_UNITE_UCAS,
    ];

    /**
     * @var list<string>
     */
    private const REPORT_ONLY_ROLES = [
        User::ROLE_DG,
        User::ROLE_CABINET,
        User::ROLE_DGA_SUPERVISION,
        User::ROLE_CABINET_SUPERVISION,
        User::ROLE_UCAS,
    ];

    /**
     * @var list<string>
     */
    private const REPORT_READ_ROLES = [
        User::ROLE_AUDITEUR,
        User::ROLE_AGENT,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        foreach (self::FULL_ROLES as $role) {
            $this->appendPermissions($role, array_merge(self::IMPORT_PERMISSIONS, self::REPORT_PERMISSIONS));
        }

        foreach (self::OPERATIONAL_ROLES as $role) {
            $this->appendPermissions($role, array_merge(self::IMPORT_PERMISSIONS, [
                'ai_reports.view',
                'ai_reports.generate',
                'ai_reports.edit',
                'ai_reports.export',
            ]));
        }

        foreach (self::REPORT_ONLY_ROLES as $role) {
            $this->appendPermissions($role, [
                'ai_reports.view',
                'ai_reports.generate',
                'ai_reports.export',
            ]);
        }

        foreach (self::REPORT_READ_ROLES as $role) {
            $this->appendPermissions($role, ['ai_reports.view']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        $permissions = array_merge(self::IMPORT_PERMISSIONS, self::REPORT_PERMISSIONS);
        foreach (array_unique(array_merge(self::FULL_ROLES, self::OPERATIONAL_ROLES, self::REPORT_ONLY_ROLES, self::REPORT_READ_ROLES)) as $role) {
            $this->removePermissions($role, $permissions);
        }
    }

    /**
     * @param  list<string>  $permissionsToAdd
     */
    private function appendPermissions(string $role, array $permissionsToAdd): void
    {
        $key = 'role_permissions_'.$role;
        $value = DB::table('platform_settings')
            ->where('group', 'role_permissions')
            ->where('key', $key)
            ->value('value');

        if ($value === null) {
            return;
        }

        $permissions = json_decode((string) $value, true);
        if (! is_array($permissions)) {
            return;
        }

        DB::table('platform_settings')
            ->where('group', 'role_permissions')
            ->where('key', $key)
            ->update([
                'value' => json_encode(array_values(array_unique(array_merge($permissions, $permissionsToAdd))), JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<string>  $permissionsToRemove
     */
    private function removePermissions(string $role, array $permissionsToRemove): void
    {
        $key = 'role_permissions_'.$role;
        $value = DB::table('platform_settings')
            ->where('group', 'role_permissions')
            ->where('key', $key)
            ->value('value');

        if ($value === null) {
            return;
        }

        $permissions = json_decode((string) $value, true);
        if (! is_array($permissions)) {
            return;
        }

        DB::table('platform_settings')
            ->where('group', 'role_permissions')
            ->where('key', $key)
            ->update([
                'value' => json_encode(array_values(array_diff($permissions, $permissionsToRemove)), JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
