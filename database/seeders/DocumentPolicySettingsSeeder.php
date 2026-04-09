<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use Illuminate\Database\Seeder;

class DocumentPolicySettingsSeeder extends Seeder
{
    public function run(): void
    {
        $actorId = User::query()
            ->where('email', 'superadmin@anbg.ga')
            ->value('id');

        foreach (app(DocumentPolicySettings::class)->defaults() as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'document_policy', 'key' => $key],
                [
                    'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'updated_by' => $actorId,
                ]
            );
        }
    }
}
