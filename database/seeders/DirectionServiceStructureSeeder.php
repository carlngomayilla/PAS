<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DirectionServiceStructureSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AnbgOrganizationSeeder::class);
    }
}
