<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

class RoleRegistrySeeder extends Seeder
{
    public function run(): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['group' => 'role_registry', 'key' => 'custom_roles'],
            ['value' => json_encode([], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
        );
    }
}
