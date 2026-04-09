<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\RolePermissionSettings;
use Illuminate\Database\Seeder;

class RolePermissionSettingsSeeder extends Seeder
{
    public function run(RolePermissionSettings $settings): void
    {
        $actor = User::query()->where('email', 'superadmin@anbg.ga')->first();

        $settings->update($settings->defaults(), $actor);
    }
}
