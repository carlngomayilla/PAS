<?php

namespace Database\Seeders;

use App\Services\DashboardProfileSettings;
use Illuminate\Database\Seeder;

class DashboardProfileSettingsSeeder extends Seeder
{
    public function run(): void
    {
        app(DashboardProfileSettings::class)->update([], null);
    }
}
