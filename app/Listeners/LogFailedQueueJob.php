<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Psr\Log\LoggerInterface;

class LogFailedQueueJob
{
    public function __construct(private LoggerInterface $logger) {}

    public function handle(JobFailed $event): void
    {
        $this->logger->error('Queue job failed.', [
            'event' => 'queue.job_failed',
            'connection' => (string) $event->connectionName,
            'queue' => (string) $event->job->getQueue(),
            'job_id' => (string) $event->job->getJobId(),
            'attempts' => (int) $event->job->attempts(),
            'exception_type' => $event->exception::class,
        ]);
    }
}
