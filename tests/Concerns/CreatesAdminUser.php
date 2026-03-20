<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

trait CreatesAdminUser
{
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
