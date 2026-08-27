<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobTimedOut;
use Psr\Log\LoggerInterface;

class LogTimedOutQueueJob
{
    public function __construct(private LoggerInterface $logger) {}

    public function handle(JobTimedOut $event): void
    {
        $this->logger->error('Queue job timed out.', [
            'event' => 'queue.job_timed_out',
            'connection' => (string) $event->connectionName,
            'queue' => (string) $event->job->getQueue(),
            'job_id' => (string) $event->job->getJobId(),
            'attempts' => (int) $event->job->attempts(),
        ]);
    }
}
