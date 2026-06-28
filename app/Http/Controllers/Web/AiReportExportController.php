<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiGeneratedReport;
use App\Services\Ai\ReportExportService;
use Illuminate\Http\Request;

class AiReportExportController extends Controller
{
    public function __construct(
        private readonly ReportExportService $exports
    ) {}

    public function pdf(Request $request, AiGeneratedReport $report)
    {
        $this->authorizeExport($request);

        return $this->exports->pdf($report);
    }

    public function word(Request $request, AiGeneratedReport $report)
    {
        $this->authorizeExport($request);

        return $this->exports->word($report);
    }

    public function excel(Request $request, AiGeneratedReport $report)
    {
        $this->authorizeExport($request);

        return $this->exports->excel($report);
    }

    private function authorizeExport(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('ai_reports.export'), 403);
    }
}
