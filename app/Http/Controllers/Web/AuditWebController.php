<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditWorkspaceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditWebController extends Controller
{
    public function __construct(
        private readonly AuditWorkspaceService $auditWorkspaceService
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeAuditReader($request);
        $filters = $this->auditWorkspaceService->normalizeFilters($request->query());
        $workspace = $this->auditWorkspaceService->workspace($filters);

        return view('workspace.audit.index', [
            'logs' => $workspace['logs'],
            'summary' => $workspace['summary'],
            'options' => $workspace['options'],
            'filters' => $filters,
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorizeAuditReader($request);
        $filters = $this->auditWorkspaceService->normalizeFilters($request->query());
        $filename = 'journal-audit-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($filters): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                abort(500, 'Impossible de générer le fichier d’audit.');
            }

            try {
                $this->auditWorkspaceService->writeCsv($stream, $filters);
            } finally {
                fclose($stream);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeAuditReader(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('audit.read')) {
            abort(403, 'Accès non autorisé.');
        }

        return $user;
    }
}
