<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_email(): void
    {
        $user = User::factory()->create([
            'email' => 'agent.test@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
            'role' => User::ROLE_AGENT,
            'agent_matricule' => 'A9-99',
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'Pass@12345',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_login_with_matricule(): void
    {
        $user = User::factory()->create([
            'email' => 'agent.matricule@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
            'role' => User::ROLE_AGENT,
            'agent_matricule' => 'B1-77',
        ]);

        $this->post(route('login'), [
            'email' => 'B1-77',
            'password' => 'Pass@12345',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_login_with_matricule_alias_in_anbg_ga_domain(): void
    {
        $user = User::factory()->create([
            'email' => 'c1-55@anbg.ga',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
            'role' => User::ROLE_AGENT,
            'agent_matricule' => null,
        ]);

        $this->post(route('login'), [
            'email' => 'C1-55',
            'password' => 'Pass@12345',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login'), [
                'email' => 'intrus@anbg.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('login'), [
            'email' => 'intrus@anbg.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_expired_password_user_is_redirected_to_profile_update_after_login(): void
    {
        $user = User::factory()->create([
            'email' => 'expired@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now()->subDays(120),
            'role' => User::ROLE_AGENT,
            'agent_matricule' => 'X1-01',
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'Pass@12345',
        ])->assertRedirect(route('workspace.profile.edit'));

        $this->assertAuthenticatedAs($user);
    }
}
