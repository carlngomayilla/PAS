<?php

namespace App\Services\AiReporting;

use App\Models\AiReport;
use App\Models\AiReportRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class QuarterlyReportService
{
    public function __construct(
        private readonly ReportDataAggregatorService $aggregator,
        private readonly AiReportNarrativeService $narrative
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     */
    public function generate(?User $user, int $year, int $quarter, array $filters = []): AiReport
    {
        $quarter = max(1, min(4, $quarter));
        $start = Carbon::create($year, (($quarter - 1) * 3) + 1, 1)->startOfMonth();
        $end = $start->copy()->addMonths(2)->endOfMonth();

        return DB::transaction(function () use ($user, $start, $end, $filters): AiReport {
            $request = AiReportRequest::query()->create([
                'user_id' => $user?->id,
                'report_type' => AiReportRequest::TYPE_QUARTERLY,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'direction_id' => $filters['direction_id'] ?? null,
                'service_id' => $filters['service_id'] ?? null,
                'status' => AiReportRequest::STATUS_GENERATING,
            ]);

            $metrics = $this->aggregator->aggregate(AiReportRequest::TYPE_QUARTERLY, $filters + [
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
