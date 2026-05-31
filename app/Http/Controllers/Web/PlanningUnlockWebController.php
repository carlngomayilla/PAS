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
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $query = PlanningUnlockRequest::query()
            ->with(['requester:id,name,email,role', 'reviewer:id,name,email,role'])
            ->orderByRaw("CASE WHEN status = 'soumise' THEN 0 ELSE 1 END")
            ->orderByDesc('id');

        if (! $this->locks->isUnlockReviewer($user) && ! $user->hasGlobalReadAccess()) {
            $query->where('requested_by', (int) $user->id);
        }

        return view('workspace.planning-unlocks.index', [
            'rows' => $query->paginate(20)->withQueryString(),
            'canReview' => $this->locks->isUnlockReviewer($user),
            'canGivePlanifAvis' => $this->locks->canGivePlanifAvis($user),
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
     * Circuit V2 — étape directeur : transfère la demande à Planif + DG.
     */
    public function transferByDirecteur(Request $request, PlanningUnlockRequest $planningUnlockRequest): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->locks->canTransfer($user, $planningUnlockRequest)) {
            abort(403, 'Seul le directeur de la direction concernée peut transférer cette demande.');
        }

        $validated = $request->validate([
            'transfer_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $planningUnlockRequest->toArray();
        $this->locks->transferByDirecteur($planningUnlockRequest, $user, $validated['transfer_comment'] ?? null);
        $planningUnlockRequest->refresh();
        $this->recordAudit($request, 'planning_unlock', 'directeur_transfer', $planningUnlockRequest, $before, $planningUnlockRequest->toArray());

        return redirect()
            ->route('workspace.planning-unlocks.index')
            ->with('success', 'Demande transmise à la Planification et à la DG.');
    }

    /**
     * Circuit V2 — étape planification : avis consultatif.
     */
    public function reviewByPlanification(Request $request, PlanningUnlockRequest $planningUnlockRequest): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->locks->canGivePlanifAvis($user)) {
            abort(403, 'Seule la Planification peut émettre un avis.');
        }

        $validated = $request->validate([
            'planif_avis' => ['required', 'in:favorable,defavorable'],
            'planif_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $planningUnlockRequest->toArray();
        $this->locks->recordPlanifAvis($planningUnlockRequest, $user, (string) $validated['planif_avis'], $validated['planif_comment'] ?? null);
        $planningUnlockRequest->refresh();
        $this->recordAudit($request, 'planning_unlock', 'planif_avis', $planningUnlockRequest, $before, $planningUnlockRequest->toArray());

        return redirect()
            ->route('workspace.planning-unlocks.index')
            ->with('success', 'Avis de la Planification enregistré. La DG va statuer.');
    }

    public function reviewByDg(Request $request, PlanningUnlockRequest $planningUnlockRequest): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->locks->isUnlockReviewer($user)) {
            abort(403, 'Seul le DG peut traiter une demande de deverrouillage.');
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:approuver,rejeter'],
            'review_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $planningUnlockRequest->toArray();

        if ($validated['decision'] === PlanningUnlockRequest::DECISION_APPROUVER) {
            $this->locks->approve(
                $planningUnlockRequest,
                $user,
                ($value = trim((string) ($validated['review_comment'] ?? ''))) !== '' ? $value : null
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
            'dg_decision',
            $planningUnlockRequest,
            $before,
            $planningUnlockRequest->toArray()
        );

        return redirect()
            ->route('workspace.planning-unlocks.index')
            ->with('success', 'Décision DG enregistrée.');
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

        // Justificatif à l'appui (optionnel mais recommandé), stocké chiffré.
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

        return back()->with('success', 'Demande de modification transmise au directeur pour transfert à la Planification et la DG.');
    }
}
