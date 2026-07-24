<?php

namespace App\Jobs;

use App\Models\AiImportSession;
use App\Services\AiImport\DocumentExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExtractDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public int $sessionId
    ) {}

    public function handle(DocumentExtractionService $documents): void
    {
        $session = AiImportSession::query()->findOrFail($this->sessionId);
        $documents->extract($session);
        AnalyzePasPaoPtaWithAiJob::dispatch($session->id)->onQueue((string) config('ai_training.pta.import_queue', 'ai-imports'));
    }

    public function failed(?Throwable $exception): void
    {
        AiImportSession::query()->whereKey($this->sessionId)->update([
            'status' => AiImportSession::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }
}
