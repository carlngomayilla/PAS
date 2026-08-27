<?php

namespace Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class RedisHorizonRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(getenv('RUN_REDIS_HORIZON_TESTS'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Le test d integration Redis/Horizon est reserve au job CI Linux dedie.');
        }
    }

    public function test_purpose_specific_redis_connections_are_reachable(): void
    {
        $this->assertSame('database', config('session.driver'));

        foreach (['cache', 'queue', 'horizon_meta'] as $connection) {
            $pong = Redis::connection($connection)->command('ping');

            $this->assertTrue(
                in_array($pong, [true, 'PONG', '+PONG'], true),
                sprintf('La connexion Redis [%s] ne repond pas.', $connection),
            );
        }
    }

    public function test_default_failover_cache_can_store_and_retrieve_a_value(): void
    {
        $cacheKey = 'ci-runtime-cache:'.Str::lower((string) Str::ulid());

        $this->assertSame('redis_failover', config('cache.default'));

        try {
            $this->assertTrue(Cache::put($cacheKey, 'redis-horizon-ready', 60));
            $this->assertSame('redis-horizon-ready', Cache::get($cacheKey));
        } finally {
            Cache::forget($cacheKey);
        }
    }

    public function test_redis_queue_can_be_monitored_and_consumed(): void
    {
        $queue = Queue::connection('redis');
        $queueName = 'ci-runtime-'.Str::lower((string) Str::ulid());

        try {
            $queue->push(new RedisHorizonRuntimeProbe, '', $queueName);

            $this->assertSame(1, $queue->size($queueName));

            $this->artisan('queue:monitor', [
                'queues' => 'redis:'.$queueName,
                '--max' => 100,
            ])->assertSuccessful();

            $job = $queue->pop($queueName);

            $this->assertNotNull($job);
            $job->delete();
            $this->assertSame(0, $queue->size($queueName));
        } finally {
            $queue->clear($queueName);
        }
    }

    public function test_horizon_can_write_a_metrics_snapshot_to_redis(): void
    {
        $this->artisan('horizon:snapshot')->assertSuccessful();
    }
}

final class RedisHorizonRuntimeProbe implements ShouldQueue
{
    public function handle(): void {}
}
