<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAiImportRequest;
use App\Jobs\AnalyzePasPaoPtaWithAiJob;
use App\Models\AiImportSession;
use App\Services\AiImport\DocumentExtractionService;
use App\Services\AiImport\ExcelMappingService;
use App\Services\AiImport\ImportValidationService;
use App\Services\AiImport\PasPaoPtaAnalysisService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;

class AiImportController extends Controller
{
    public function __construct(
        private readonly DocumentExtractionService $documents,
        private readonly PasPaoPtaAnalysisService $analysis,
        private readonly ImportValidationService $validation,
        private readonly ExcelMappingService $excel
    ) {}

    public function index(Request $request): View
    {
        $this->authorizePermission($request, 'ai_pta_import.view');

        return view('workspace.ai-imports.index', [
            'sessions' => AiImportSession::query()->with('user:id,name,email,role,custom_role_code')->latest()->paginate(12),
        ]);
    }

    public function store(StoreAiImportRequest $request): RedirectResponse
    {
        $session = $this->documents->createSession(
            $request->file('file'),
            $request->user(),
            $request->validated('document_type')
        );

        if (config('queue.default') !== 'sync') {
            AnalyzePasPaoPtaWithAiJob::dispatch($session->id)->onQueue((string) config('ai_training.pta.import_queue', 'ai-imports'));

            return redirect()
                ->route('workspace.ai-imports.review', $session)
                ->with('status', 'Analyse IA lancee en arriere-plan.');
        }

        try {
            $extraction = $this->documents->extract($session);
            $this->analysis->analyzeAndPersist($session->refresh(), $extraction, $request->user());
        } catch (RuntimeException $exception) {
            $session->forceFill([
                'status' => AiImportSession::STATUS_FAILED,
                'completed_at' => now(),
            ])->save();
            report($exception);

            return back()->withInput()->withErrors(['openai' => $exception->getMessage()]);
        }
        $this->validation->validateSession($session->refresh());
        $this->excel->generate($session->refresh());

        return redirect()
            ->route('workspace.ai-imports.review', $session)
            ->with('status', 'Analyse terminee. Validation humaine requise avant import.');
    }

    public function analyze(Request $request, AiImportSession $session): RedirectResponse
    {
        $this->authorizePermission($request, 'ai_pta_import.analyze');

        if (config('queue.default') !== 'sync') {
            AnalyzePasPaoPtaWithAiJob::dispatch($session->id)->onQueue((string) config('ai_training.pta.import_queue', 'ai-imports'));

            return back()->with('status', 'Analyse relancee en arriere-plan.');
        }

        try {
            $extraction = $this->documents->extract($session);
            $this->analysis->analyzeAndPersist($session->refresh(), $extraction, $request->user());
        } catch (RuntimeException $exception) {
            $session->forceFill([
                'status' => AiImportSession::STATUS_FAILED,
                'completed_at' => now(),
            ])->save();
            report($exception);

            return back()->withErrors(['openai' => $exception->getMessage()]);
        }
        $this->validation->validateSession($session->refresh());
        $this->excel->generate($session->refresh());

        return redirect()
            ->route('workspace.ai-imports.review', $session)
            ->with('status', 'Analyse terminee.');
    }

    public function downloadExcel(Request $request, AiImportSession $session)
    {
        $this->authorizePermission($request, 'ai_pta_import.export');

        $this->excel->generate($session);

        return Storage::disk('local')->download(
            (string) $session->refresh()->generated_excel_path,
            'import-pas-pao-pta-'.$session->id.'.xlsx'
        );
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }
}
