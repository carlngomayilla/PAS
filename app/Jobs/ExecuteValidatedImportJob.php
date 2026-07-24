<?php

namespace App\Jobs;

use App\Models\AiImportSession;
use App\Models\User;
use App\Services\AiImport\ImportExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteValidatedImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public int $sessionId,
        public ?int $userId = null
    ) {}

    public function handle(ImportExecutionService $execution): void
    {
        $execution->execute(
            AiImportSession::query()->findOrFail($this->sessionId),
            $this->userId !== null ? User::query()->find($this->userId) : null
        );
    }

    public function failed(?Throwable $exception): void
    {
        AiImportSession::query()->whereKey($this->sessionId)->update([
            'status' => AiImportSession::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }
}
