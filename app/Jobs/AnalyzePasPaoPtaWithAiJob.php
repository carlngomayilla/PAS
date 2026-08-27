<?php

namespace App\Jobs;

use App\Models\AiImportSession;
use App\Services\AiImport\DocumentExtractionService;
use App\Services\AiImport\PasPaoPtaAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzePasPaoPtaWithAiJob implements ShouldQueue
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
    ) {
        $this->onQueue((string) config('ai_training.pta.import_queue', 'ai-imports'));
    }

    public function handle(DocumentExtractionService $documents, PasPaoPtaAnalysisService $analysis): void
    {
        $session = AiImportSession::query()->with('user')->findOrFail($this->sessionId);
        $extraction = $documents->extract($session);
        $analysis->analyzeAndPersist($session, $extraction, $session->user);
        ValidateImportRowsJob::dispatch($session->id);
    }

    public function failed(?Throwable $exception): void
    {
        AiImportSession::query()->whereKey($this->sessionId)->update([
            'status' => AiImportSession::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }
}
