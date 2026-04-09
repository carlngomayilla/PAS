<?php

namespace Database\Seeders;

use App\Services\ManagedKpiSettings;
use Illuminate\Database\Seeder;

class ManagedKpiSettingsSeeder extends Seeder
{
    public function run(): void
    {
        app(ManagedKpiSettings::class)->update([
            'definitions' => array_values(app(ManagedKpiSettings::class)->defaults()),
        ]);
    }
}
