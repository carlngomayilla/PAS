<?php

namespace App\Jobs;

use App\Models\AiImportBatch;
use App\Services\Ai\PtaImportAnalysisService;
use App\Services\Ai\PtaImportAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AnalyzePtaImportBatch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1260;

    public int $uniqueFor = 1800;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(
        public AiImportBatch $batch
    ) {
        $this->onQueue((string) config('ai_training.pta.import_queue', 'ai-imports'));
        $this->timeout = max(120, (int) config('ai_training.pta.import_job_timeout', 1200)) + 60;
        $this->uniqueFor = $this->timeout + 600;
    }

    public function handle(PtaImportAnalysisService $analysis, PtaImportAuditService $audit): void
    {
        $batch = $this->batch->refresh();
        $result = $analysis->analyze($batch);

        $audit->record('analyze', $batch->refresh(), $batch->user, null, null, [
            'stats' => $result['stats'],
            'completion' => $result['completion'],
            'hierarchy' => $result['hierarchy'],
            'repair' => $result['repair'],
            'warning' => $result['warning'],
            'excel' => $result['excel'],
        ]);
    }

    public function uniqueId(): string
    {
        return 'ai-pta-import-'.$this->batch->id;
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception?->getMessage() ?: 'Analyse IA PTA interrompue.';

        $this->batch->refresh()->forceFill([
            'status' => AiImportBatch::STATUS_FAILED,
            'error_message' => $message,
        ])->save();
    }
}
