<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Analytics\ReportingAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportingController extends Controller
{
    use AuthorizesPlanningScope;

    public function __construct(
        private readonly ReportingAnalyticsService $reportingAnalyticsService
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $payload = $this->reportingAnalyticsService->buildPayload($user, false, false);

        return response()->json([
            'generated_at' => $payload['generatedAt'] ?? now(),
            'scope' => $payload['scope'] ?? [],
            'global' => $payload['global'] ?? [],
            'kpi_summary' => $payload['kpiSummary'] ?? [],
            'statuts' => $payload['statuts'] ?? [],
            'alertes' => $payload['alertes'] ?? [],
        ]);
    }
}
