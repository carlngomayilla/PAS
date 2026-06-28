<?php

namespace App\Jobs;

use App\Models\AiGeneratedReport;
use App\Services\Ai\AiReportWritingService;
use App\Services\Ai\PtaReportDataBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeneratePtaAiReport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AiGeneratedReport $report
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PtaReportDataBuilder $builder, AiReportWritingService $writer): void
    {
        $metrics = $builder->build($this->report->filters ?? []);
        $this->report->forceFill([
            'metrics_snapshot' => $metrics,
            'ai_draft' => $writer->draft($this->report->title, $this->report->report_type, $metrics),
        ])->save();
    }
}
