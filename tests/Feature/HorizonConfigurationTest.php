<?php

namespace Tests\Feature;

use App\Http\Middleware\ConfigureHorizonCspNonce;
use App\Http\Middleware\EnsurePasswordIsFresh;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_supervisors_respect_queue_timeout_invariants(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $workloads = (array) config('queue.workloads');

        foreach ($workloads as $queue => $workload) {
            $supervisor = (array) config('horizon.defaults.supervisor-'.$queue);

            $this->assertSame('redis', $supervisor['connection'] ?? null);
            $this->assertSame([$queue], $supervisor['queue'] ?? null);
            $this->assertGreaterThan((int) $workload['job_timeout'], (int) ($supervisor['timeout'] ?? 0));
            $this->assertLessThan($retryAfter, (int) ($supervisor['timeout'] ?? PHP_INT_MAX));
        }
    }

    public function test_horizon_uses_fast_termination_without_cutting_jobs_longer_than_five_minutes(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $this->assertTrue(config('horizon.fast_termination'));

        foreach ((array) config('queue.workloads') as $queue => $workload) {
            $jobTimeout = (int) ($workload['job_timeout'] ?? 0);
            $supervisorTimeout = (int) config('horizon.defaults.supervisor-'.$queue.'.timeout');

            $this->assertGreaterThanOrEqual(300, $jobTimeout);
            $this->assertGreaterThan($jobTimeout, $supervisorTimeout);
            $this->assertLessThan($retryAfter, $supervisorTimeout);
        }
    }

    public function test_horizon_uses_isolated_metadata_and_all_expected_queues(): void
    {
        $this->assertSame('horizon_meta', config('horizon.use'));
        $this->assertSame(
            ['redis:notifications', 'redis:exports', 'redis:ai-imports', 'redis:default'],
            array_keys((array) config('horizon.waits'))
        );
        $this->assertContains(
            ConfigureHorizonCspNonce::class,
            (array) config('horizon.middleware')
        );
        $this->assertContains(
            EnsurePasswordIsFresh::class,
            (array) config('horizon.middleware')
        );
    }

    public function test_only_active_unsuspended_super_administrators_can_view_horizon(): void
    {
        $allowed = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
            'suspended_until' => null,
        ]);
        $inactive = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => false,
        ]);
        $suspended = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
            'suspended_until' => now()->addHour(),
        ]);
        $otherRole = User::factory()->create([
            'role' => User::ROLE_DG,
            'is_active' => true,
        ]);

        $this->assertTrue(Gate::forUser($allowed)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($inactive)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($suspended)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($otherRole)->allows('viewHorizon'));
    }

    public function test_horizon_dashboard_is_forbidden_to_guests(): void
    {
        $this->get('/horizon')->assertForbidden();
    }

    public function test_horizon_dashboard_redirects_a_super_administrator_with_an_expired_password(): void
    {
        config(['security.passwords.expire_days' => 90]);

        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
            'suspended_until' => null,
            'password_changed_at' => now()->subDays(91),
        ]);

        $this->actingAs($superAdmin)
            ->get('/horizon')
            ->assertRedirect(route('workspace.profile.edit'))
            ->assertSessionHasErrors('password');
    }

    public function test_horizon_dashboard_assets_receive_the_application_csp_nonce(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
            'suspended_until' => null,
        ]);

        $response = $this->actingAs($superAdmin)->get('/horizon');

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy');
        $response->assertSee('script type="module" nonce="', false);
    }
}
