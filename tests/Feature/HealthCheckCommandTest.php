<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_command_succeeds_on_local_stack(): void
    {
        $this->artisan('anbg:health-check')
            ->expectsOutputToContain('Health check OK.')
            ->assertSuccessful();
    }

    public function test_health_check_command_can_render_json(): void
    {
        $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"ok": true', $output);
        $this->assertStringContainsString('"label": "Base de donnees"', $output);
    }

    public function test_health_check_fails_when_brevo_is_enabled_without_credentials(): void
    {
        config([
            'services.brevo.enabled' => true,
            'services.brevo.transport' => 'api',
            'services.brevo.api_key' => '',
        ]);

        $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $brevoCheck = collect($payload['checks'])->firstWhere('label', 'Brevo');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('fail', $brevoCheck['status']);
        $this->assertSame('transport api incomplet (api_key_missing)', $brevoCheck['details']);
    }

    public function test_health_check_accepts_configured_brevo_without_exposing_the_key(): void
    {
        config([
            'services.brevo.enabled' => true,
            'services.brevo.transport' => 'api',
            'services.brevo.api_key' => 'secret-api-key-must-not-escape',
        ]);

        $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $brevoCheck = collect($payload['checks'])->firstWhere('label', 'Brevo');

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $brevoCheck['status']);
        $this->assertSame('transport api configure', $brevoCheck['details']);
        $this->assertStringNotContainsString('secret-api-key', $output);
    }

    public function test_production_health_check_fails_when_default_brevo_mailer_has_no_smtp_credentials(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config([
            'queue.default' => 'sync',
            'mail.default' => 'brevo',
            'mail.mailers.brevo' => [
                'transport' => 'smtp',
                'host' => 'smtp-relay.brevo.com',
                'port' => 587,
                'username' => '',
                'password' => '',
            ],
            'services.brevo.enabled' => true,
            'services.brevo.transport' => 'api',
            'services.brevo.api_key' => 'configured-api-key',
        ]);

        $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $brevoCheck = collect($payload['checks'])->firstWhere('label', 'Brevo');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('fail', $brevoCheck['status']);
        $this->assertSame(
            'mailer par defaut brevo incomplet (smtp_credentials_missing)',
            $brevoCheck['details']
        );
    }

    public function test_production_health_check_accepts_configured_default_brevo_mailer_with_optional_channel_disabled(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config([
            'queue.default' => 'sync',
            'mail.default' => 'brevo',
            'mail.mailers.brevo' => [
                'transport' => 'smtp',
                'host' => 'smtp-relay.brevo.com',
                'port' => 587,
                'username' => 'configured-user',
                'password' => 'configured-password',
            ],
            'services.brevo.enabled' => false,
        ]);

        $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $brevoCheck = collect($payload['checks'])->firstWhere('label', 'Brevo');

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('ok', $brevoCheck['status']);
        $this->assertSame(
            'mailer par defaut brevo configure; canal complementaire desactive',
            $brevoCheck['details']
        );
    }

    public function test_health_check_command_pings_the_configured_redis_queue_connection(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'queue',
        ]);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('ping')
            ->andReturn('+PONG');

        Redis::shouldReceive('connection')
            ->once()
            ->with('queue')
            ->andReturn($connection);

        $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $queueCheck = collect($payload['checks'])->firstWhere('label', 'Queue');

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('ok', $queueCheck['status']);
        $this->assertSame('connexion redis queue: PING OK', $queueCheck['details']);
    }

    public function test_health_check_command_fails_without_exposing_a_redis_exception_message(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'queue',
        ]);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('ping')
            ->andThrow(new RuntimeException('redis-password=super-secret'));

        Redis::shouldReceive('connection')
            ->once()
            ->with('queue')
            ->andReturn($connection);

        $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $queueCheck = collect($payload['checks'])->firstWhere('label', 'Queue');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('fail', $queueCheck['status']);
        $this->assertStringContainsString('RuntimeException', $queueCheck['details']);
        $this->assertStringNotContainsString('super-secret', $output);
    }

    public function test_health_check_command_does_not_expose_database_exception_details(): void
    {
        config(['queue.default' => 'sync']);
        $defaultConnection = config('database.default');
        config(['database.default' => 'postgres-super-secret-database.internal']);

        try {
            $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
            $output = Artisan::output();
        } finally {
            config(['database.default' => $defaultConnection]);
        }
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $databaseCheck = collect($payload['checks'])->firstWhere('label', 'Base de donnees');

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $databaseCheck['status']);
        $this->assertStringContainsString('InvalidArgumentException', $databaseCheck['details']);
        $this->assertStringNotContainsString('super-secret', $output);
        $this->assertStringNotContainsString('database.internal', $output);
    }

    public function test_health_check_command_requires_running_horizon_in_production_with_redis(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'queue',
        ]);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('ping')
            ->andReturn('PONG');

        Redis::shouldReceive('connection')
            ->once()
            ->with('queue')
            ->andReturn($connection);

        $masterSupervisors = Mockery::mock(MasterSupervisorRepository::class);
        $masterSupervisors->shouldReceive('all')
            ->once()
            ->andReturn([(object) ['status' => 'running']]);
        $this->app->instance(MasterSupervisorRepository::class, $masterSupervisors);

        $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $horizonCheck = collect($payload['checks'])->firstWhere('label', 'Horizon');

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $horizonCheck['status']);
        $this->assertSame('1 superviseur(s) maitre(s) running', $horizonCheck['details']);
    }

    public function test_health_check_command_fails_when_horizon_is_paused_in_production_with_redis(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'queue',
        ]);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('ping')
            ->andReturn(true);

        Redis::shouldReceive('connection')
            ->once()
            ->with('queue')
            ->andReturn($connection);

        $masterSupervisors = Mockery::mock(MasterSupervisorRepository::class);
        $masterSupervisors->shouldReceive('all')
            ->once()
            ->andReturn([(object) ['status' => 'paused']]);
        $this->app->instance(MasterSupervisorRepository::class, $masterSupervisors);

        $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $horizonCheck = collect($payload['checks'])->firstWhere('label', 'Horizon');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('fail', $horizonCheck['status']);
    }

    public function test_health_check_command_checks_the_enabled_next_pilot_runtime(): void
    {
        config([
            'dashboard.next_pilot.enabled' => true,
            'dashboard.next_pilot.health_url' => 'http://127.0.0.1:3000/dashboard-pilot/health',
        ]);
        Http::fake([
            'http://127.0.0.1:3000/dashboard-pilot/health' => Http::response([
                'status' => 'ok',
            ]),
        ]);

        $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $nextCheck = collect($payload['checks'])->firstWhere('label', 'Dashboard Next');

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $nextCheck['status']);
        $this->assertSame('runtime Next joignable', $nextCheck['details']);
        Http::assertSentCount(1);
    }

    public function test_health_check_command_rejects_an_external_next_health_endpoint(): void
    {
        config([
            'dashboard.next_pilot.enabled' => true,
            'dashboard.next_pilot.health_url' => 'https://example.invalid/secret-health',
        ]);
        Http::fake();

        $externalExitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $externalPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $externalCheck = collect($externalPayload['checks'])->firstWhere('label', 'Dashboard Next');

        $this->assertSame(1, $externalExitCode);
        $this->assertSame('fail', $externalCheck['status']);
        $this->assertSame('URL de sante interne invalide', $externalCheck['details']);
        Http::assertNothingSent();
    }

    public function test_health_check_command_rejects_an_unhealthy_next_endpoint_without_exposing_its_body(): void
    {
        config([
            'dashboard.next_pilot.enabled' => true,
            'dashboard.next_pilot.health_url' => 'http://localhost:3000/dashboard-pilot/health',
        ]);
        Http::fake([
            'http://localhost:3000/dashboard-pilot/health' => Http::response(
                'upstream-secret-body',
                503,
            ),
        ]);

        $unhealthyExitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $unhealthyOutput = Artisan::output();
        $unhealthyPayload = json_decode($unhealthyOutput, true, flags: JSON_THROW_ON_ERROR);
        $unhealthyCheck = collect($unhealthyPayload['checks'])->firstWhere('label', 'Dashboard Next');

        $this->assertSame(1, $unhealthyExitCode);
        $this->assertSame('fail', $unhealthyCheck['status']);
        $this->assertSame('reponse de sante invalide (HTTP 503)', $unhealthyCheck['details']);
        $this->assertStringNotContainsString('upstream-secret-body', $unhealthyOutput);
    }
}
