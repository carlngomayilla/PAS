<?php

namespace App\Jobs;

use App\Models\AiImportBatch;
use App\Services\Ai\PtaExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractPtaFileWithAi implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AiImportBatch $batch
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PtaExtractionService $extraction): void
    {
        $extraction->extract($this->batch);
    }
}
