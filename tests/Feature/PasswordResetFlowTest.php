<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_reset_forgotten_password_end_to_end(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset.flow@anbg.test',
            'password' => Hash::make('Password-Old@123'),
            'password_changed_at' => now(),
            'is_active' => true,
        ]);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Mot de passe', false);

        $this->from(route('password.request'))
            ->post(route('password.email'), [
                'email' => $user->email,
            ])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return is_string($token) && $token !== '';
            }
        );

        $this->assertNotNull($token);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Nouveau mot de passe')
            ->assertSee('Voir');

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Password-New@123',
            'password_confirmation' => 'Password-New@123',
        ])
            ->assertRedirect(route('login.form'))
            ->assertSessionHas('status');

        $user->refresh();

        $this->assertTrue(Hash::check('Password-New@123', (string) $user->password));
        $this->assertNotNull($user->password_changed_at);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'Password-New@123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_reset_password_cannot_reuse_current_password(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset.reuse@anbg.test',
            'password' => Hash::make('Password-Old@123'),
            'password_changed_at' => now(),
            'is_active' => true,
        ]);

        $this->post(route('password.email'), [
            'email' => $user->email,
        ]);

        $token = null;
        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            }
        );

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Password-Old@123',
            'password_confirmation' => 'Password-Old@123',
        ])
            ->assertSessionHasErrors('password');

        $user->refresh();
        $this->assertTrue(Hash::check('Password-Old@123', (string) $user->password));
    }
}
