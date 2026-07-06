<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzePtaImportBatch;
use App\Models\AiImportBatch;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\Service;
use App\Services\Ai\PtaExcelGenerationService;
use App\Services\Ai\PtaFileStorageService;
use App\Services\Ai\PtaImportAnalysisService;
use App\Services\Ai\PtaImportAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AiPtaImportController extends Controller
{
    public function __construct(
        private readonly PtaFileStorageService $storage,
        private readonly PtaImportAnalysisService $analysis,
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
            'llmStatus' => $this->llmStatus(),
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

        if ($batch->status === AiImportBatch::STATUS_EXTRACTING) {
            return redirect()
                ->route('workspace.ai-imports.pta.preview', $batch)
                ->with('status', 'Analyse IA/OCR deja en cours. Actualisez la previsualisation dans quelques instants.');
        }

        if (! $this->runsQueueSynchronously()) {
            $batch->forceFill([
                'status' => AiImportBatch::STATUS_EXTRACTING,
                'error_message' => null,
            ])->save();

            AnalyzePtaImportBatch::dispatch($batch);
            $this->audit->record('analyze_queued', $batch->refresh(), $request->user(), $request);

            return redirect()
                ->route('workspace.ai-imports.pta.preview', $batch)
                ->with('status', 'Analyse IA/OCR lancee en arriere-plan. La previsualisation se mettra a jour apres traitement.');
        }

        try {
            $result = $this->analysis->analyze($batch);
        } catch (\Throwable $exception) {
            $this->audit->record('analyze_failed', $batch, $request->user(), $request, null, ['message' => $exception->getMessage()]);

            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        $this->audit->record('analyze', $batch->refresh(), $request->user(), $request, null, [
            'stats' => $result['stats'],
            'completion' => $result['completion'],
            'hierarchy' => $result['hierarchy'],
            'repair' => $result['repair'],
            'warning' => $result['warning'],
            'excel' => $result['excel'],
        ]);

        return redirect()
            ->route('workspace.ai-imports.pta.preview', $batch)
            ->with('status', $this->analysisStatusMessage($result['warning'] ?? null));
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

    private function analysisStatusMessage(?string $warning): string
    {
        if ($warning !== null && trim($warning) !== '') {
            return 'Analyse terminee avec avertissement: '.$warning;
        }

        return 'Analyse terminee. Les lignes invalides doivent etre corrigees ou ignorees.';
    }

    private function runsQueueSynchronously(): bool
    {
        return config('queue.default') === 'sync';
    }

    /**
     * @return array{enabled:bool,tone:string,label:string,message:string,provider:string,models:list<string>}
     */
    private function llmStatus(): array
    {
        $enabled = (bool) config('ai_training.pta.llm_enabled', true);
        $provider = (string) (config('ai_training.pta.llm_provider') ?: config('ai.default', 'non configure'));
        $models = collect([
            config('ai_training.pta.llm_model'),
            config('ai_training.pta.llm_text_model'),
            config('ai_training.pta.llm_reasoning_model'),
            config('ai_training.pta.llm_vision_model'),
        ])
            ->filter(fn (mixed $model): bool => is_string($model) && trim($model) !== '')
            ->map(fn (mixed $model): string => trim((string) $model))
            ->unique()
            ->values()
            ->all();

        if (! $enabled) {
            return [
                'enabled' => false,
                'tone' => 'warning',
                'label' => 'IA PTA desactivee',
                'message' => 'L import continue avec l extraction locale et la correction humaine.',
                'provider' => $provider,
                'models' => $models,
            ];
        }

        if ($provider !== 'ollama') {
            return [
                'enabled' => true,
                'tone' => 'info',
                'label' => 'IA PTA configuree',
                'message' => 'Provider '.$provider.' actif. La validation humaine reste obligatoire avant import final.',
                'provider' => $provider,
                'models' => $models,
            ];
        }

        $url = rtrim((string) config('ai.providers.ollama.url', 'http://127.0.0.1:11434'), '/');
        if ($url === '') {
            return [
                'enabled' => true,
                'tone' => 'danger',
                'label' => 'Ollama non configure',
                'message' => 'Renseignez OLLAMA_URL ou desactivez AI_PTA_LLM_ENABLED.',
                'provider' => $provider,
                'models' => $models,
            ];
        }

        try {
            $response = Http::baseUrl($url)
                ->connectTimeout(max(1, (int) config('ai_training.pta.llm_connect_timeout', 5)))
                ->timeout(10)
                ->get('api/tags')
                ->throw()
                ->json();
            $installed = collect($response['models'] ?? [])
                ->map(fn (mixed $model): string => is_array($model) ? (string) ($model['name'] ?? '') : '')
                ->filter()
                ->values();
            $missing = collect($models)->reject(fn (string $model): bool => $installed->contains($model))->values();

            if ($missing->isNotEmpty()) {
                return [
                    'enabled' => true,
                    'tone' => 'warning',
                    'label' => 'Ollama connecte, modele manquant',
                    'message' => 'Modeles absents: '.$missing->implode(', ').'. L extraction basculera sur le fallback si l appel IA echoue.',
                    'provider' => $provider,
                    'models' => $models,
                ];
            }

            return [
                'enabled' => true,
                'tone' => 'warning',
                'label' => 'Ollama connecte, fiabilite a valider',
                'message' => 'Les modeles locaux sont installes, mais cela ne garantit pas une extraction PTA a 100/100. Une validation humaine reste obligatoire.',
                'provider' => $provider,
                'models' => $models,
            ];
        } catch (\Throwable) {
            return [
                'enabled' => true,
                'tone' => 'danger',
                'label' => 'Ollama indisponible',
                'message' => 'Le serveur '.$url.' ne repond pas. L analyse continue avec l extraction locale si possible.',
                'provider' => $provider,
                'models' => $models,
            ];
        }
    }
}
