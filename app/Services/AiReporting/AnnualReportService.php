<?php

namespace App\Services\AiReporting;

use App\Models\AiReport;
use App\Models\AiReportRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnnualReportService
{
    public function __construct(
        private readonly ReportDataAggregatorService $aggregator,
        private readonly AiReportNarrativeService $narrative
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     */
    public function generate(?User $user, int $year, array $filters = []): AiReport
    {
        $start = Carbon::create($year, 1, 1)->startOfYear();
        $end = $start->copy()->endOfYear();

        return DB::transaction(function () use ($user, $start, $end, $filters): AiReport {
            $request = AiReportRequest::query()->create([
                'user_id' => $user?->id,
                'report_type' => AiReportRequest::TYPE_ANNUAL,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'direction_id' => $filters['direction_id'] ?? null,
                'service_id' => $filters['service_id'] ?? null,
                'status' => AiReportRequest::STATUS_GENERATING,
            ]);

            $metrics = $this->aggregator->aggregate(AiReportRequest::TYPE_ANNUAL, $filters + [
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ]);
            $draft = $this->narrative->generate($request, $metrics, $user);
            $report = $request->report()->create([
                'title' => $draft['title'],
                'summary' => $draft['summary'],
                'report_html' => $draft['html'],
                'report_json' => $metrics,
                'generated_at' => now(),
            ]);

            foreach ($draft['sections'] as $index => $section) {
                $report->sections()->create([
                    'section_title' => $section['title'],
                    'section_order' => $index + 1,
                    'content' => $section['content'],
                    'indicators_json' => $section['indicators'],
                ]);
            }

            $request->forceFill(['status' => AiReportRequest::STATUS_COMPLETED])->save();

            return $report->load('sections', 'request');
        });
    }
}
