<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\Justificatif;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\DocumentPolicySettings;
use App\Services\DynamicReferentialSettings;
use App\Services\Governance\DelegationService;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\PlanningModificationLockService;
use App\Services\Security\SecureJustificatifStorage;
use App\Services\Workflow\ActionWorkflowService;
use App\Services\WorkflowSettings;
use App\Support\UiLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller de suivi des actions — version réduite après suppression du workflow
 * opérationnel (2026-05-31). Les méthodes restantes couvrent :
 *
 *  - Page de suivi en lecture seule (show)
 *  - Fil de discussion / commentaires (comment)
 *  - Workflow financement DAF → DG (préservé)
 *  - Téléchargement / preview des justificatifs
 *
 * Les méthodes supprimées (updateSubAction, updateQuantitativeProgress,
 * reviewSubAction, reviewClosure, signalAnomaly, resolveAnomaly,
 * storeDeadlineExtensionRequest, reviewDeadlineExtensionBy*) sont à
 * reconstruire from scratch lors de la refonte du workflow opérationnel.
 */
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
            'pao:id,pas_id,annee,titre,statut,objectif_operationnel,echeance',
            'pao.pas:id,titre,periode_debut,periode_fin,statut',
            'objectifOperationnel:id,pao_id,libelle,description,echeance',
            'responsable:id,name,email,agent_matricule,agent_fonction,agent_telephone',
            'responsables:id,name,email,agent_matricule,agent_fonction,agent_telephone',
            'financementDafPar:id,name,email',
            'financementDgPar:id,name,email',
            'sousActions' => fn ($q) => $q->with([
                'agent:id,name,email',
            ])->orderBy('id'),
            'actionKpi',
            'justificatifs' => fn ($q) => $q->with([
                'ajoutePar:id,name,email',
            ])->latest(),
            'actionLogs' => fn ($q) => $q->with('utilisateur:id,name,email')->latest()->limit(80),
        ]);

        // ── Workflow V2 : performances + permissions de suivi ──────────────────
        $calculator = app(\App\Services\Workflow\ActionPerformanceCalculator::class);
        $provisional = $calculator->provisionalPerformance($action);
        $official = (float) ($action->official_progress_percent ?? 0);
        $lockService = app(PlanningModificationLockService::class);
        $isActionModificationLocked = $lockService->isLocked($action);
        $canRequestActionUnlock = $isActionModificationLocked && $lockService->canRequestUnlock($user, $action);
        $canProcessActionUnlock = $isActionModificationLocked
            && ($lockService->isUnlockReviewer($user) || $lockService->canGivePlanifAvis($user));

        return view('workspace.actions.suivi', [
            'action' => $action,
            // Workflow V2
            'v2ProvisionalPerf' => $provisional,
            'v2OfficialPerf' => $official,
            'v2PerfStatus' => $calculator->performanceStatus($provisional),
            'v2TemporalStatus' => $calculator->temporalStatus($action),
            'canTrackActionV2' => $this->canTrackAction($user, $action) && ! $action->isComposee(),
            'canTrackSubActionsV2' => $action->isComposee() && ($action->isResponsible($user) || $user->isAgent()),
            'canReviewByChefV2' => $this->canReviewByChef($user, $action),
            // Le responsable VOIT toujours le formulaire (figé si non éditable),
            // tant que l'action est paramétrée. L'édition dépend de canTrackActionV2.
            'v2ActionResponsible' => $action->isResponsible($user)
                && (string) ($action->statut_parametrage ?? '') !== 'a_parametrer'
                && ! $action->isComposee(),
            'v2ActionFrozen' => ! $this->isExecutionEditable($action),
            // Compat anciennes clés (encore lues par certaines parties de la vue).
            'canTrackWeekly' => false,
            'canSubmitAssignedSubActions' => false,
            'canManageAction' => $this->canManageAction($user, $action),
            'isActionModificationLocked' => $isActionModificationLocked,
            'canRequestActionUnlock' => $canRequestActionUnlock,
            'canProcessActionUnlock' => $canProcessActionUnlock,
            'canReviewClosure' => false,
            'canRequestDeadlineExtension' => false,
            'canReviewDeadlineExtensionBySciq' => false,
            'canReviewDeadlineExtensionByDg' => false,
            'canReviewFinancingByDaf' => $this->canReviewFinancingByDaf($user, $action),
            'canReviewFinancingByDg' => $this->canReviewFinancingByDg($user, $action),
            'canSignalControlAnomaly' => false,
            'canResolveControlAnomaly' => false,
            'workflowConfig' => $this->workflowSettings()->actionValidationSummary(),
            'justificatifCategoryLabels' => app(DynamicReferentialSettings::class)->justificatifCategoryLabels(),
            'alertLevelLabels' => app(DynamicReferentialSettings::class)->alertLevelLabels(),
            'validationStatusLabels' => app(DynamicReferentialSettings::class)->validationStatusLabels(),
            'documentAccept' => app(DocumentPolicySettings::class)->acceptAttribute(),
        ]);
    }

    public function comment(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        WorkspaceNotificationService $notificationService
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
        $notificationService->notifyActionCommentAdded($action, $validated['message'], $user);

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Commentaire enregistré.');
    }

    // ── WORKFLOW V2 — suivi opérationnel (cf. docs/WORKFLOW-SUIVI-V2.md) ───────

    /**
     * Suivi d'une action SIMPLE (quantitative ou non quantitative).
     * tracking_action=save → brouillon ; tracking_action=submit → soumission chef.
     */
    public function updateActionProgress(
        Request $request,
        Action $action,
        ActionWorkflowService $workflow,
        SecureJustificatifStorage $secureStorage,
        WorkspaceNotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,responsable_id');
        if (! $this->canTrackAction($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ($action->isComposee()) {
            return back()->withErrors(['general' => 'Cette action est composée : le suivi se fait par sous-action.']);
        }

        $intent = (string) $request->input('tracking_action', 'save') === 'submit' ? 'submit' : 'save';

        $validated = $request->validate([
            'quantite_realisee' => ['nullable', 'numeric', 'min:0'],
            'commentaire' => ['nullable', 'string', 'max:5000'],
            'difficulte' => ['nullable', 'string', 'max:5000'],
            'justificatif' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ]);

        $hasNewProof = $request->hasFile('justificatif');
        $before = $action->toArray();

        try {
            DB::transaction(function () use ($workflow, $action, $validated, $intent, $user, $request, $secureStorage, $hasNewProof): void {
                if ($hasNewProof) {
                    $stored = $secureStorage->store($request->file('justificatif'), 'justificatifs/'.date('Y/m'));
                    Justificatif::query()->create([
                        'justifiable_type' => Action::class,
                        'justifiable_id' => $action->id,
                        'categorie' => $action->isQuantitative() ? 'execution_quantitative' : 'execution_non_quantitative',
                        'nom_original' => $stored['nom_original'],
                        'chemin_stockage' => $stored['path'],
                        'est_chiffre' => $stored['est_chiffre'],
                        'mime_type' => $stored['mime_type'],
                        'taille_octets' => $stored['taille_octets'],
                        'description' => 'Justificatif de suivi action',
                        'ajoute_par' => $user->id,
                    ]);
                    $action->load('justificatifs');
                }

                $workflow->recordActionProgress($action, [
                    'quantite_realisee' => $validated['quantite_realisee'] ?? null,
                    'commentaire' => $validated['commentaire'] ?? null,
                    'difficulte' => $validated['difficulte'] ?? null,
                ], $user);

                if ($intent === 'submit') {
                    $workflow->submitAction($action->fresh(['justificatifs']), [
                        'commentaire' => $validated['commentaire'] ?? null,
                        'difficulte' => $validated['difficulte'] ?? null,
                        'has_new_proof' => $hasNewProof,
                    ], $user);
                }
            });
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        if ($intent === 'submit') {
            $submittedAction = $action->fresh()->loadMissing('pta:id,direction_id,service_id');
            $this->recordAudit($request, 'action', 'submit_validation_chef', $submittedAction, $before, [
                ...$submittedAction->toArray(),
                'audit_context' => [
                    'intervention_created' => true,
                    'task_type' => 'validation_chef',
                    'target_route' => route('workspace.actions.suivi', $submittedAction).'#action-validation',
                ],
            ]);
            $notificationService->notifyActionSubmittedToChef($submittedAction, $user);
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', $intent === 'submit'
                ? 'Action soumise au chef de service pour validation.'
                : 'Avancement enregistré. Vous pourrez soumettre quand vous serez prêt.');
    }

    /**
     * Suivi d'une SOUS-ACTION (action composée).
     */
    public function updateSubActionProgress(
        Request $request,
        Action $action,
        SousAction $sousAction,
        ActionWorkflowService $workflow,
        SecureJustificatifStorage $secureStorage,
        WorkspaceNotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,responsable_id');
        if ((int) $sousAction->action_id !== (int) $action->id) {
            abort(404);
        }
        if (! $this->canTrackSubAction($user, $action, $sousAction)) {
            abort(403, 'Acces non autorise.');
        }

        $intent = (string) $request->input('tracking_action', 'save') === 'submit' ? 'submit' : 'save';

        $validated = $request->validate([
            'quantite_realisee' => ['nullable', 'numeric', 'min:0'],
            'resultat_obtenu' => ['nullable', 'string', 'max:5000'],
            'commentaire' => ['nullable', 'string', 'max:5000'],
            'difficulte' => ['nullable', 'string', 'max:5000'],
            'justificatif' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ]);

        $hasNewProof = $request->hasFile('justificatif');
        $beforeSubAction = $sousAction->toArray();

        try {
            DB::transaction(function () use ($workflow, $action, $sousAction, $validated, $intent, $user, $request, $secureStorage, $hasNewProof): void {
                if ($hasNewProof) {
                    $stored = $secureStorage->store($request->file('justificatif'), 'justificatifs/'.date('Y/m'));
                    Justificatif::query()->create([
                        'justifiable_type' => Action::class,
                        'justifiable_id' => $sousAction->action_id,
                        'sous_action_id' => $sousAction->id,
                        'categorie' => 'sous_action',
                        'nom_original' => $stored['nom_original'],
                        'chemin_stockage' => $stored['path'],
                        'est_chiffre' => $stored['est_chiffre'],
                        'mime_type' => $stored['mime_type'],
                        'taille_octets' => $stored['taille_octets'],
                        'description' => 'Justificatif de sous-action',
                        'ajoute_par' => $user->id,
                    ]);
                    $sousAction->load('justificatifs');
                }

                $workflow->recordSubActionProgress($sousAction, [
                    'quantite_realisee' => $validated['quantite_realisee'] ?? null,
                    'resultat_obtenu' => $validated['resultat_obtenu'] ?? null,
                    'commentaire' => $validated['commentaire'] ?? null,
                ], $user);

                if ($intent === 'submit') {
                    $workflow->submitSubAction($sousAction->fresh(['justificatifs']), [
                        'commentaire' => $validated['commentaire'] ?? null,
                        'difficulte' => $validated['difficulte'] ?? null,
                        'has_new_proof' => $hasNewProof,
                    ], $user);
                }

                $workflow->refreshCompositeParent($action->fresh(['sousActions']), $user);
            });
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        if ($intent === 'submit') {
            $submittedSubAction = $sousAction->fresh();
            $submittedAction = $action->fresh()->loadMissing('pta:id,direction_id,service_id');
            $this->recordAudit($request, 'action', 'submit_sub_action_validation_chef', $submittedSubAction, $beforeSubAction, [
                ...$submittedSubAction->toArray(),
                'audit_context' => [
                    'intervention_created' => true,
                    'task_type' => 'validation_sous_action_chef',
                    'parent_action_id' => (int) $submittedAction->id,
                    'target_route' => route('workspace.actions.suivi', $submittedAction).'#action-weeks',
                ],
            ]);
            $notificationService->notifySubActionCompleted(
                $submittedAction,
                $submittedSubAction,
                $user
            );
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', $intent === 'submit'
                ? 'Sous-action soumise au chef de service.'
                : 'Sous-action enregistrée en brouillon.');
    }

    /**
     * Décision du chef de service : valider ou renvoyer pour correction.
     * Gère action simple OU sous-action (selon présence de sous_action_id).
     */
    public function reviewItem(
        Request $request,
        Action $action,
        ActionWorkflowService $workflow,
        WorkspaceNotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,responsable_id');
        if (! $this->canReviewByChef($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['valider', 'rejeter'])],
            'sous_action_id' => ['nullable', 'integer', 'exists:sous_actions,id'],
            'motif' => ['nullable', 'string', 'max:5000'],
        ]);

        $approve = $validated['decision'] === 'valider';
        if (! $approve && trim((string) ($validated['motif'] ?? '')) === '') {
            return back()->withErrors(['motif' => 'Le motif est obligatoire en cas de renvoi pour correction.']);
        }

        $source = (string) $request->input('source', '');
        $subActionId = $validated['sous_action_id'] ?? null;
        if ($subActionId !== null) {
            $sousAction = SousAction::query()->whereKey((int) $subActionId)->where('action_id', $action->id)->firstOrFail();
            $before = $sousAction->toArray();
            $workflow->reviewSubAction($sousAction, $approve, $validated['motif'] ?? null, $user);
            $reviewed = $sousAction->fresh();
            $this->recordAudit($request, 'action', $approve ? 'review_sub_action_validate' : 'review_sub_action_reject', $reviewed, $before, [
                ...$reviewed->toArray(),
                'audit_context' => [
                    'intervention_processed' => true,
                    'source' => $source === 'personal_tasks' ? 'personal_tasks' : 'action_tracking',
                    'parent_action_id' => (int) $action->id,
                    'decision' => $approve ? 'valider' : 'rejeter',
                    'motif' => $validated['motif'] ?? null,
                ],
            ]);
        } else {
            $before = $action->toArray();
            $workflow->reviewAction($action, $approve, $validated['motif'] ?? null, $user);
            $reviewed = $action->fresh();
            $this->recordAudit($request, 'action', $approve ? 'review_action_validate' : 'review_action_reject', $reviewed, $before, [
                ...$reviewed->toArray(),
                'audit_context' => [
                    'intervention_processed' => true,
                    'source' => $source === 'personal_tasks' ? 'personal_tasks' : 'action_tracking',
                    'decision' => $approve ? 'valider' : 'rejeter',
                    'motif' => $validated['motif'] ?? null,
                ],
            ]);
        }

        $notificationService->notifyActionReviewedByChef($action->fresh()->loadMissing('pta:id,direction_id,service_id'), $approve, $user);

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', $approve ? 'Validation enregistrée.' : 'Renvoi pour correction enregistré.');
    }

    public function reviewFinancingByDaf(
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

        $action->loadMissing('pta:id,direction_id,service_id,statut,responsable_id');
        if (! $this->canReviewFinancingByDaf($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if (in_array((string) $action->pta?->statut, ['cloture', 'archive'], true)) {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Financement'),
            ]);
        }

        $currentStatus = $action->financementStatus();
        if (! in_array($currentStatus, [Action::FINANCEMENT_A_TRAITER_DAF, Action::FINANCEMENT_REFUSE_DG], true)) {
            return back()->withErrors(['general' => 'Ce financement n est pas en attente de traitement DAF.']);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate($this->financeDafValidationRules($request));

        $before = $action->toArray();
        DB::transaction(function () use ($trackingService, $action, $validated, $request, $user, $secureStorage): void {
            $trackingService->reviewFinancingByDaf($action, $validated, $user);

            if ($request->hasFile('justificatif_financement_daf')) {
                $file = $request->file('justificatif_financement_daf');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'financement_daf',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif de decision DAF sur financement',
                    $user,
                    $storedFile['est_chiffre']
                );
            }
        });

        $action->refresh();
        $this->recordAudit($request, 'action', 'review_financing_daf', $action, $before, $action->toArray());

        $decision = (string) ($validated['decision_financement'] ?? ActionTrackingService::FINANCEMENT_DECISION_REJETER);
        if ($decision === ActionTrackingService::FINANCEMENT_DECISION_COMPLEMENT) {
            $notificationService->notifyActionFinancingComplementRequested($action, $user);
        } else {
            $notificationService->notifyActionFinancingReviewedByDaf($action, $decision === ActionTrackingService::FINANCEMENT_DECISION_VALIDER, $user);
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', match ($decision) {
                ActionTrackingService::FINANCEMENT_DECISION_VALIDER => 'Financement valide par la DAF. Accord DG requis.',
                ActionTrackingService::FINANCEMENT_DECISION_COMPLEMENT => 'Complement demande par la DAF. Le responsable doit corriger le dossier.',
                default => 'Financement rejete par la DAF avec tracabilite complete.',
            });
    }

    public function reviewFinancingByDg(
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

        $action->loadMissing('pta:id,direction_id,service_id,statut,responsable_id');
        if (! $this->canReviewFinancingByDg($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if (in_array((string) $action->pta?->statut, ['cloture', 'archive'], true)) {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Accord DG'),
            ]);
        }

        if (! in_array($action->financementStatus(), [Action::FINANCEMENT_TRANSMIS_DG, Action::FINANCEMENT_VALIDE_DAF], true)) {
            return back()->withErrors(['general' => 'Ce financement n est pas en attente d accord DG.']);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate($this->financeDgValidationRules($request));

        $before = $action->toArray();
        DB::transaction(function () use ($trackingService, $action, $validated, $request, $user, $secureStorage): void {
            $trackingService->reviewFinancingByDg($action, $validated, $user);

            if ($request->hasFile('justificatif_financement_dg')) {
                $file = $request->file('justificatif_financement_dg');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'financement_dg',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif accord DG sur financement',
                    $user,
                    $storedFile['est_chiffre']
                );
            }
        });

        $action->refresh();
        $this->recordAudit($request, 'action', 'review_financing_dg', $action, $before, $action->toArray());

        $decision = (string) ($validated['decision_financement'] ?? ActionTrackingService::FINANCEMENT_DECISION_REFUSER);
        $notificationService->notifyActionFinancingReviewedByDg($action, $decision === ActionTrackingService::FINANCEMENT_DECISION_ACCORDER, $user);

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', $decision === ActionTrackingService::FINANCEMENT_DECISION_ACCORDER
                ? 'Accord DG enregistre pour le financement.'
                : 'Refus DG enregistre avec tracabilite complete.');
    }

    public function updateFinancingStatusByDaf(Request $request, Action $action): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,responsable_id');
        if (! $this->canMarkFinancingByDaf($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if (in_array((string) $action->pta?->statut, ['cloture', 'archive'], true)) {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Financement'),
            ]);
        }

        $validated = $request->validate([
            'statut_financement' => ['required', Rule::in([
                Action::FINANCEMENT_EN_COURS_ANALYSE,
                Action::FINANCEMENT_FINANCE,
                Action::FINANCEMENT_NON_FINANCE,
            ])],
            'commentaire_financement' => ['nullable', 'string'],
            'montant_valide' => ['nullable', 'integer', 'min:0'],
        ]);

        $before = $action->toArray();
        $status = (string) $validated['statut_financement'];

        $action->forceFill([
            'financement_statut' => $status,
            'financement_daf_par' => $user->id,
            'financement_daf_le' => now(),
            'financement_daf_decision' => $status,
            'financement_daf_commentaire' => $validated['commentaire_financement'] ?? $action->financement_daf_commentaire,
            'financement_montant_valide' => isset($validated['montant_valide']) ? (int) $validated['montant_valide'] : $action->financement_montant_valide,
        ])->save();

        $this->recordAudit($request, 'action', 'update_financing_status_daf', $action, $before, $action->toArray());

        return back()->with('success', 'Statut de financement mis à jour par la DAF.');
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

    public function previewJustificatif(
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

        return $secureStorage->preview($justificatif);
    }

    // ── HELPERS ──────────────────────────────────────────────────────────────

    private function canReviewFinancingByDaf(User $user, Action $action): bool
    {
        if ($action->isResponsible($user)) {
            return false;
        }

        if (! (bool) $action->financement_requis) {
            return false;
        }

        return $this->isDafFinanceReviewer($user)
            && in_array($action->financementStatus(), [Action::FINANCEMENT_A_TRAITER_DAF, Action::FINANCEMENT_REFUSE_DG], true);
    }

    private function canReviewFinancingByDg(User $user, Action $action): bool
    {
        if ($action->isResponsible($user)) {
            return false;
        }

        if (! (bool) $action->financement_requis) {
            return false;
        }

        return $user->hasRole(User::ROLE_DG)
            && in_array($action->financementStatus(), [Action::FINANCEMENT_TRANSMIS_DG, Action::FINANCEMENT_VALIDE_DAF], true);
    }

    private function canMarkFinancingByDaf(User $user, Action $action): bool
    {
        if ($action->isResponsible($user)) {
            return false;
        }

        if (! (bool) $action->financement_requis) {
            return false;
        }

        return $this->isDafFinanceReviewer($user);
    }

    private function isDafFinanceReviewer(User $user): bool
    {
        if (! $user->hasRole(User::ROLE_DIRECTION) || $user->direction_id === null) {
            return false;
        }

        if ($user->relationLoaded('direction')) {
            return (string) ($user->direction?->code ?? '') === 'DAF';
        }

        return $user->direction()->where('code', 'DAF')->exists();
    }

    private function canReadAction(User $user, Action $action): bool
    {
        if ($action->isResponsible($user)) {
            return true;
        }

        if ($user->isAgent()) {
            return (int) $action->responsable_id === (int) $user->id
                || $action->sousActions()->where('agent_id', $user->id)->exists();
        }

        if ((bool) $action->financement_requis && ($this->isDafFinanceReviewer($user) || $user->hasRole(User::ROLE_DG))) {
            return true;
        }

        $lockService = app(PlanningModificationLockService::class);
        if ($lockService->isUnlockReviewer($user) || $lockService->canGivePlanifAvis($user)) {
            return true;
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

        return $this->canReadService(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );
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

    // ── WORKFLOW V2 — autorisations de suivi ─────────────────────────────────

    /**
     * L'action n'est éditable par l'agent que tant qu'elle n'est pas validée
     * (non_soumise / correction_demandee / rejetee_chef).
     */
    private function isExecutionEditable(Action $action): bool
    {
        return in_array((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE), [
            ActionTrackingService::VALIDATION_NON_SOUMISE,
            ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
            ActionTrackingService::VALIDATION_REJETEE_CHEF,
        ], true);
    }

    private function canTrackAction(User $user, Action $action): bool
    {
        if ((string) ($action->statut_parametrage ?? '') === 'a_parametrer') {
            return false;
        }
        if (! $this->isExecutionEditable($action)) {
            return false;
        }

        return $action->isResponsible($user);
    }

    private function canTrackSubAction(User $user, Action $action, SousAction $sousAction): bool
    {
        if ((string) ($action->statut_parametrage ?? '') === 'a_parametrer') {
            return false;
        }
        if (in_array((string) $sousAction->validation_status, [SousAction::VALIDATION_VALIDEE], true)) {
            return false;
        }

        return (int) $sousAction->agent_id === (int) $user->id
            || $action->isResponsible($user);
    }

    private function canReviewByChef(User $user, Action $action): bool
    {
        if ($action->isResponsible($user)) {
            return false;
        }
        if (! $this->workflowSettings()->serviceValidationEnabled()) {
            return false;
        }

        return ($user->isServiceOrUnitChief() && $this->canManageAction($user, $action))
            || app(DelegationService::class)->canReviewServiceAction(
                $user,
                (int) $action->pta?->direction_id,
                (int) $action->pta?->service_id
            );
    }

    private function workflowSettings(): WorkflowSettings
    {
        return app(WorkflowSettings::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function financeDafValidationRules(Request $request): array
    {
        $commentRules = ['nullable', 'string'];
        if (in_array((string) $request->input('decision_financement'), [
            ActionTrackingService::FINANCEMENT_DECISION_REJETER,
            ActionTrackingService::FINANCEMENT_DECISION_COMPLEMENT,
        ], true)) {
            array_unshift($commentRules, 'required');
        }

        return [
            'decision_financement' => ['required', Rule::in([
                ActionTrackingService::FINANCEMENT_DECISION_VALIDER,
                ActionTrackingService::FINANCEMENT_DECISION_COMPLEMENT,
                ActionTrackingService::FINANCEMENT_DECISION_REJETER,
            ])],
            'montant_valide' => ['required_if:decision_financement,'.ActionTrackingService::FINANCEMENT_DECISION_VALIDER, 'nullable', 'integer', 'min:0'],
            'reference_financement' => ['nullable', 'string', 'max:255'],
            'commentaire_financement' => $commentRules,
            'justificatif_financement_daf' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financeDgValidationRules(Request $request): array
    {
        $commentRules = ['nullable', 'string'];
        if ((string) $request->input('decision_financement') === ActionTrackingService::FINANCEMENT_DECISION_REFUSER) {
            array_unshift($commentRules, 'required');
        }

        return [
            'decision_financement' => ['required', Rule::in([ActionTrackingService::FINANCEMENT_DECISION_ACCORDER, ActionTrackingService::FINANCEMENT_DECISION_REFUSER])],
            'commentaire_financement' => $commentRules,
            'justificatif_financement_dg' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ];
    }
}
