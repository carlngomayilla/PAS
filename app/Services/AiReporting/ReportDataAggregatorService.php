<?php

namespace App\Services\AiReporting;

use App\Models\AiReportRequest;
use App\Services\Ai\ActionReportMetricsBuilder;

class ReportDataAggregatorService
{
    public function __construct(
        private readonly ActionReportMetricsBuilder $metrics
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function aggregate(string $reportType, array $filters = []): array
    {
        $scope = match ($reportType) {
            AiReportRequest::TYPE_MONTHLY,
            AiReportRequest::TYPE_QUARTERLY,
            AiReportRequest::TYPE_ANNUAL => 'pta',
            default => 'pta',
        };

        return $this->metrics->build($scope, $filters) + [
            'report_type' => $reportType,
            'institutional_source' => 'laravel_database',
        ];
    }
}
