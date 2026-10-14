<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SyncOrgUsersPreservingPasswordsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

    public function test_seeded_anbg_user_can_login_with_default_password(): void
    {
        // En environnement de tests, les seeders conservent le mdp fixture
        // `Pass@12345` pour permettre aux fixtures de se logger. En production,
        // le seeder genere un mdp aleatoire + force renewal au 1er login (cf.
        // test_production_seeder_uses_random_password_and_forces_renewal).
        $this->seed(SyncOrgUsersPreservingPasswordsSeeder::class);

        $user = User::query()
            ->where('email', 'ingrid@anbg.ga')
            ->firstOrFail();

        $this->post(route('login'), [
            'email' => 'ingrid@anbg.ga',
            'password' => 'Pass@12345',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_production_seeder_uses_random_password_and_forces_renewal(): void
    {
        // A08 — En production le seeder NE DOIT PAS distribuer Pass@12345 mais
        // un mdp aleatoire + password_changed_at NULL pour forcer le changement.
        //
        // On bascule l environnement en "production" UNIQUEMENT autour de run()
        // (et on invoque le seeder en direct pour eviter le prompt de
        // confirmation que `$this->seed()` declenche en prod).
        $previousEnv = app()->environment();
        app()->detectEnvironment(fn () => 'production');

        try {
            (new SyncOrgUsersPreservingPasswordsSeeder)->setContainer(app())->run();
        } finally {
            app()->detectEnvironment(fn () => $previousEnv);
        }

        $user = User::query()
            ->where('email', 'ingrid@anbg.ga')
            ->firstOrFail();

        // password_changed_at NULL -> le user sera redirige vers profile/edit au 1er login.
        $this->assertNull(
            $user->password_changed_at,
            'Le seeder de production doit poser password_changed_at = NULL pour forcer le renouvellement.'
        );

        // Le mdp fixture Pass@12345 ne doit pas etre accepte.
        $this->assertFalse(
            Hash::check('Pass@12345', (string) $user->password),
            'Le seeder de production ne doit pas distribuer le mdp partage Pass@12345.'
        );
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
        $this->serverVariables = ['REMOTE_ADDR' => '203.0.113.'.random_int(1, 254)];
        $identifier = 'intrus-'.Str::lower(Str::random(16)).'@anbg.test';

        try {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->post(route('login'), [
                    'email' => $identifier,
                    'password' => 'wrong-password',
                ])->assertSessionHasErrors('email');
            }

            $this->post(route('login'), [
                'email' => $identifier,
                'password' => 'wrong-password',
            ])->assertStatus(429);
        } finally {
            $this->serverVariables = [];
        }
    }

    public function test_user_with_a_password_older_than_ninety_days_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'longstanding-password@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now()->subDays(120),
            'role' => User::ROLE_AGENT,
            'agent_matricule' => 'X1-01',
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'Pass@12345',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_with_a_temporary_password_is_redirected_to_the_profile_once(): void
    {
        $user = User::factory()->create([
            'email' => 'temporary-password@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => null,
            'role' => User::ROLE_AGENT,
            'agent_matricule' => 'X1-02',
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'Pass@12345',
        ])->assertRedirect(route('workspace.profile.edit'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_login_via_web_form(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
            'role' => User::ROLE_AGENT,
            'agent_matricule' => 'Z1-10',
            'is_active' => false,
        ]);

        $this->from(route('login.form'))
            ->post(route('login'), [
                'email' => $user->email,
                'password' => 'Pass@12345',
            ])
            ->assertRedirect(route('login.form'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_suspended_user_cannot_login_via_web_form(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@anbg.test',
            'password' => Hash::make('Pass@12345'),
            'password_changed_at' => now(),
            'role' => User::ROLE_AGENT,
            'agent_matricule' => 'S1-10',
            'is_active' => true,
            'suspended_until' => now()->addDays(3),
            'suspension_reason' => 'Controle interne',
        ]);

        $this->from(route('login.form'))
            ->post(route('login'), [
                'email' => $user->email,
                'password' => 'Pass@12345',
            ])
            ->assertRedirect(route('login.form'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
