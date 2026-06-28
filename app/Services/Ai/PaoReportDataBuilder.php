<?php

namespace App\Services\Ai;

class PaoReportDataBuilder
{
    public function __construct(
        private readonly ActionReportMetricsBuilder $metrics
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = []): array
    {
        return $this->metrics->build('pao', $filters);
    }
}
