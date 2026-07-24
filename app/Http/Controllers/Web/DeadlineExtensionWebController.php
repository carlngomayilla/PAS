<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplyDeadlineExtensionRequest;
use App\Http\Requests\ResubmitDeadlineExtensionRequest;
use App\Http\Requests\ReviewDeadlineExtensionByChefRequest;
use App\Http\Requests\ReviewDeadlineExtensionByControllerRequest;
use App\Http\Requests\ReviewDeadlineExtensionFinalRequest;
use App\Http\Requests\StoreDeadlineExtensionRequest;
use App\Models\Action;
use App\Models\DeadlineExtensionRequest;
use App\Models\Justificatif;
use App\Models\User;
use App\Services\DeadlineExtensionQueueService;
use App\Services\DocumentPolicySettings;
use App\Services\Security\SecureJustificatifStorage;
use App\Services\Workflow\DeadlineExtensionWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeadlineExtensionWebController extends Controller
{
    use RecordsAuditTrail;

    public function index(Request $request, DeadlineExtensionQueueService $queueService): View
    {
        $user = $request->user();
        if (! $user instanceof User || ! (bool) ($user->is_active ?? true)) {
            abort(403);
        }

        $data = $queueService->forUser(
            $user,
            (string) $request->string('vue', 'a_traiter'),
            (string) $request->string('recherche')
        );

        return view('workspace.deadline-extensions.index', $data + [
            'search' => (string) $request->string('recherche'),
        ]);
    }

    public function show(Request $request, DeadlineExtensionRequest $deadlineExtensionRequest): View
    {
        $this->authorizeRequestAccess($request, $deadlineExtensionRequest);
        $deadlineExtensionRequest->loadMissing([
            'action.pta:id,direction_id,service_id,titre',
            'sousAction:id,action_id,libelle',
            'requestedBy:id,name,role',
            'chefReviewedBy:id,name',
            'sciqReviewedBy:id,name',
            'finalDecidedBy:id,name',
            'appliedBy:id,name',
        ]);

        $user = $request->user();
        $action = $deadlineExtensionRequest->action;
        if (! $user instanceof User || ! $action instanceof Action) {
            abort(404);
        }

        return view('workspace.deadline-extensions.show', [
            'deadlineRequest' => $deadlineExtensionRequest,
            'canResubmit' => (int) $deadlineExtensionRequest->requested_by === (int) $user->id
                && $deadlineExtensionRequest->status === DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE,
            'canReviewByChef' => in_array((string) $deadlineExtensionRequest->status, [
                DeadlineExtensionRequest::STATUS_SOUMISE,
                DeadlineExtensionRequest::STATUS_EN_ANALYSE,
            ], true) && $user->can('reviewDeadlineExtensionByChef', $action),
            'canReviewByController' => $deadlineExtensionRequest->status === DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE
                && $user->can('reviewDeadlineExtensionByController', $action),
            'canReviewFinal' => in_array((string) $deadlineExtensionRequest->status, [
                DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE,
                DeadlineExtensionRequest::STATUS_TRANSMISE_DG,
            ], true) && $user->can('reviewDeadlineExtensionFinal', $action),
            'canApply' => $deadlineExtensionRequest->status === DeadlineExtensionRequest::STATUS_APPROUVEE
                && $user->can('applyDeadlineExtension', $action),
            'documentAccept' => app(DocumentPolicySettings::class)->acceptAttribute(),
        ]);
    }

    public function downloadAttachment(
        Request $request,
        DeadlineExtensionRequest $deadlineExtensionRequest,
        SecureJustificatifStorage $secureStorage
    ): StreamedResponse {
        $this->authorizeRequestAccess($request, $deadlineExtensionRequest);

        $metadata = is_array($deadlineExtensionRequest->metadata) ? $deadlineExtensionRequest->metadata : [];
        $justificatif = $this->attachmentJustificatif([
            'chemin_stockage' => $deadlineExtensionRequest->attachment_path,
            'nom_original' => $deadlineExtensionRequest->attachment_name ?: 'piece-report',
            'mime_type' => $deadlineExtensionRequest->attachment_mime,
            'taille_octets' => $deadlineExtensionRequest->attachment_size,
            'est_chiffre' => (bool) ($metadata['encrypted_attachment'] ?? false),
        ]);

        return $secureStorage->download($justificatif);
    }

    public function downloadRevisionAttachment(
        Request $request,
        DeadlineExtensionRequest $deadlineExtensionRequest,
        int $revision,
        SecureJustificatifStorage $secureStorage
    ): StreamedResponse {
        $this->authorizeRequestAccess($request, $deadlineExtensionRequest);

        $metadata = is_array($deadlineExtensionRequest->metadata) ? $deadlineExtensionRequest->metadata : [];
        $history = is_array($metadata['revision_history'] ?? null) ? $metadata['revision_history'] : [];
        $attachment = $history[$revision] ?? null;
        if (! is_array($attachment) || trim((string) ($attachment['previous_attachment_path'] ?? '')) === '') {
            abort(404);
        }

        $justificatif = $this->attachmentJustificatif([
            'chemin_stockage' => $attachment['previous_attachment_path'],
            'nom_original' => ($attachment['previous_attachment_name'] ?? null) ?: 'piece-report-version-'.($revision + 1),
            'mime_type' => $attachment['previous_attachment_mime'] ?? null,
            'taille_octets' => $attachment['previous_attachment_size'] ?? null,
            'est_chiffre' => (bool) ($attachment['previous_attachment_encrypted'] ?? false),
        ]);

        return $secureStorage->download($justificatif);
    }

    public function store(
        StoreDeadlineExtensionRequest $request,
        Action $action,
        DeadlineExtensionWorkflowService $workflow,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $file = $request->file('piece_justificative');
        if (! $file instanceof UploadedFile) {
            abort(422, 'Piece justificative obligatoire.');
        }

        $storedFile = $secureStorage->store($file, 'reports-echeance/'.date('Y/m'));
        $deadlineExtensionRequest = $workflow->submit(
            $action,
            $request->safe()->except(['piece_justificative']),
            $user,
            $storedFile
        );

        $this->recordAudit($request, 'reports_echeance', 'submit', $deadlineExtensionRequest, null, $deadlineExtensionRequest->toArray());

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Demande de report d echeance soumise.');
    }

    public function reviewByChef(
        ReviewDeadlineExtensionByChefRequest $request,
        DeadlineExtensionRequest $deadlineExtensionRequest,
        DeadlineExtensionWorkflowService $workflow
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $before = $deadlineExtensionRequest->toArray();
        $reviewed = $workflow->reviewByChef($deadlineExtensionRequest, $request->validated(), $user);
        $this->recordAudit($request, 'reports_echeance', 'chef_review', $reviewed, $before, $reviewed->toArray());

        return $this->redirectAfterWorkflow($request, $reviewed, 'Avis du chef de service enregistré.');
    }

    public function resubmit(
        ResubmitDeadlineExtensionRequest $request,
        DeadlineExtensionRequest $deadlineExtensionRequest,
        DeadlineExtensionWorkflowService $workflow,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $file = $request->file('piece_justificative');
        if (! $file instanceof UploadedFile) {
            abort(422, 'Piece justificative obligatoire.');
        }

        $storedFile = $secureStorage->store($file, 'reports-echeance/'.date('Y/m'));
        $before = $deadlineExtensionRequest->toArray();
        $resubmitted = $workflow->resubmit(
            $deadlineExtensionRequest,
            $request->safe()->except(['piece_justificative']),
            $user,
            $storedFile
        );
        $this->recordAudit($request, 'reports_echeance', 'resubmit', $resubmitted, $before, $resubmitted->toArray());

        return $this->redirectAfterWorkflow($request, $resubmitted, 'Complement ajoute et demande de report retransmise.');
    }

    public function reviewByController(
        ReviewDeadlineExtensionByControllerRequest $request,
        DeadlineExtensionRequest $deadlineExtensionRequest,
        DeadlineExtensionWorkflowService $workflow
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $before = $deadlineExtensionRequest->toArray();
        $reviewed = $workflow->reviewByController($deadlineExtensionRequest, $request->validated(), $user);
        $this->recordAudit($request, 'reports_echeance', 'controller_review', $reviewed, $before, $reviewed->toArray());

        return $this->redirectAfterWorkflow($request, $reviewed, 'Avis du contrôleur enregistré.');
    }

    public function reviewFinal(
        ReviewDeadlineExtensionFinalRequest $request,
        DeadlineExtensionRequest $deadlineExtensionRequest,
        DeadlineExtensionWorkflowService $workflow
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $before = $deadlineExtensionRequest->toArray();
        $reviewed = $workflow->reviewFinal($deadlineExtensionRequest, $request->validated(), $user);
        $this->recordAudit($request, 'reports_echeance', 'final_decision', $reviewed, $before, $reviewed->toArray());

        return $this->redirectAfterWorkflow(
            $request,
            $reviewed,
            'Décision finale enregistrée. La date reste inchangée jusqu’à son application par un contrôleur.'
        );
    }

    public function apply(
        ApplyDeadlineExtensionRequest $request,
        DeadlineExtensionRequest $deadlineExtensionRequest,
        DeadlineExtensionWorkflowService $workflow
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $before = $deadlineExtensionRequest->toArray();
        $applied = $workflow->applyApprovedDeadline($deadlineExtensionRequest, $user);
        $this->recordAudit($request, 'reports_echeance', 'deadline_applied', $applied, $before, $applied->toArray());

        return $this->redirectAfterWorkflow(
            $request,
            $applied,
            'Nouvelle échéance appliquée conformément à la décision finale.'
        );
    }

    private function authorizeRequestAccess(
        Request $request,
        DeadlineExtensionRequest $deadlineExtensionRequest
    ): void {
        $user = $request->user();
        $deadlineExtensionRequest->loadMissing('action');
        $action = $deadlineExtensionRequest->action;
        $canAccess = $user instanceof User && $action instanceof Action && (
            (int) $deadlineExtensionRequest->requested_by === (int) $user->id
            || $user->can('view', $action)
            || $user->can('reviewDeadlineExtensionByChef', $action)
            || $user->can('reviewDeadlineExtensionByController', $action)
            || $user->can('reviewDeadlineExtensionFinal', $action)
            || $user->can('applyDeadlineExtension', $action)
        );
        if (! $canAccess) {
            abort(403);
        }
    }

    private function redirectAfterWorkflow(
        Request $request,
        DeadlineExtensionRequest $deadlineExtensionRequest,
        string $message
    ): RedirectResponse {
        if ($request->string('return_to')->toString() === 'report_detail') {
            return redirect()
                ->route('workspace.deadline-extension.show', $deadlineExtensionRequest)
                ->with('success', $message);
        }

        return redirect()
            ->route('workspace.actions.suivi', $deadlineExtensionRequest->action)
            ->with('success', $message);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attachmentJustificatif(array $attributes): Justificatif
    {
        $justificatif = new Justificatif;
        $justificatif->forceFill($attributes);

        return $justificatif;
    }
}
