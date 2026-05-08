<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportingOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = (array) $this->resource;

        return [
            'generated_at' => $payload['generatedAt'] ?? now(),
            'scope' => $payload['scope'] ?? [],
            'global' => $payload['global'] ?? [],
            'kpi_summary' => $payload['kpiSummary'] ?? [],
            'statuts' => $payload['statuts'] ?? [],
            'alertes' => $payload['alertes'] ?? [],
        ];
    }
}