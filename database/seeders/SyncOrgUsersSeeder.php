<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SyncOrgUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SyncOrgUsersPreservingPasswordsSeeder::class);
    }
}
