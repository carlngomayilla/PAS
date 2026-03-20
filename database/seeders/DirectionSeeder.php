<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DirectionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AnbgOrganizationSeeder::class);
    }
}
