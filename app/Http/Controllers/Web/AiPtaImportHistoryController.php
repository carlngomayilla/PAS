<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiImportBatch;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiPtaImportHistoryController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('ai_pta_import.history'), 403);

        return view('workspace.ai-imports.pta.history', [
            'batches' => AiImportBatch::query()
                ->with(['user:id,name,email,role,custom_role_code'])
                ->withCount(['rows', 'blockingRows'])
                ->latest()
                ->paginate(20),
        ]);
    }
}
