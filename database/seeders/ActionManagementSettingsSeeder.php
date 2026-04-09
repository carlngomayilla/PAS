<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use App\Services\ActionManagementSettings;
use Illuminate\Database\Seeder;

class ActionManagementSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (app(ActionManagementSettings::class)->defaults() as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'action_management', 'key' => $key],
                ['value' => $value]
            );
        }
    }
}
