<?php

namespace App\Jobs;

use App\Models\AiImportSession;
use App\Services\AiImport\ExcelMappingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateImportExcelJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public int $sessionId
    ) {}

    public function handle(ExcelMappingService $excel): void
    {
        $excel->generate(AiImportSession::query()->findOrFail($this->sessionId));
    }

    public function failed(?Throwable $exception): void
    {
        AiImportSession::query()->whereKey($this->sessionId)->update([
            'status' => AiImportSession::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }
}
