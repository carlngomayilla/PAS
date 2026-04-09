<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\WorkflowSettings;
use Illuminate\Database\Seeder;

class WorkflowSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $actorId = User::query()
            ->where('email', 'superadmin@anbg.ga')
            ->value('id');

        foreach (app(WorkflowSettings::class)->defaults() as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'workflow', 'key' => $key],
                ['value' => $value, 'updated_by' => $actorId]
            );
        }

        app(WorkflowSettings::class)->flush();
    }
}
