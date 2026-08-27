<?php

namespace Tests\Feature;

use App\Jobs\NotifyImportedParametreActionsJob;
use App\Jobs\SendBrevoNotificationEmailsJob;
use Tests\TestCase;

class RedisInfrastructureConfigurationTest extends TestCase
{
    public function test_redis_connections_are_isolated_by_workload(): void
    {
        $this->assertSame('cache', config('cache.stores.redis.connection'));
        $this->assertSame('cache', config('cache.stores.redis.lock_connection'));
        $this->assertSame('queue', config('queue.connections.redis.connection'));
        $this->assertSame(['redis', 'database'], config('cache.stores.redis_failover.stores'));
        $this->assertSame('array', config('cache.version_store'));
        $this->assertNotSame('redis_failover', config('cache.version_store'));
        $this->assertSame('database', config('cache.limiter'));
        $this->assertFalse(config('cache.serializable_classes'));

        foreach (['cache', 'sessions', 'queue', 'horizon_meta'] as $connection) {
            $this->assertIsArray(config('database.redis.'.$connection));
            $this->assertNotSame('', (string) config('database.redis.'.$connection.'.prefix'));
        }
    }

    public function test_redis_queue_timeouts_prevent_duplicate_long_running_jobs(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $this->assertGreaterThanOrEqual(1500, $retryAfter);
        $this->assertSame(5, config('queue.connections.redis.block_for'));
        $this->assertTrue(config('queue.connections.redis.after_commit'));

        foreach (config('queue.workloads', []) as $workload => $profile) {
            $jobTimeout = (int) ($profile['job_timeout'] ?? 0);
            $supervisorTimeout = (int) ($profile['supervisor_timeout'] ?? 0);

            $this->assertGreaterThan(0, $jobTimeout, $workload.' doit déclarer un timeout de job.');
            $this->assertGreaterThan($jobTimeout, $supervisorTimeout, $workload.' doit laisser le superviseur terminer le job.');
            $this->assertGreaterThan($supervisorTimeout, $retryAfter, $workload.' doit libérer le job après le timeout superviseur.');
        }
    }

    public function test_production_example_requires_redis_and_horizon_but_keeps_database_sessions(): void
    {
        $environment = (string) file_get_contents(base_path('.env.production.example'));

        $this->assertStringContainsString('CACHE_STORE=redis_failover', $environment);
        $this->assertStringContainsString('CACHE_VERSION_STORE=database', $environment);
        $this->assertStringContainsString('CACHE_LIMITER_STORE=redis', $environment);
        $this->assertStringContainsString('SESSION_DRIVER=database', $environment);
        $this->assertStringContainsString('QUEUE_CONNECTION=redis', $environment);
        $this->assertStringContainsString('REDIS_QUEUE_RETRY_AFTER=1500', $environment);
        $this->assertStringContainsString('HORIZON_NAME=ANBG-PAS', $environment);
        $this->assertStringContainsString('SESSION_ENCRYPT=true', $environment);
    }

    public function test_alert_cache_uses_only_the_canonical_version_store(): void
    {
        $alertCenterSource = (string) file_get_contents(app_path('Services/Alerting/AlertCenterService.php'));
        $justificatifSource = (string) file_get_contents(app_path('Models/Justificatif.php'));
        $providerSource = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringNotContainsString('alert-center:version', $alertCenterSource);
        $this->assertStringNotContainsString('alert-center:version', $justificatifSource);
        $this->assertStringContainsString('AnalyticsCacheVersionService', $alertCenterSource);
        $this->assertStringNotContainsString('app(AnalyticsCacheVersionService::class)', $justificatifSource);
        $this->assertStringContainsString(
            'Justificatif::observe(PlanningCacheObserver::class)',
            $providerSource
        );
    }

    public function test_notification_jobs_inherit_the_application_connection_and_use_the_notification_queue(): void
    {
        config()->set('services.brevo.queue.connection', '');
        config()->set('services.brevo.queue.name', 'notifications');

        $importNotification = new NotifyImportedParametreActionsJob([1], 1);
        $brevoNotification = new SendBrevoNotificationEmailsJob('action_assigned', [1], []);

        $this->assertNull($importNotification->connection);
        $this->assertSame('notifications', $importNotification->queue);
        $this->assertSame([10, 60], $importNotification->backoff);
        $this->assertNull($brevoNotification->connection);
        $this->assertSame('notifications', $brevoNotification->queue);
        $this->assertSame([10, 60], $brevoNotification->backoff);
    }
}
