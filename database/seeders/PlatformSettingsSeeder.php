<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Database\Seeder;

class PlatformSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $actorId = User::query()
            ->where('email', 'superadmin@anbg.ga')
            ->value('id');

        foreach (app(PlatformSettings::class)->defaults() as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'general', 'key' => $key],
                ['value' => $value, 'updated_by' => $actorId]
            );
        }

        app(PlatformSettings::class)->flush();
    }
}
