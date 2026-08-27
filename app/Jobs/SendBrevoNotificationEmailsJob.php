<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notifications\BrevoMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendBrevoNotificationEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    /**
     * @param  list<int>  $recipientIds
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $event,
        public readonly array $recipientIds,
        public readonly array $payload
    ) {
        $connection = trim((string) config('services.brevo.queue.connection', ''));
        if ($connection !== '') {
            $this->onConnection($connection);
        }

        $queue = trim((string) config('services.brevo.queue.name', 'notifications'));
        if ($queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(
        BrevoMailService $brevoMailService,
        QueueFactory $queues
    ): void {
        $ids = collect($this->recipientIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        if ($ids->count() > 1) {
            $connection = trim((string) ($this->connection ?? $this->job?->getConnectionName()));
            $queue = trim((string) ($this->queue ?? $this->job?->getQueue()));
            $jobs = $ids
                ->map(fn (int $recipientId): self => $this->unitJob($recipientId))
                ->all();

            try {
                $queues
                    ->connection($connection !== '' ? $connection : null)
                    ->bulk($jobs, '', $queue !== '' ? $queue : null);
            } catch (Throwable $exception) {
                Log::warning('Legacy Brevo batch queue fan-out failed.', [
                    'event' => $this->event,
                    'recipient_count' => $ids->count(),
                    'exception_type' => get_debug_type($exception),
                ]);

                throw new RuntimeException('Legacy Brevo batch queue fan-out failed.');
            }

            return;
        }

        $users = User::query()
            ->whereKey((int) $ids->first())
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('suspended_until')
                    ->orWhere('suspended_until', '<=', now());
            })
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        $brevoMailService->deliverQueued($this->event, $users, $this->payload);
    }

    private function unitJob(int $recipientId): self
    {
        $job = new self($this->event, [$recipientId], $this->payload);
        $connection = trim((string) ($this->connection ?? $this->job?->getConnectionName()));
        $queue = trim((string) ($this->queue ?? $this->job?->getQueue()));

        if ($connection !== '') {
            $job->onConnection($connection);
        }

        if ($queue !== '') {
            $job->onQueue($queue);
        }

        return $job;
    }

    public function failed(?Throwable $exception): void
    {
        $recipientCount = collect($this->recipientIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->count();

        Log::error('Brevo notification email job failed.', [
            'event' => $this->event,
            'recipient_count' => $recipientCount,
            'exception_type' => get_debug_type($exception),
        ]);
    }
}
