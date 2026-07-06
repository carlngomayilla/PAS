<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use App\Services\Ai\PtaNormalizationService;
use App\Services\Imports\PlanningExcelImportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiPtaImportPreviewController extends Controller
{
    private const PREVIEW_ROWS_PER_PAGE = 50;

    public function show(Request $request, AiImportBatch $batch): View
    {
        abort_unless($request->user()?->hasPermission('ai_pta_import.preview'), 403);

        $batch->load(['user:id,name,email,role,custom_role_code']);

        $stats = $this->rowStats($batch);
        $previewRows = $batch->rows()
            ->paginate(self::PREVIEW_ROWS_PER_PAGE)
            ->withQueryString();
        $batch->setRelation('rows', $previewRows->getCollection());

        return view('workspace.ai-imports.pta.preview', [
            'batch' => $batch,
            'fields' => PtaNormalizationService::FIELDS,
            'importColumns' => PlanningExcelImportService::IMPORT_COLUMNS,
            'isAnalysisRunning' => $batch->status === AiImportBatch::STATUS_EXTRACTING,
            'isExtractionPending' => $stats['total'] === 0 && in_array($batch->status, [
                AiImportBatch::STATUS_UPLOADED,
                AiImportBatch::STATUS_EXTRACTING,
            ], true),
            'isPreviewPaginated' => $previewRows->hasPages(),
            'previewRows' => $previewRows,
            'stats' => $stats,
        ]);
    }

    /**
     * @return array{total:int,valid:int,invalid:int,ignored:int,imported:int}
     */
    private function rowStats(AiImportBatch $batch): array
    {
        $counts = $batch->rows()
            ->reorder()
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            'valid' => (int) $counts->get(AiImportRow::STATUS_VALID, 0) + (int) $counts->get(AiImportRow::STATUS_CORRECTED, 0),
            'invalid' => (int) $counts->get(AiImportRow::STATUS_INVALID, 0),
            'ignored' => (int) $counts->get(AiImportRow::STATUS_IGNORED, 0),
            'imported' => (int) $counts->get(AiImportRow::STATUS_IMPORTED, 0),
        ];
    }
}
