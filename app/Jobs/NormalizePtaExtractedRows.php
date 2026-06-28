<?php

namespace App\Jobs;

use App\Models\AiImportBatch;
use App\Services\Ai\PtaNormalizationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NormalizePtaExtractedRows implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AiImportBatch $batch
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PtaNormalizationService $normalization): void
    {
        $normalization->normalize($this->batch);
    }
}
