<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\DynamicReferentialSettings;
use Illuminate\Database\Seeder;

class DynamicReferentialSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $actorId = User::query()
            ->where('email', 'superadmin@anbg.ga')
            ->value('id');

        foreach (app(DynamicReferentialSettings::class)->defaults() as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'dynamic_referentials', 'key' => $key],
                [
                    'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'updated_by' => $actorId,
                ]
            );
        }

        app(DynamicReferentialSettings::class)->flush();
    }
}
