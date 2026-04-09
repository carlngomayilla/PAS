<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    public function test_web_and_api_responses_include_security_headers(): void
    {
        $this->get(route('login.form'))
            ->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');

        $admin = $this->createAdminUser([
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
        ]);

        $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-security-headers',
        ])
            ->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }

    public function test_api_login_rejects_inactive_user_and_sets_token_expiration_for_active_user(): void
    {
        $activeUser = $this->createAdminUser([
            'email' => 'active.admin@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
            'is_active' => true,
        ]);

        $inactiveUser = $this->createAdminUser([
            'email' => 'inactive.admin@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/login', [
            'email' => $inactiveUser->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-inactive-login',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Compte desactive.');

        $this->postJson('/api/v1/login', [
            'email' => $activeUser->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-active-login',
        ])->assertOk();

        /** @var PersonalAccessToken $token */
        $token = $activeUser->tokens()->latest('id')->firstOrFail();
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(now()->addMinutes(470), now()->addMinutes(490)));
    }

    public function test_inactive_accounts_are_blocked_by_web_and_api_middlewares(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive.user@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'role' => User::ROLE_SERVICE,
            'password_changed_at' => now(),
            'is_active' => false,
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'Pass@12345',
        ])
            ->assertSessionHasErrors('email');

        $plainTextToken = $user->createToken('phpunit-inactive-api', ['*'], now()->addHour())->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$plainTextToken)
            ->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'Compte desactive.');

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_suspended_accounts_are_blocked_by_web_and_api_layers(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended.user@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'role' => User::ROLE_SERVICE,
            'password_changed_at' => now(),
            'is_active' => true,
            'suspended_until' => now()->addDays(5),
            'suspension_reason' => 'Blocage de securite',
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'Pass@12345',
        ])
            ->assertSessionHasErrors('email');

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'Pass@12345',
            'device_name' => 'phpunit-suspended-login',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Compte temporairement suspendu.');

        $plainTextToken = $user->createToken('phpunit-suspended-api', ['*'], now()->addHour())->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$plainTextToken)
            ->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'Compte temporairement suspendu.');

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }
}
