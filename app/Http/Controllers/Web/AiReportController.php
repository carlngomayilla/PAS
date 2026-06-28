<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiGeneratedReport;
use App\Models\Direction;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AiReportController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizePermission($request, 'ai_reports.view');

        return view('workspace.ai-reports.index', [
            'reports' => AiGeneratedReport::query()->with('user:id,name,email,role,custom_role_code')->latest()->paginate(15),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizePermission($request, 'ai_reports.generate');

        return view('workspace.ai-reports.create', [
            'types' => AiGeneratedReport::reportTypes(),
            'directions' => Direction::query()->orderBy('libelle')->get(),
            'services' => Service::query()->orderBy('libelle')->get(),
        ]);
    }

    public function show(Request $request, AiGeneratedReport $report): View
    {
        $this->authorizePermission($request, 'ai_reports.view');

        return view('workspace.ai-reports.show', [
            'report' => $report->load('user:id,name,email,role,custom_role_code'),
            'types' => AiGeneratedReport::reportTypes(),
        ]);
    }

    public function update(Request $request, AiGeneratedReport $report): RedirectResponse
    {
        $this->authorizePermission($request, 'ai_reports.edit');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['nullable', Rule::in([AiGeneratedReport::STATUS_DRAFT, AiGeneratedReport::STATUS_VALIDATED])],
        ]);

        $contentColumn = $report->status === AiGeneratedReport::STATUS_VALIDATED ? 'validated_content' : 'ai_draft';

        $report->forceFill([
            'title' => $validated['title'],
            $contentColumn => $validated['content'],
            'status' => $validated['status'] ?? $report->status,
        ])->save();

        return redirect()
            ->route('workspace.ai-reports.show', $report)
            ->with('status', 'Rapport mis a jour.');
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }
}
