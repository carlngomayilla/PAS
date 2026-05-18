<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\JournalAudit;
use App\Models\User;
use App\Services\PlatformDiagnosticService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditWebController extends Controller
{
    public function __construct(
        private readonly PlatformDiagnosticService $platformDiagnosticService
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('audit.read')) {
            abort(403, 'Acces non autorise.');
        }

        $query = $this->filteredQuery($request);

        return view('workspace.audit.index', [
            'logs' => $query->orderByDesc('id')->paginate(30)->withQueryString(),
            'summary' => $this->platformDiagnosticService->filteredAuditSummary([
                'module' => (string) $request->string('module'),
                'action' => (string) $request->string('action'),
                'user_id' => $request->filled('user_id') ? (int) $request->integer('user_id') : null,
                'entite_type' => (string) $request->string('entite_type'),
                'entite_id' => $request->filled('entite_id') ? (int) $request->integer('entite_id') : null,
                'date_from' => (string) $request->string('date_from'),
                'date_to' => (string) $request->string('date_to'),
                'q' => (string) $request->string('q'),
            ]),
            'filters' => [
                'module' => (string) $request->string('module'),
                'action' => (string) $request->string('action'),
                'user_id' => $request->filled('user_id') ? (int) $request->integer('user_id') : null,
                'entite_type' => (string) $request->string('entite_type'),
                'entite_id' => $request->filled('entite_id') ? (int) $request->integer('entite_id') : null,
                'date_from' => (string) $request->string('date_from'),
                'date_to' => (string) $request->string('date_to'),
                'q' => (string) $request->string('q'),
            ],
        ]);
    }


    private function filteredQuery(Request $request)
    {
        $query = JournalAudit::query()->with('user:id,name,email');

        $query->when(
            $request->filled('module'),
            fn ($q) => $q->where('module', (string) $request->string('module'))
        );

        $query->when(
            $request->filled('action'),
            fn ($q) => $q->where('action', (string) $request->string('action'))
        );

        $query->when(
            $request->filled('user_id'),
            fn ($q) => $q->where('user_id', (int) $request->integer('user_id'))
        );

        $query->when(
            $request->filled('entite_type'),
            fn ($q) => $q->where('entite_type', (string) $request->string('entite_type'))
        );

        $query->when(
            $request->filled('entite_id'),
            fn ($q) => $q->where('entite_id', (int) $request->integer('entite_id'))
        );
        $query->when(
            $request->filled('date_from'),
            fn ($q) => $q->whereDate('created_at', '>=', (string) $request->string('date_from'))
        );
        $query->when(
            $request->filled('date_to'),
            fn ($q) => $q->whereDate('created_at', '<=', (string) $request->string('date_to'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('module', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('entite_type', 'like', "%{$search}%");
            });
        });

        return $query;
    }
}
