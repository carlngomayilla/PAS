<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        $now = now();

        DB::table('users')->updateOrInsert(
            ['email' => 'admin.technique@anbg.test'],
            [
                'name' => 'Administrateur technique',
                'password' => Hash::make('Pass@12345'),
                'role' => User::ROLE_ADMIN,
                'is_agent' => false,
                'direction_id' => null,
                'service_id' => null,
                'email_verified_at' => $now,
                'password_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
}
