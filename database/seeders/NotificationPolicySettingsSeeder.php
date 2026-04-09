<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\NotificationPolicySettings;
use Illuminate\Database\Seeder;

class NotificationPolicySettingsSeeder extends Seeder
{
    public function run(): void
    {
        $actorId = User::query()
            ->where('email', 'superadmin@anbg.ga')
            ->value('id');

        foreach (app(NotificationPolicySettings::class)->defaults() as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'notification_policy', 'key' => $key],
                [
                    'value' => is_array($value)
                        ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : $value,
                    'updated_by' => $actorId,
                ]
            );
        }

        app(NotificationPolicySettings::class)->flush();
    }
}
