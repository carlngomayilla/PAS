<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AnbgOrganizationSeeder::class,
            RefreshPlanningDemoSeeder::class,
            SuperAdminSeeder::class,
            PlatformSettingsSeeder::class,
            RoleRegistrySeeder::class,
            RolePermissionSettingsSeeder::class,
            DashboardProfileSettingsSeeder::class,
            DynamicReferentialSettingsSeeder::class,
            DocumentPolicySettingsSeeder::class,
            ManagedKpiSettingsSeeder::class,
            WorkflowSettingsSeeder::class,
            ActionCalculationSettingsSeeder::class,
            ActionManagementSettingsSeeder::class,
            NotificationPolicySettingsSeeder::class,
        ]);
    }
}
