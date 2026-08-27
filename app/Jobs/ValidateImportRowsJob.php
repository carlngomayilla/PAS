<?php

namespace App\Jobs;

use App\Models\AiImportSession;
use App\Services\AiImport\ExcelMappingService;
use App\Services\AiImport\ImportValidationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ValidateImportRowsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public int $sessionId
    ) {
        $this->onQueue((string) config('ai_training.pta.import_queue', 'ai-imports'));
    }

    public function handle(ImportValidationService $validation, ExcelMappingService $excel): void
    {
        $session = AiImportSession::query()->findOrFail($this->sessionId);
        $validation->validateSession($session);
        $excel->generate($session->refresh());
    }

    public function failed(?Throwable $exception): void
    {
        AiImportSession::query()->whereKey($this->sessionId)->update([
            'status' => AiImportSession::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }
}
