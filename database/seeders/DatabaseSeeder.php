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
            ProductionSafeSeeder::class,
            // Institutional PAS demo data — utilisé en dev/test uniquement,
            // exclu de ProductionSafeSeeder pour que la prod ne contienne pas de jeu de démonstration.
            InstitutionalPasSeeder::class,
        ]);
    }
}
