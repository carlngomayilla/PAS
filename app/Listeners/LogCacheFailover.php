<?php

namespace App\Listeners;

use Illuminate\Cache\Events\CacheFailedOver;
use Psr\Log\LoggerInterface;

class LogCacheFailover
{
    public function __construct(private LoggerInterface $logger) {}

    public function handle(CacheFailedOver $event): void
    {
        $this->logger->warning('Cache failover activated.', [
            'event' => 'cache.failed_over',
            'store' => $event->storeName,
            'exception_type' => $event->exception::class,
        ]);
    }
}
