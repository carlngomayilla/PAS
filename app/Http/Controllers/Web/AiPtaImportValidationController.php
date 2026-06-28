<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Services\Ai\PtaExcelGenerationService;
use App\Services\Ai\PtaFinalImportService;
use App\Services\Ai\PtaImportAuditService;
use App\Services\Ai\PtaImportValidationService;
use App\Services\Ai\PtaNormalizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiPtaImportValidationController extends Controller
{
    public function __construct(
        private readonly PtaImportValidationService $validation,
        private readonly PtaFinalImportService $finalImport,
        private readonly PtaExcelGenerationService $excel,
        private readonly PtaImportAuditService $audit
    ) {}

    public function updateRow(Request $request, AiImportBatch $batch, AiImportRow $row): RedirectResponse
    {
        $this->authorizePermission($request, 'ai_pta_import.correct');
        abort_unless((int) $row->batch_id === (int) $batch->id, 404);

        $before = $row->toArray();
        $action = (string) $request->input('action', 'save');

        if ($action === 'ignore') {
            $row->forceFill([
                'status' => AiImportRow::STATUS_IGNORED,
                'validation_errors' => null,
            ])->save();
        } else {
            $validated = $request->validate([
                'normalized' => ['required', 'array'],
                'normalized.*' => ['nullable'],
            ]);

            $payload = array_replace(
                array_fill_keys(PtaNormalizationService::FIELDS, null),
                $validated['normalized']
            );
            $row->forceFill(['normalized_payload' => $payload])->save();
            $this->validation->validateRow($row, true);
        }

        $this->excel->generate($batch->refresh());
        $this->audit->record('row_'.$action, $batch, $request->user(), $request, $before, $row->refresh()->toArray());

        return redirect()
            ->route('workspace.ai-imports.pta.preview', $batch)
            ->with('status', 'Ligne mise a jour.');
    }

    public function validateBatch(Request $request, AiImportBatch $batch): RedirectResponse
    {
        $this->authorizePermission($request, 'ai_pta_import.validate');

        $stats = $this->validation->validateBatch($batch);
        $this->excel->generate($batch->refresh());
        $this->audit->record('validate_batch', $batch, $request->user(), $request, null, $stats);

        return redirect()
            ->route('workspace.ai-imports.pta.preview', $batch)
            ->with('status', $stats['invalid'] > 0 ? 'Validation terminee avec erreurs.' : 'Import pret pour confirmation finale.');
    }

    public function import(Request $request, AiImportBatch $batch): RedirectResponse
    {
        $this->authorizePermission($request, 'ai_pta_import.import');

        try {
            $stats = $this->finalImport->import($batch, $request->user());
        } catch (\Throwable $exception) {
            return back()->withErrors(['import' => $exception->getMessage()]);
        }

        $this->audit->record('final_import', $batch->refresh(), $request->user(), $request, null, $stats);

        return redirect()
            ->route('workspace.ai-imports.pta.preview', $batch)
            ->with('status', 'Import final termine : '.$stats['imported'].' action(s) importee(s).');
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }
}
