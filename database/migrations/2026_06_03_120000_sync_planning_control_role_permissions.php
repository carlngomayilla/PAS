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

        $permissions = [
            'scope.global.read',
            'planning.read',
            'planning.write.global',
            'planning.write.service',
            'planning.strategic.manage',
            'reporting.read',
            'alerts.read',
            'referentiel.read',
            'users.manage',
            'users.manage_roles',
        ];

        foreach ([User::ROLE_CHEF_PLANIFICATION, User::ROLE_CHEF_UNITE_SCIQ] as $role) {
            DB::table('platform_settings')->updateOrInsert(
                ['group' => 'role_permissions', 'key' => 'role_permissions_'.$role],
                [
                    'value' => json_encode($permissions, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // Forward-only production sync: do not restore stale permission overrides.
    }
};
