<?php

namespace Tests\Feature;

use App\Listeners\LogBusyQueue;
use App\Listeners\LogCacheFailover;
use App\Listeners\LogFailedQueueJob;
use App\Listeners\LogTimedOutQueueJob;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class OperationalEventObservabilityTest extends TestCase
{
    public function test_operational_listeners_are_discovered_by_laravel(): void
    {
        Event::fake();

        Event::assertListening(CacheFailedOver::class, LogCacheFailover::class);
        Event::assertListening(JobFailed::class, LogFailedQueueJob::class);
        Event::assertListening(JobTimedOut::class, LogTimedOutQueueJob::class);
        Event::assertListening(QueueBusy::class, LogBusyQueue::class);
    }

    public function test_cache_failover_is_logged_without_the_exception_message(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('Cache failover activated.', [
                'event' => 'cache.failed_over',
                'store' => 'redis_failover',
                'exception_type' => RuntimeException::class,
            ]);

        (new LogCacheFailover($logger))->handle(new CacheFailedOver(
            'redis_failover',
            new RuntimeException('cache-password=super-secret')
        ));
    }

    public function test_failed_job_is_logged_without_payload_or_exception_message(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('Queue job failed.', [
                'event' => 'queue.job_failed',
                'connection' => 'redis',
                'queue' => 'exports',
                'job_id' => 'job-123',
                'attempts' => 3,
                'exception_type' => RuntimeException::class,
            ]);

        (new LogFailedQueueJob($logger))->handle(new JobFailed(
            'redis',
            $this->queueJob('exports', 'job-123', 3),
            new RuntimeException('payload-secret=super-secret')
        ));
    }

    public function test_timed_out_job_is_logged_with_safe_operational_metadata(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('Queue job timed out.', [
                'event' => 'queue.job_timed_out',
                'connection' => 'redis',
                'queue' => 'ai-imports',
                'job_id' => 'job-456',
                'attempts' => 2,
            ]);

        (new LogTimedOutQueueJob($logger))->handle(new JobTimedOut(
            'redis',
            $this->queueJob('ai-imports', 'job-456', 2)
        ));
    }

    public function test_busy_queue_is_logged_with_laravel_13_connection_name(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('Queue backlog threshold exceeded.', [
                'event' => 'queue.busy',
                'connection' => 'redis',
                'queue' => 'notifications',
                'size' => 250,
            ]);

        (new LogBusyQueue($logger))->handle(new QueueBusy(
            'redis',
            'notifications',
            250
        ));
    }

    private function queueJob(string $queue, string $jobId, int $attempts): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getQueue')->once()->andReturn($queue);
        $job->shouldReceive('getJobId')->once()->andReturn($jobId);
        $job->shouldReceive('attempts')->once()->andReturn($attempts);

        return $job;
    }
}
