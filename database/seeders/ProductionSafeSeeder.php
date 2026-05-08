<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductionSafeSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed only production-safe organizational and platform data.
     */
    public function run(): void
    {
        $this->call([
            SyncOrgUsersPreservingPasswordsSeeder::class,
            InstitutionalPasSeeder::class,
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
