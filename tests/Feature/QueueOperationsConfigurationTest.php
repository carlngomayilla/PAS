<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class QueueOperationsConfigurationTest extends TestCase
{
    public function test_queue_operational_commands_are_registered_with_the_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('horizon:snapshot')
            ->expectsOutputToContain('queue:monitor redis:notifications,redis:exports,redis:ai-imports,redis:default --max=100')
            ->expectsOutputToContain('queue:prune-failed --hours=168')
            ->assertSuccessful();
    }

    public function test_queue_operations_have_guarded_single_server_schedules(): void
    {
        $schedule = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString(
            "\$usesRedisQueue = static fn (): bool => (string) config('queue.default') === 'redis';",
            $schedule,
        );
        $this->assertStringContainsString('class_exists(SnapshotCommand::class)', $schedule);
        $this->assertStringContainsString("Schedule::command('alertes:notifier --refresh-metrics')", $schedule);
        $this->assertStringContainsString("Schedule::command('meetings:send-reminders')", $schedule);
        $this->assertStringContainsString("Schedule::command('anbg:planning-auto-archive --execute')", $schedule);
        $this->assertStringContainsString("Schedule::command('anbg:retention-run --execute')", $schedule);
        $this->assertStringContainsString("Schedule::command('horizon:snapshot')", $schedule);
        $this->assertStringContainsString('->everyFiveMinutes()', $schedule);
        $this->assertStringContainsString('class_exists(MonitorCommand::class)', $schedule);
        $this->assertStringContainsString(
            'queue:monitor redis:notifications,redis:exports,redis:ai-imports,redis:default --max=100',
            $schedule,
        );
        $this->assertStringContainsString('->everyMinute()', $schedule);
        $this->assertStringContainsString('class_exists(PruneFailedJobsCommand::class)', $schedule);
        $this->assertStringContainsString('queue:prune-failed --hours=168', $schedule);
        $this->assertSame(7, substr_count($schedule, '->onOneServer()'));
        $this->assertSame(7, substr_count($schedule, '->withoutOverlapping('));
        $this->assertSame(2, substr_count($schedule, '->when($usesRedisQueue)'));
    }

    public function test_deployment_waits_for_running_horizon_with_a_bounded_timeout_and_keeps_the_worker_fallback(): void
    {
        $deployment = (string) file_get_contents(base_path('scripts/deploy.sh'));

        $this->assertStringContainsString('config:show queue.default --no-ansi', $deployment);
        $this->assertStringContainsString('horizon:status', $deployment);
        $this->assertStringContainsString('Horizon is running.', $deployment);
        $this->assertStringContainsString('Horizon is paused.', $deployment);
        $this->assertStringContainsString('php artisan horizon:terminate', $deployment);
        $this->assertStringContainsString('php artisan queue:restart', $deployment);
        $this->assertStringContainsString('HORIZON_RESTART_TIMEOUT="${HORIZON_RESTART_TIMEOUT:-60}"', $deployment);
        $this->assertStringContainsString('[ "$HORIZON_RESTART_TIMEOUT" -lt 1 ]', $deployment);
        $this->assertStringContainsString('[ "$HORIZON_RESTART_TIMEOUT" -gt 300 ]', $deployment);
        $this->assertStringContainsString('while [ "$HORIZON_WAITED" -lt "$HORIZON_RESTART_TIMEOUT" ]', $deployment);
        $this->assertStringContainsString('if [ "$HORIZON_RUNNING" != "1" ]', $deployment);
        $this->assertLessThan(
            strpos($deployment, 'php artisan queue:restart'),
            strpos($deployment, 'php artisan horizon:terminate'),
        );
    }

    public function test_ci_has_an_isolated_php_84_redis_horizon_job(): void
    {
        $workflowPath = base_path('.github/workflows/tests.yml');
        $workflow = (string) file_get_contents($workflowPath);
        $configuration = Yaml::parseFile($workflowPath);
        $job = $configuration['jobs']['redis-horizon'] ?? null;

        $this->assertIsArray($configuration);
        $this->assertIsArray($job);
        $this->assertSame('ubuntu-latest', $job['runs-on'] ?? null);
        $this->assertSame('redis:7-alpine', $job['services']['redis']['image'] ?? null);
        $this->assertSame('redis_failover', $job['env']['CACHE_STORE'] ?? null);
        $this->assertSame('database', $job['env']['SESSION_DRIVER'] ?? null);
        $this->assertStringContainsString('redis-horizon:', $workflow);
        $this->assertStringContainsString("php-version: '8.4'", $workflow);
        $this->assertStringContainsString('redis, pcntl, posix', $workflow);
        $this->assertStringContainsString('RUN_REDIS_HORIZON_TESTS:', $workflow);
        $this->assertStringContainsString('php artisan package:discover --ansi', $workflow);
        $this->assertStringContainsString('APP_ENV=local php artisan horizon', $workflow);
        $this->assertLessThan(
            strpos($workflow, 'php artisan horizon'),
            strpos($workflow, 'php artisan package:discover --ansi'),
        );
    }
}
