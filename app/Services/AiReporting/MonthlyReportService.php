<?php

namespace App\Services\AiReporting;

use App\Models\AiReport;
use App\Models\AiReportRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MonthlyReportService
{
    public function __construct(
        private readonly ReportDataAggregatorService $aggregator,
        private readonly AiReportNarrativeService $narrative
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     */
    public function generate(?User $user, Carbon|string $month, array $filters = []): AiReport
    {
        $start = Carbon::parse($month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return $this->create(AiReportRequest::TYPE_MONTHLY, $user, $start, $end, $filters);
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function create(string $type, ?User $user, Carbon $start, Carbon $end, array $filters): AiReport
    {
        return DB::transaction(function () use ($type, $user, $start, $end, $filters): AiReport {
            $request = AiReportRequest::query()->create([
                'user_id' => $user?->id,
                'report_type' => $type,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'direction_id' => $filters['direction_id'] ?? null,
                'service_id' => $filters['service_id'] ?? null,
                'status' => AiReportRequest::STATUS_GENERATING,
            ]);

            $metrics = $this->aggregator->aggregate($type, $filters + [
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
