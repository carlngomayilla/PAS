<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiGeneratedReport;
use App\Services\Ai\PtaTrainingDatasetService;
use App\Services\Ai\ReportValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiReportValidationController extends Controller
{
    public function __construct(
        private readonly ReportValidationService $validation,
        private readonly PtaTrainingDatasetService $training
    ) {}

    public function validateReport(Request $request, AiGeneratedReport $report): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('ai_reports.validate'), 403);

        $validated = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $this->validation->validate($report, $validated['content'], $request->user());
        $this->training->recordValidatedReport($report->refresh(), $request->user());

        return redirect()
            ->route('workspace.ai-reports.show', $report)
            ->with('status', 'Rapport valide et pret pour export.');
    }
}
