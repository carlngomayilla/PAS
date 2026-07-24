<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\Pas;
use App\Models\PlanningUnlockRequest;
use App\Models\Pta;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use App\Services\PlanningModificationLockService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlanningUnlockWebController extends Controller
{
    use AuthorizesPlanningScope;
    use RecordsAuditTrail;

    public function __construct(
        private readonly PlanningModificationLockService $locks
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $query = PlanningUnlockRequest::query()
            ->with([
                'requester:id,name,email,role',
                'reviewer:id,name,email,role',
                'transferredBy:id,name,email,role',
                'planifReviewer:id,name,email,role',
            ])
            ->orderByRaw("CASE WHEN status = 'soumise' THEN 0 ELSE 1 END")
            ->orderByDesc('id');

        $canReview = $this->locks->isUnlockReviewer($user);
        $canGivePlanifAvis = $this->locks->canGivePlanifAvis($user);

        if (! $canReview && ! $canGivePlanifAvis && ! $user->hasGlobalReadAccess()) {
            if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
                $query->where(function ($scopedQuery) use ($user): void {
                    $scopedQuery
                        ->where('direction_id', (int) $user->direction_id)
                        ->orWhere('requested_by', (int) $user->id);
                });
            } else {
                $query->where('requested_by', (int) $user->id);
            }
        }

        return view('workspace.planning-unlocks.index', [
            'rows' => $query->paginate(20)->withQueryString(),
            'canReview' => $canReview,
            'canGivePlanifAvis' => $canGivePlanifAvis,
            'currentUser' => $user,
        ]);
    }

    public function storePas(Request $request, Pas $pas): RedirectResponse
    {
        return $this->storeForTarget($request, $pas);
    }

    public function storePta(Request $request, Pta $pta): RedirectResponse
    {
        return $this->storeForTarget($request, $pta);
    }

    public function storeAction(Request $request, Action $action): RedirectResponse
    {
        return $this->storeForTarget($request, $action);
    }

    /**
     * Legacy route: controller roles can still use it to record an avis and transmit.
     */
    public function transferByDirecteur(Request $request, PlanningUnlockRequest $planningUnlockRequest): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->locks->canTransfer($user, $planningUnlockRequest)) {
            abort(403, 'Seul un controleur SCIQ/Planification peut transmettre cette demande.');
        }

        $validated = $request->validate([
            'planif_avis' => ['nullable', 'in:favorable,defavorable'],
            'transfer_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $planningUnlockRequest->toArray();
        $this->locks->transmitByController(
            $planningUnlockRequest,
            $user,
            (string) ($validated['planif_avis'] ?? PlanningUnlockRequest::AVIS_FAVORABLE),
            $validated['transfer_comment'] ?? null
        );
        $planningUnlockRequest->refresh();
        $this->recordAudit($request, 'planning_unlock', 'controller_transfer', $planningUnlockRequest, $before, $planningUnlockRequest->toArray());

        return redirect()
            ->route('workspace.planning-unlocks.index')
            ->with('success', 'Demande transmise pour decision.');
    }

    /**
     * Controller step: record SCIQ/Planification avis and keep the request available for decision.
     */
    public function reviewByPlanification(Request $request, PlanningUnlockRequest $planningUnlockRequest): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->locks->canGivePlanifAvis($user)) {
            abort(403, 'Seuls les controleurs SCIQ/Planification peuvent transmettre cette demande.');
        }

        $validated = $request->validate([
            'planif_avis' => ['required', 'in:favorable,defavorable'],
            'planif_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $planningUnlockRequest->toArray();
        $this->locks->recordPlanifAvis($planningUnlockRequest, $user, (string) $validated['planif_avis'], $validated['planif_comment'] ?? null);
        $planningUnlockRequest->refresh();
        $this->recordAudit($request, 'planning_unlock', 'controller_transfer', $planningUnlockRequest, $before, $planningUnlockRequest->toArray());

        return redirect()
            ->route('workspace.planning-unlocks.index')
            ->with('success', 'Avis du controleur enregistre. La demande est transmise pour decision.');
    }

    public function reviewByDg(Request $request, PlanningUnlockRequest $planningUnlockRequest): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->locks->isUnlockReviewer($user)) {
            abort(403, 'Seuls la DG, le SCIQ ou la Planification peuvent traiter une demande de deverrouillage.');
        }

        if (! $request->filled('review_comment') && $request->filled('planif_comment')) {
            $request->merge(['review_comment' => $request->input('planif_comment')]);
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:approuver,rejeter,defavorable'],
            'review_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $planningUnlockRequest->toArray();

        if ($validated['decision'] === PlanningUnlockRequest::DECISION_APPROUVER) {
            $comment = trim((string) ($validated['review_comment'] ?? ''));

            $this->locks->approve(
                $planningUnlockRequest,
                $user,
                $comment !== '' ? $comment : null
            );
        } else {
            $request->validate([
                'review_comment' => ['required', 'string', 'min:5', 'max:2000'],
            ]);

            $this->locks->reject($planningUnlockRequest, $user, (string) $validated['review_comment']);
        }

        $planningUnlockRequest->refresh();
        $this->recordAudit(
            $request,
            'planning_unlock',
            'unlock_decision',
            $planningUnlockRequest,
            $before,
            $planningUnlockRequest->toArray()
        );

        return redirect()
            ->route('workspace.planning-unlocks.index')
            ->with('success', 'Decision de deverrouillage enregistree.');
    }

    private function storeForTarget(Request $request, Model $target): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->locks->canRequestUnlock($user, $target)) {
            abort(403, 'Vous ne pouvez pas demander le deverrouillage de cet enregistrement.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'justificatif' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ]);

        $justificatifPath = null;
        if ($request->hasFile('justificatif')) {
            $stored = app(SecureJustificatifStorage::class)->store($request->file('justificatif'), 'unlock-requests/'.date('Y/m'));
            $justificatifPath = $stored['path'];
        }

        $unlockRequest = $this->locks->requestUnlock($target, $user, (string) $validated['reason'], $justificatifPath);
        $this->recordAudit(
            $request,
            'planning_unlock',
            'request_create',
            $unlockRequest,
            null,
            $unlockRequest->toArray()
        );

        return back()->with('success', 'Demande de modification transmise aux responsables SCIQ/Planification/DG.');
    }
}
