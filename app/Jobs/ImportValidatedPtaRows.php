<?php

namespace App\Jobs;

use App\Models\AiImportBatch;
use App\Models\User;
use App\Services\Ai\PtaFinalImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportValidatedPtaRows implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AiImportBatch $batch,
        public ?User $user = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PtaFinalImportService $import): void
    {
        $import->import($this->batch, $this->user);
    }
}
