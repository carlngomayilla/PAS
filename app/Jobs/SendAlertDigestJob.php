<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class SendAlertDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(
        private readonly int $limit = 20,
        private readonly bool $withoutDb = false,
        private readonly bool $refreshMetrics = false,
        private readonly bool $dryRun = false
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        Artisan::call('alertes:notifier', [
            '--limit' => $this->limit,
            '--without-db' => $this->withoutDb,
            '--refresh-metrics' => $this->refreshMetrics,
            '--dry-run' => $this->dryRun,
        ]);
    }
}