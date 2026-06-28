<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiImportBatch;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\Service;
use App\Services\Ai\PtaExcelGenerationService;
use App\Services\Ai\PtaExtractionService;
use App\Services\Ai\PtaFileStorageService;
use App\Services\Ai\PtaImportAuditService;
use App\Services\Ai\PtaImportValidationService;
use App\Services\Ai\PtaNormalizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AiPtaImportController extends Controller
{
    public function __construct(
        private readonly PtaFileStorageService $storage,
        private readonly PtaExtractionService $extraction,
        private readonly PtaNormalizationService $normalization,
        private readonly PtaImportValidationService $validation,
        private readonly PtaExcelGenerationService $excel,
        private readonly PtaImportAuditService $audit
    ) {}

    public function index(Request $request): View
    {
        $this->authorizePermission($request, 'ai_pta_import.view');

        return view('workspace.ai-imports.pta.index', [
            'batches' => AiImportBatch::query()->with('user:id,name,email,role,custom_role_code')->latest()->paginate(12),
            'exercices' => Exercice::query()->orderByDesc('annee')->get(),
            'directions' => Direction::query()->orderBy('libelle')->get(),
            'services' => Service::query()->with('direction:id,libelle')->orderBy('libelle')->get(),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'ai_pta_import.upload');

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,xlsx,csv,png,jpg,jpeg', 'max:20480'],
            'detected_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'detected_direction' => ['nullable', 'string', 'max:255'],
            'detected_service' => ['nullable', 'string', 'max:255'],
        ]);

        $stored = $this->storage->store($validated['file']);
        $batch = AiImportBatch::query()->create([
            'user_id' => $request->user()?->id,
            'original_filename' => $validated['file']->getClientOriginalName(),
            'file_path' => $stored['path'],
            'file_type' => $stored['file_type'],
            'status' => AiImportBatch::STATUS_UPLOADED,
            'detected_year' => $validated['detected_year'] ?? null,
            'detected_direction' => $validated['detected_direction'] ?? null,
            'detected_service' => $validated['detected_service'] ?? null,
        ]);

        $this->audit->record('upload', $batch, $request->user(), $request, null, $batch->toArray());

        return redirect()
            ->route('workspace.ai-imports.pta.preview', $batch)
            ->with('status', 'Fichier PTA charge. Analyse IA prete a lancer.');
    }

    public function analyze(Request $request, AiImportBatch $batch): RedirectResponse
    {
        $this->authorizePermission($request, 'ai_pta_import.analyze');

        try {
            $this->extraction->extract($batch);
            $this->normalization->normalize($batch->refresh());
            $stats = $this->validation->validateBatch($batch->refresh());
            $this->excel->generate($batch->refresh());
        } catch (\Throwable $exception) {
            $this->audit->record('analyze_failed', $batch, $request->user(), $request, null, ['message' => $exception->getMessage()]);

            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        $this->audit->record('analyze', $batch->refresh(), $request->user(), $request, null, $stats);

        return redirect()
            ->route('workspace.ai-imports.pta.preview', $batch)
            ->with('status', 'Analyse terminee. Les lignes invalides doivent etre corrigees ou ignorees.');
    }

    public function downloadExcel(Request $request, AiImportBatch $batch)
    {
        $this->authorizePermission($request, 'ai_pta_import.export');

        $this->excel->generate($batch);

        $this->audit->record('download_excel', $batch, $request->user(), $request);

        return Storage::disk('local')->download(
            (string) $batch->refresh()->generated_excel_path,
            'pta-normalise-'.$batch->id.'.xlsx'
        );
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }
}
