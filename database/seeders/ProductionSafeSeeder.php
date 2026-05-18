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
            // InstitutionalPasSeeder retiré du seeder production : le PAS doit être créé par le client via l'interface.
            // Lancer manuellement avec `php artisan db:seed --class=InstitutionalPasSeeder` en local/demo si besoin.
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
