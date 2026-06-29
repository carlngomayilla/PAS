<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiImportBatch;
use App\Models\Direction;
use App\Models\Service;
use App\Services\Ai\PtaNormalizationService;
use App\Services\Imports\PlanningExcelImportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiPtaImportPreviewController extends Controller
{
    public function show(Request $request, AiImportBatch $batch): View
    {
        abort_unless($request->user()?->hasPermission('ai_pta_import.preview'), 403);

        $batch->load(['rows', 'user:id,name,email,role,custom_role_code']);

        return view('workspace.ai-imports.pta.preview', [
            'batch' => $batch,
            'fields' => PtaNormalizationService::FIELDS,
            'importColumns' => PlanningExcelImportService::IMPORT_COLUMNS,
            'directions' => Direction::query()->orderBy('libelle')->get(),
            'services' => Service::query()->orderBy('libelle')->get(),
            'stats' => [
                'total' => $batch->rows->count(),
                'valid' => $batch->rows->whereIn('status', ['valid', 'corrected'])->count(),
                'invalid' => $batch->rows->where('status', 'invalid')->count(),
                'ignored' => $batch->rows->where('status', 'ignored')->count(),
                'imported' => $batch->rows->where('status', 'imported')->count(),
            ],
        ]);
    }
}
