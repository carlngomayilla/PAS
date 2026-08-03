<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use App\Services\ActionCalculationSettings;
use Illuminate\Database\Seeder;

class ActionCalculationSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(ActionCalculationSettings::class);

        foreach ($settings->defaults() as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'action_calculation', 'key' => $key],
                ['value' => $value]
            );
        }
    }
}
