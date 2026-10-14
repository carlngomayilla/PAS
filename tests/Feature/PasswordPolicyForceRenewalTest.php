<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Couvre A08 : un user dont `password_changed_at` est NULL (compte cree par
 * seeder, import admin, reset sans distribution) doit etre force a renouveler
 * son mot de passe au prochain login.
 */
class PasswordPolicyForceRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_password_requires_a_change_before_the_first_login(): void
    {
        /** @var PasswordPolicyService $policy */
        $policy = app(PasswordPolicyService::class);

        $user = User::factory()->create([
            'password_changed_at' => null,
            'created_at' => now()->subDay(),
        ]);

        $this->assertTrue($policy->requiresPasswordChange($user));
    }

    public function test_password_change_is_not_required_after_a_personal_password_is_set(): void
    {
        /** @var PasswordPolicyService $policy */
        $policy = app(PasswordPolicyService::class);

        $user = User::factory()->create([
            'password_changed_at' => now(),
        ]);

        $this->assertFalse($policy->requiresPasswordChange($user));
    }

    public function test_password_change_is_not_required_after_ninety_days(): void
    {
        /** @var PasswordPolicyService $policy */
        $policy = app(PasswordPolicyService::class);

        $user = User::factory()->create([
            'password_changed_at' => now()->subDays(91),
        ]);

        $this->assertFalse($policy->requiresPasswordChange($user));
    }

    public function test_medium_password_policy_accepts_letters_and_numbers_without_symbols(): void
    {
        /** @var PasswordPolicyService $policy */
        $policy = app(PasswordPolicyService::class);

        $validator = Validator::make([
            'password' => 'pilotage2026',
        ], [
            'password' => ['required', 'string', $policy->rule()],
        ]);

        $this->assertFalse($validator->fails(), (string) $validator->errors()->first('password'));
    }

    public function test_password_policy_never_allows_less_than_eight_characters(): void
    {
        config(['security.passwords.min_length' => 4]);

        /** @var PasswordPolicyService $policy */
        $policy = app(PasswordPolicyService::class);

        $shortPasswordValidator = Validator::make([
            'password' => 'abc1234',
        ], [
            'password' => ['required', 'string', $policy->rule()],
        ]);

        $validPasswordValidator = Validator::make([
            'password' => 'abc12345',
        ], [
            'password' => ['required', 'string', $policy->rule()],
        ]);

        $this->assertTrue($shortPasswordValidator->fails());
        $this->assertFalse($validPasswordValidator->fails(), (string) $validPasswordValidator->errors()->first('password'));
    }

    public function test_generate_initial_password_is_long_enough_and_matches_medium_policy(): void
    {
        /** @var PasswordPolicyService $policy */
        $policy = app(PasswordPolicyService::class);

        $minLength = (int) config('security.passwords.min_length', 8);

        for ($i = 0; $i < 25; $i++) {
            $password = $policy->generateInitialPassword();

            $this->assertGreaterThanOrEqual($minLength, strlen($password));
            $this->assertMatchesRegularExpression('/[A-Za-z]/', $password);
            $this->assertMatchesRegularExpression('/\d/', $password);
        }
    }
}
