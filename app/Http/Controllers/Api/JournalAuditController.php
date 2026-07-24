<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalAudit;
use App\Models\User;
use App\Services\Audit\AuditWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalAuditController extends Controller
{
    public function __construct(
        private readonly AuditWorkspaceService $auditWorkspaceService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAuditReader($request);
        $input = $request->query();
        $input['date_from'] = $input['date_from'] ?? $input['date_debut'] ?? null;
        $input['date_to'] = $input['date_to'] ?? $input['date_fin'] ?? null;
        $input['per_page'] = $input['per_page'] ?? 20;
        $filters = $this->auditWorkspaceService->normalizeFilters($input);
        $paginator = $this->auditWorkspaceService->paginate(
            $this->auditWorkspaceService->filteredQuery($filters),
            $filters
        );
        $paginator->setCollection(
            $paginator->getCollection()
                ->map(fn (JournalAudit $audit): array => $this->auditWorkspaceService->apiPayload($audit))
        );

        return response()->json($paginator);
    }

    public function show(Request $request, JournalAudit $journalAudit): JsonResponse
    {
        $this->authorizeAuditReader($request);

        return response()->json([
            'data' => $this->auditWorkspaceService->apiPayload(
                $journalAudit->load('user:id,name,email')
            ),
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
