<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

trait CreatesAdminUser
{
    protected function createSuperAdminUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Super Administrateur technique',
            'email' => 'super.admin@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
            'role' => User::ROLE_SUPER_ADMIN,
            'is_agent' => false,
            'direction_id' => null,
            'service_id' => null,
        ], $overrides));
    }

    protected function createAdminUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Administrateur technique',
            'email' => 'admin.technique@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
            'role' => User::ROLE_ADMIN,
            'is_agent' => false,
            'direction_id' => null,
            'service_id' => null,
        ], $overrides));
    }
}
