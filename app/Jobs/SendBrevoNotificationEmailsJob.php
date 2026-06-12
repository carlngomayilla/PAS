<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notifications\BrevoMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBrevoNotificationEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    /**
     * @param list<int> $recipientIds
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $event,
        public readonly array $recipientIds,
        public readonly array $payload
    ) {
        $this->onConnection('database');
        $this->onQueue('notifications');
    }

    public function handle(BrevoMailService $brevoMailService): void
    {
        $ids = collect($this->recipientIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        User::query()
            ->whereKey($ids->all())
            ->orderBy('id')
            ->chunkById(50, function ($users) use ($brevoMailService): void {
                $brevoMailService->dispatch($this->event, $users, $this->payload);
            });
    }
}
