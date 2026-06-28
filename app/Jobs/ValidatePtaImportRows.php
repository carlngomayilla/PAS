<?php

namespace App\Jobs;

use App\Models\AiImportBatch;
use App\Services\Ai\PtaImportValidationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ValidatePtaImportRows implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AiImportBatch $batch
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PtaImportValidationService $validation): void
    {
        $validation->validateBatch($this->batch);
    }
}
