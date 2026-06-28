<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'pta.control';

    /**
     * @var list<string>
     */
    private const ROLES = [
        User::ROLE_SUPER_ADMIN,
        User::ROLE_ADMIN,
        User::ROLE_ADMIN_FONCTIONNEL,
        User::ROLE_PLANIFICATION,
        User::ROLE_SCIQ,
        User::ROLE_SCIQ_SUIVI_GLOBAL,
        User::ROLE_CHEF_PLANIFICATION,
        User::ROLE_CHEF_UNITE_SCIQ,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        foreach (self::ROLES as $role) {
            $this->appendPermission($role);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        foreach (self::ROLES as $role) {
            $this->removePermission($role);
        }
    }

    private function appendPermission(string $role): void
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

        if (! in_array(self::PERMISSION, $permissions, true)) {
            $permissions[] = self::PERMISSION;
        }

        DB::table('platform_settings')
            ->where('group', 'role_permissions')
            ->where('key', $key)
            ->update([
                'value' => json_encode(array_values(array_unique($permissions)), JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function removePermission(string $role): void
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
                'value' => json_encode(array_values(array_diff($permissions, [self::PERMISSION])), JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
