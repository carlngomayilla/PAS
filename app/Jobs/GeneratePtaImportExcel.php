<?php

namespace App\Jobs;

use App\Models\AiImportBatch;
use App\Services\Ai\PtaExcelGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeneratePtaImportExcel implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AiImportBatch $batch
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PtaExcelGenerationService $excel): void
    {
        $excel->generate($this->batch);
    }
}
