<?php

namespace App\Jobs;

use App\Models\AiGeneratedReport;
use App\Services\Ai\ReportExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExportAiReportToExcel implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AiGeneratedReport $report
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ReportExportService $exports): void
    {
        $exports->excel($this->report);
    }
}
