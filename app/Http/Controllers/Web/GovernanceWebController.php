<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelDelegationRequest;
use App\Http\Requests\ResubmitDeletionRequestRequest;
use App\Http\Requests\RunRetentionRequest;
use App\Http\Requests\StoreDelegationRequest;
use App\Models\DataArchive;
use App\Models\Delegation;
use App\Models\DeletionRequest;
use App\Models\Direction;
use App\Models\RetentionRun;
use App\Models\Service;
use App\Models\User;
use App\Services\DeletionRequestService;
use App\Services\Governance\DelegationService;
use App\Services\Governance\GovernanceQueueService;
use App\Services\Governance\RetentionOperationService;
use App\Services\Governance\RetentionWorkspaceService;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GovernanceWebController extends Controller
{
    use RecordsAuditTrail;

    public function apiDocumentation(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('api_docs.read')) {
            abort(403, 'Acces non autorise.');
        }

        return view('workspace.governance.api-docs', [
            'specUrl' => route('workspace.api-docs.spec'),
        ]);
    }

    public function apiSpec(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('api_docs.read')) {
            abort(403, 'Acces non autorise.');
        }

        $path = base_path('docs/openapi.yaml');
        if (! File::exists($path)) {
            abort(404);
        }

        return response(File::get($path), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
        ]);
    }

    public function retentionIndex(Request $request, RetentionWorkspaceService $retentionWorkspaceService): View
    {
        $user = $this->authorizeRetentionReader($request);
        $workspace = $retentionWorkspaceService->workspace($request->query());

        return view('workspace.governance.retention', [
            ...$workspace,
            'canRun' => $user->hasPermission('retention.manage'),
        ]);
    }

    public function retentionRun(
        RunRetentionRequest $request,
        RetentionOperationService $retentionOperationService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $scope = (string) $request->validated('scope');
        $execute = $request->validated('mode') === RetentionRun::MODE_EXECUTE;
        $operation = $retentionOperationService->run(
            $scope,
            $execute,
            $user,
            'web',
            $request->ip(),
            $request->userAgent()
        );
        $run = $operation['run'];
        $counts = $execute ? ($run->processed ?? []) : ($run->candidates ?? []);
        $message = sprintf(
            '%s #%d terminée : %d élément(s) %s.',
            $execute ? 'Exécution' : 'Simulation',
            (int) $run->id,
            (int) array_sum($counts),
            $execute ? 'traité(s)' : 'éligible(s)'
        );

        return redirect()
            ->route('workspace.retention.index')
            ->with('success', $message);
    }

    public function retentionExportCsv(
        Request $request,
        RetentionWorkspaceService $retentionWorkspaceService
    ): StreamedResponse {
        $this->authorizeRetentionReader($request);
        $filters = $retentionWorkspaceService->normalizeFilters($request->query());

        return response()->streamDownload(function () use ($retentionWorkspaceService, $filters): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                abort(500, 'Impossible de générer le registre des archives.');
            }

            try {
                $retentionWorkspaceService->writeCsv($stream, $filters);
            } finally {
                fclose($stream);
            }
        }, 'archives-retention-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function retentionArchiveDownload(
        Request $request,
        DataArchive $dataArchive,
        RetentionWorkspaceService $retentionWorkspaceService
    ): StreamedResponse {
        $this->authorizeRetentionReader($request);
        $json = $retentionWorkspaceService->archiveJson($dataArchive);

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            'archive-retention-'.$dataArchive->id.'.json',
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function delegationsIndex(Request $request, DelegationService $delegationService): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('delegations.manage')) {
            abort(403, 'Acces non autorise.');
        }

        return view('workspace.governance.delegations.index', $delegationService->directory($request->query()));
    }

    public function delegationsCreate(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasPermission('delegations.manage')) {
            abort(403, 'Acces non autorise.');
        }

        return view('workspace.governance.delegations.form', [
            'delegation' => new Delegation([
                'role_scope' => Delegation::SCOPE_SERVICE,
                'permissions' => ['planning_read', 'action_review'],
                'date_debut' => now()->format('Y-m-d\TH:i'),
                'date_fin' => now()->addDays(15)->format('Y-m-d\TH:i'),
            ]),
            'delegantOptions' => $this->delegationEligibleUsers(),
            'delegateOptions' => $this->delegateReceivers(),
            'directionOptions' => Direction::query()->where('actif', true)->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => Service::query()->where('actif', true)->orderBy('code')->get(['id', 'direction_id', 'code', 'libelle']),
        ]);
    }

    public function delegationsStore(
        StoreDelegationRequest $request,
        DelegationService $delegationService,
        WorkspaceNotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $delegation = $delegationService->create($request->validated(), $user);

        $notificationService->notifyDelegationCreated($delegation, $user);
        $this->recordAudit($request, 'delegations', 'create', $delegation, null, $delegation->toArray());

        return redirect()
            ->route('workspace.delegations.index')
            ->with('success', 'Delegation enregistree avec succès.');
    }

    public function delegationsCancel(
        CancelDelegationRequest $request,
        Delegation $delegation,
        DelegationService $delegationService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $result = $delegationService->cancel(
            $delegation,
            $user,
            (string) $request->validated('motif_annulation')
        );
        $this->recordAudit(
            $request,
            'delegations',
            'cancel',
            $result['delegation'],
            $result['before'],
            $result['delegation']->toArray()
        );

        return redirect()
            ->route('workspace.delegations.index')
            ->with('success', 'Délégation annulée.');
    }

    public function deletionRequestsIndex(Request $request, GovernanceQueueService $governanceQueueService): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return view(
            'workspace.governance.deletion-requests.index',
            $governanceQueueService->deletionRequests($user, $request->query())
        );
    }

    public function deletionRequestComplementStore(
        ResubmitDeletionRequestRequest $request,
        DeletionRequest $deletionRequest,
        DeletionRequestService $deletionRequestService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $before = $deletionRequest->toArray();
        $deletionRequest = $deletionRequestService->resubmitComplement(
            $deletionRequest,
            $user,
            (string) $request->validated('complement')
        );
        $this->recordAudit(
            $request,
            'deletion_requests',
            'complement_resubmitted',
            $deletionRequest,
            $before,
            $deletionRequest->toArray()
        );

        return redirect()
            ->route('workspace.deletion-requests.index', ['status' => DeletionRequest::STATUS_PENDING])
            ->with('success', 'Complément transmis pour une nouvelle instruction.');
    }

    /** @return Collection<int, User> */
    private function delegationEligibleUsers(): Collection
    {
        return User::query()
            ->whereIn('role', [User::ROLE_DIRECTION, User::ROLE_SERVICE])
            ->where('is_active', true)
            ->with(['direction:id,code,libelle', 'service:id,code,libelle'])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'direction_id', 'service_id']);
    }

    /** @return Collection<int, User> */
    private function delegateReceivers(): Collection
    {
        return User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_DIRECTION, User::ROLE_SERVICE])
            ->where('is_active', true)
            ->with(['direction:id,code,libelle', 'service:id,code,libelle'])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'direction_id', 'service_id']);
    }

    private function authorizeRetentionReader(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasAnyPermission('retention.read', 'retention.manage')) {
            abort(403, 'Accès non autorisé.');
        }

        return $user;
    }
}
