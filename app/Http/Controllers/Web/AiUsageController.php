<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Services\OpenAi\OpenAiUsageBillingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiUsageController extends Controller
{
    public function __construct(
        private readonly OpenAiUsageBillingService $billing
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('ai_reports.view') || $request->user()?->hasPermission('ai_pta_import.view'), 403);

        $logs = AiUsageLog::query()->with('user:id,name,email')->latest()->paginate(25);

        return view('workspace.ai-usage.index', [
            'logs' => $logs,
            'monthlyTotal' => $this->billing->currentMonthTotalUsd(),
            'monthlyBudget' => $this->billing->monthlyBudgetUsd(),
            'byModule' => AiUsageLog::query()
                ->selectRaw('module, COUNT(*) as calls, SUM(total_tokens) as tokens, SUM(total_cost_usd) as cost')
                ->groupBy('module')
                ->orderBy('module')
                ->get(),
        ]);
    }
}
