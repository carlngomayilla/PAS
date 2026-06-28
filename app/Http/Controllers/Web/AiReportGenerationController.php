<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiGeneratedReport;
use App\Services\Ai\ActionReportMetricsBuilder;
use App\Services\Ai\AiReportWritingService;
use App\Services\Ai\PaoReportDataBuilder;
use App\Services\Ai\PasReportDataBuilder;
use App\Services\Ai\PtaReportDataBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiReportGenerationController extends Controller
{
    public function __construct(
        private readonly PasReportDataBuilder $pasBuilder,
        private readonly PaoReportDataBuilder $paoBuilder,
        private readonly PtaReportDataBuilder $ptaBuilder,
        private readonly ActionReportMetricsBuilder $metricsBuilder,
        private readonly AiReportWritingService $writer
    ) {}

    public function generate(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('ai_reports.generate'), 403);

        $validated = $request->validate([
            'report_type' => ['required', Rule::in(array_keys(AiGeneratedReport::reportTypes()))],
            'title' => ['nullable', 'string', 'max:255'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
        ]);

        $filters = collect($validated)
            ->only(['period_start', 'period_end', 'direction_id', 'service_id'])
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->all();
        $type = (string) $validated['report_type'];
        $title = trim((string) ($validated['title'] ?? ''))
            ?: (AiGeneratedReport::reportTypes()[$type] ?? 'Rapport IA').' - '.now()->format('d/m/Y');
        $metrics = $this->metricsFor($type, $filters);
        $draft = $this->writer->draft($title, $type, $metrics);

        $report = AiGeneratedReport::query()->create([
            'user_id' => $request->user()?->id,
            'report_type' => $type,
            'title' => $title,
            'period_start' => $filters['period_start'] ?? null,
            'period_end' => $filters['period_end'] ?? null,
            'filters' => $filters,
            'metrics_snapshot' => $metrics,
            'ai_draft' => $draft,
            'status' => AiGeneratedReport::STATUS_DRAFT,
        ]);

        return redirect()
            ->route('workspace.ai-reports.show', $report)
            ->with('status', 'Rapport IA genere a partir des metriques Laravel.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function metricsFor(string $type, array $filters): array
    {
        return match ($type) {
            AiGeneratedReport::TYPE_PAO_DIRECTION => $this->paoBuilder->build($filters),
            AiGeneratedReport::TYPE_PTA_ANNUAL,
            AiGeneratedReport::TYPE_PTA_QUARTERLY,
            AiGeneratedReport::TYPE_EXECUTION_MONTHLY => $this->ptaBuilder->build($filters),
            AiGeneratedReport::TYPE_LATE_ACTIONS => $this->metricsBuilder->build('late_actions', $filters),
            AiGeneratedReport::TYPE_RUNNING_ACTIONS => $this->metricsBuilder->build('running_actions', $filters),
            AiGeneratedReport::TYPE_CLOSED_ACTIONS => $this->metricsBuilder->build('closed_actions', $filters),
            default => $this->pasBuilder->build($filters),
        };
    }
}
