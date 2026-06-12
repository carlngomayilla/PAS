<?php

namespace App\Jobs;

use App\Models\Action;
use App\Models\User;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyImportedParametreActionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    /**
     * @param list<int> $actionIds
     */
    public function __construct(
        public readonly array $actionIds,
        public readonly int $actorId
    ) {
        $this->onConnection('database');
        $this->onQueue('notifications');
    }

    public function handle(WorkspaceNotificationService $notifications): void
    {
        $actor = User::query()->find($this->actorId);
        if (! $actor instanceof User) {
            return;
        }

        $ids = collect($this->actionIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        Action::query()
            ->with('pta')
            ->whereKey($ids->all())
            ->orderBy('id')
            ->chunkById(50, function ($actions) use ($notifications, $actor): void {
                foreach ($actions as $action) {
                    if ($action instanceof Action) {
                        $notifications->notifyActionAssigned($action, $actor);
                    }
                }
            });
    }
}
