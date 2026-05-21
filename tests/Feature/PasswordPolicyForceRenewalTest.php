<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre A08 : un user dont `password_changed_at` est NULL (compte cree par
 * seeder, import admin, reset sans distribution) doit etre force a renouveler
 * son mot de passe au prochain login.
 */
class PasswordPolicyForceRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_marked_expired_when_never_changed(): void
    {
        /** @var PasswordPolicyService $policy */
        $policy = app(PasswordPolicyService::class);

        $user = User::factory()->create([
            'password_changed_at' => null,
            'created_at' => now()->subDay(),
        ]);

        $this->assertTrue($policy->isExpired($user));
    }

    public function test_password_is_not_expired_when_recently_changed(): void
    {
        /** @var PasswordPolicyService $policy */
        $policy = app(PasswordPolicyService::class);

        $user = User::factory()->create([
            'password_changed_at' => now(),
        ]);

        $this->assertFalse($policy->isExpired($user));
    }

    public function test_password_is_expired_when_older_than_threshold(): void
    {
        /** @var PasswordPolicyService $policy */
        $policy = app(PasswordPolicyService::class);

        $threshold = (int) config('security.passwords.expire_days', 90);

        $user = User::factory()->create([
            'password_changed_at' => now()->subDays($threshold + 1),
        ]);

        $this->assertTrue($policy->isExpired($user));
    }

    public function test_generate_initial_password_is_long_enough_and_includes_all_classes(): void
    {
        /** @var PasswordPolicyService $policy */
        $policy = app(PasswordPolicyService::class);

        $minLength = (int) config('security.passwords.min_length', 12);

        for ($i = 0; $i < 25; $i++) {
            $password = $policy->generateInitialPassword();

            $this->assertGreaterThanOrEqual($minLength, strlen($password));
            $this->assertMatchesRegularExpression('/[A-Za-z]/', $password);
            $this->assertMatchesRegularExpression('/\d/', $password);
            $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $password);
        }
    }
}
