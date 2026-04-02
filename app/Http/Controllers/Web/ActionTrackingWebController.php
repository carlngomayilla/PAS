<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\ActionWeek;
use App\Models\Justificatif;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Governance\DelegationService;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\Security\SecureJustificatifStorage;
use App\Support\UiLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActionTrackingWebController extends Controller
{
    use AuthorizesPlanningScope;
    use FormatsWorkflowMessages;
    use RecordsAuditTrail;

    public function show(Request $request, Action $action, ActionTrackingService $trackingService): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        if (! $this->canReadAction($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        $trackingService->refreshActionMetrics($action);
        $action->load([
            'pta:id,pao_id,titre,direction_id,service_id,statut',
            'pta.direction:id,code,libelle',
            'pta.service:id,code,libelle',
            'pta.pao:id,pas_id,annee,titre,statut',
            'pta.pao.pas:id,titre,periode_debut,periode_fin,statut',
            'responsable:id,name,email,agent_matricule,agent_fonction,agent_telephone',
            'soumisPar:id,name,email',
            'evaluePar:id,name,email',
            'directionValidePar:id,name,email',
            'weeks' => fn ($q) => $q->with('saisiPar:id,name,email')->orderBy('numero_semaine'),
            'actionKpi',
            'justificatifs' => fn ($q) => $q->with('ajoutePar:id,name,email')->latest(),
            'actionLogs' => fn ($q) => $q->with('utilisateur:id,name,email')->latest()->limit(80),
        ]);

        return view('workspace.actions.suivi', [
            'action' => $action,
            'canTrackWeekly' => $this->canTrackWeekly($user, $action) && $this->isExecutionEditableByAgent($action),
            'canManageAction' => $this->canManageAction($user, $action),
            'canSubmitClosure' => $this->canSubmitClosure($user, $action) && $this->isExecutionEditableByAgent($action),
            'canReviewClosure' => $this->canReviewByChef($user, $action),
            'canReviewDirection' => $this->canReviewByDirection($user, $action),
        ]);
    }

    public function submitWeek(
        Request $request,
        Action $action,
        ActionWeek $actionWeek,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,responsable_id');
        if (! $this->canTrackWeekly($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Saisie'),
            ]);
        }

        if (! $this->isExecutionEditableByAgent($action)) {
            return back()->withErrors([
                'general' => 'Saisie gelee: action en cours de validation. Modifications autorisees uniquement apres rejet motive.',
            ]);
        }

        if ((int) $actionWeek->action_id !== (int) $action->id) {
            abort(404);
        }

        $rules = [
            'commentaire' => ['nullable', 'string'],
            'difficultes' => ['required', 'string'],
            'mesures_correctives' => ['required', 'string'],
            'justificatif' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ];

        if ($action->type_cible === 'quantitative') {
            $rules['quantite_realisee'] = ['required', 'numeric', 'min:0'];
        } else {
            $rules['taches_realisees'] = ['required', 'string'];
            $rules['avancement_estime'] = ['required', 'numeric', 'min:0', 'max:100'];
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate($rules);

        DB::transaction(function () use ($trackingService, $actionWeek, $validated, $request, $action, $user, $secureStorage): void {
            $trackingService->submitWeek($actionWeek, $validated, $user);

            $file = $request->file('justificatif');
            $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
            $trackingService->addActionJustificatif(
                $action,
                $actionWeek,
                'hebdomadaire',
                $storedFile['path'],
                $storedFile['nom_original'],
                $storedFile['mime_type'],
                $storedFile['taille_octets'],
                'Justificatif hebdomadaire',
                $user,
                $storedFile['est_chiffre']
            );
        });

        $this->recordAudit(
            $request,
            'action_week',
            'submit',
            $actionWeek,
            null,
            $actionWeek->fresh()?->toArray()
        );

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Semaine renseignee avec succes.');
    }

    public function closeAction(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        WorkspaceNotificationService $notificationService,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin,responsable_id');
        if (! $this->canSubmitClosure($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Soumission'),
            ]);
        }

        $currentValidationStatus = (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE);
        if (in_array($currentValidationStatus, [
            ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ], true)) {
            return back()->withErrors(['general' => 'Action deja soumise. En attente de validation hierarchique.']);
        }

        if ($currentValidationStatus === ActionTrackingService::VALIDATION_VALIDEE_DIRECTION) {
            return back()->withErrors(['general' => 'Action deja validee par la direction.']);
        }

        $hasExecutionJustificatif = $action->justificatifs()
            ->where('categorie', 'hebdomadaire')
            ->exists();
        if (! $hasExecutionJustificatif) {
            return back()->withErrors([
                'general' => 'Soumission impossible: aucun justificatif d execution trouve dans les periodes de suivi.',
            ]);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'date_fin_reelle' => ['required', 'date'],
            'rapport_final' => ['required', 'string'],
            'justificatif_final' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ]);

        if ($action->date_debut !== null && (string) $validated['date_fin_reelle'] < (string) $action->date_debut) {
            return back()->withErrors([
                'date_fin_reelle' => 'La date de fin reelle doit etre superieure ou egale a la date de debut.',
            ]);
        }

        $before = $action->toArray();

        DB::transaction(function () use ($trackingService, $action, $validated, $request, $user, $secureStorage): void {
            $trackingService->submitClosureForReview($action, $validated, $user);

            if ($request->hasFile('justificatif_final')) {
                $file = $request->file('justificatif_final');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'final',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif final de cloture transmis pour validation',
                    $user,
                    $storedFile['est_chiffre']
                );
            }
        });

        $action->refresh();
        $this->recordAudit($request, 'action', 'submit_for_validation', $action, $before, $action->toArray());
        $notificationService->notifyActionSubmittedToChef($action, $user);

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Action soumise au chef de service pour evaluation.');
    }

    public function comment(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,responsable_id');
        if (! $this->canReadAction($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        /** @var array{message:string} $validated */
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:3000'],
        ]);

        $trackingService->addDiscussionEntry(
            $action,
            $validated['message'],
            'commentaire',
            'info',
            [],
            $user
        );

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Commentaire enregistre.');
    }

    public function reviewClosure(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        WorkspaceNotificationService $notificationService,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin');
        if (! $this->canReviewByChef($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Validation'),
            ]);
        }

        if ((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE) !== ActionTrackingService::VALIDATION_SOUMISE_CHEF) {
            return back()->withErrors(['general' => 'Cette action n est pas en attente de validation chef de service.']);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'decision_validation' => ['required', Rule::in(['valider', 'rejeter'])],
            'evaluation_note' => ['required', 'numeric', 'min:0', 'max:100'],
            'evaluation_commentaire' => ['required', 'string'],
            'validation_sans_correction' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
            'justificatif_evaluation' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ]);

        $validated['validation_sans_correction'] = $request->filled('validation_sans_correction')
            ? (bool) $request->boolean('validation_sans_correction')
            : null;

        $before = $action->toArray();

        DB::transaction(function () use ($trackingService, $action, $validated, $request, $user, $secureStorage): void {
            $trackingService->reviewClosureByChef($action, $validated, $user);

            if ($request->hasFile('justificatif_evaluation')) {
                $file = $request->file('justificatif_evaluation');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'evaluation_chef',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif de revue chef de service',
                    $user,
                    $storedFile['est_chiffre']
                );
            }
        });

        $action->refresh();
        $this->recordAudit($request, 'action', 'review_closure', $action, $before, $action->toArray());

        $decision = (string) ($validated['decision_validation'] ?? 'rejeter');
        $notificationService->notifyActionReviewedByChef($action, $decision === 'valider', $user);

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', $decision === 'valider'
                ? 'Action validee par le chef de service et transmise a la direction.'
                : 'Action rejetee. L agent peut mettre a jour et resoumettre.');
    }

    public function reviewClosureByDirection(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        WorkspaceNotificationService $notificationService,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin');
        if (! $this->canReviewByDirection($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Validation'),
            ]);
        }

        if ((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE) !== ActionTrackingService::VALIDATION_VALIDEE_CHEF) {
            return back()->withErrors(['general' => 'Cette action n est pas en attente de validation direction.']);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'decision_validation' => ['required', Rule::in(['valider', 'rejeter'])],
            'evaluation_note' => ['required', 'numeric', 'min:0', 'max:100'],
            'evaluation_commentaire' => ['required', 'string'],
            'justificatif_evaluation_direction' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ]);

        $before = $action->toArray();

        DB::transaction(function () use ($trackingService, $action, $validated, $request, $user, $secureStorage): void {
            $trackingService->reviewClosureByDirection($action, $validated, $user);

            if ($request->hasFile('justificatif_evaluation_direction')) {
                $file = $request->file('justificatif_evaluation_direction');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'evaluation_direction',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif de revue direction',
                    $user,
                    $storedFile['est_chiffre']
                );
            }
        });

        $action->refresh();
        $this->recordAudit($request, 'action', 'review_direction', $action, $before, $action->toArray());

        $decision = (string) ($validated['decision_validation'] ?? 'rejeter');
        $notificationService->notifyActionReviewedByDirection($action, $decision === 'valider', $user);

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', $decision === 'valider'
                ? 'Action validee par la direction. Elle est maintenant prise en compte dans les statistiques.'
                : 'Action rejetee par la direction. Retour au chef de service.');
    }

    public function downloadJustificatif(
        Request $request,
        Action $action,
        Justificatif $justificatif,
        SecureJustificatifStorage $secureStorage
    ): StreamedResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        if (! $this->canReadAction($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ((string) $justificatif->justifiable_type !== Action::class
            || (int) $justificatif->justifiable_id !== (int) $action->id
        ) {
            abort(404);
        }

        return $secureStorage->download($justificatif);
    }

    private function canTrackWeekly(User $user, Action $action): bool
    {
        return $user->isAgent()
            && (int) $action->responsable_id === (int) $user->id;
    }

    private function canReadAction(User $user, Action $action): bool
    {
        if ($user->isAgent()) {
            return (int) $action->responsable_id === (int) $user->id;
        }

        $delegationService = app(DelegationService::class);
        if ($delegationService->canReviewServiceAction($user, (int) $action->pta?->direction_id, (int) $action->pta?->service_id)) {
            return true;
        }
        if ($delegationService->canReviewDirectionAction($user, (int) $action->pta?->direction_id)) {
            return true;
        }
        if ($user->hasDelegatedDirectionScope((int) $action->pta?->direction_id, 'planning_write')) {
            return true;
        }
        if ($user->hasDelegatedServiceScope((int) $action->pta?->direction_id, (int) $action->pta?->service_id, 'planning_write')) {
            return true;
        }

        if (! $this->canReadDirection($user, (int) $action->pta?->direction_id)) {
            return false;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && (int) $user->service_id !== (int) $action->pta?->service_id) {
            return false;
        }

        return true;
    }

    private function canManageAction(User $user, Action $action): bool
    {
        return ! $user->isAgent()
            && $this->canWriteService(
                $user,
                (int) $action->pta?->direction_id,
                (int) $action->pta?->service_id
            );
    }

    private function canSubmitClosure(User $user, Action $action): bool
    {
        return $user->hasRole(User::ROLE_AGENT)
            && (int) $action->responsable_id === (int) $user->id;
    }

    private function canReviewByChef(User $user, Action $action): bool
    {
        return ($user->hasRole(User::ROLE_SERVICE)
                && $this->canManageAction($user, $action))
            || app(DelegationService::class)->canReviewServiceAction(
                $user,
                (int) $action->pta?->direction_id,
                (int) $action->pta?->service_id
            );
    }

    private function canReviewByDirection(User $user, Action $action): bool
    {
        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            return (int) $user->direction_id === (int) $action->pta?->direction_id;
        }

        return app(DelegationService::class)->canReviewDirectionAction(
            $user,
            (int) $action->pta?->direction_id
        );
    }

    private function isExecutionEditableByAgent(Action $action): bool
    {
        $status = (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE);

        return in_array($status, [
            ActionTrackingService::VALIDATION_NON_SOUMISE,
            ActionTrackingService::VALIDATION_REJETEE_CHEF,
            ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
        ], true);
    }
}
