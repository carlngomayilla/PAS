<?php

namespace App\Listeners;

use Illuminate\Queue\Events\QueueBusy;
use Psr\Log\LoggerInterface;

class LogBusyQueue
{
    public function __construct(private LoggerInterface $logger) {}

    public function handle(QueueBusy $event): void
    {
        $this->logger->warning('Queue backlog threshold exceeded.', [
            'event' => 'queue.busy',
            'connection' => (string) $event->connectionName,
            'queue' => (string) $event->queue,
            'size' => (int) $event->size,
        ]);
    }
}
