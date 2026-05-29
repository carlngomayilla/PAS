<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\DeadlineExtensionRequest;
use App\Models\Justificatif;
use App\Models\SousAction;
use App\Models\User;
use App\Services\ActionManagementSettings;
use App\Services\Actions\ActionBusinessRules;
use App\Services\Actions\ActionTrackingService;
use App\Services\Actions\DeadlineExtensionRequestService;
use App\Services\DocumentPolicySettings;
use App\Services\DynamicReferentialSettings;
use App\Services\Governance\DelegationService;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\Security\SecureJustificatifStorage;
use App\Services\WorkflowSettings;
use App\Support\UiLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'pao:id,pas_id,annee,titre,statut,objectif_operationnel,echeance',
            'pao.pas:id,titre,periode_debut,periode_fin,statut',
            'objectifOperationnel:id,pao_id,libelle,description,echeance',
            'responsable:id,name,email,agent_matricule,agent_fonction,agent_telephone',
            'responsables:id,name,email,agent_matricule,agent_fonction,agent_telephone',
            'soumisPar:id,name,email',
            'evaluePar:id,name,email',
            // 'directionValidePar' supprime : relation Eloquent retiree avec la
            // colonne `direction_valide_par` (cf. migration de purge direction).
            'financementDafPar:id,name,email',
            'financementDgPar:id,name,email',
            // 'weeks' supprime : le suivi hebdomadaire n'existe plus.
            'sousActions' => fn ($q) => $q->with([
                'agent:id,name,email',
                'justificatifs' => fn ($docQuery) => $docQuery->with('ajoutePar:id,name,email')->latest(),
            ])->orderBy('id'),
            'actionKpi',
            'justificatifs' => fn ($q) => $q->with([
                'ajoutePar:id,name,email',
                'actionWeek:id,libelle_sous_action,numero_semaine',
                'sousAction:id,libelle',
            ])->latest(),
            'actionLogs' => fn ($q) => $q->with('utilisateur:id,name,email')->latest()->limit(80),
            'deadlineExtensionRequests' => fn ($q) => $q->with([
                'sousAction:id,libelle',
                'requestedBy:id,name,email',
                'sciqReviewedBy:id,name,email',
                'dgDecidedBy:id,name,email',
            ])->latest(),
        ]);

        return view('workspace.actions.suivi', [
            'action' => $action,
            'canTrackWeekly' => $this->canTrackWeekly($user, $action) && $this->isExecutionEditableByAgent($action),
            'canSubmitAssignedSubActions' => $this->isExecutionEditableByAgent($action),
            'canManageAction' => $this->canManageAction($user, $action),
            'canReviewClosure' => $this->canReviewByChef($user, $action),
            'canRequestDeadlineExtension' => $this->canManageAction($user, $action),
            'canReviewDeadlineExtensionBySciq' => $this->canReviewDeadlineExtensionBySciq($user),
            'canReviewDeadlineExtensionByDg' => $this->canReviewDeadlineExtensionByDg($user),
            // canReviewDirection retire : l'etape de validation direction
            // a ete supprimee du circuit. Voir WorkflowSettings.
            'canReviewFinancingByDaf' => $this->canReviewFinancingByDaf($user, $action),
            'canReviewFinancingByDg' => $this->canReviewFinancingByDg($user, $action),
            'canSignalControlAnomaly' => $this->canSignalControlAnomaly($user, $action),
            'canResolveControlAnomaly' => $this->canResolveControlAnomaly($user, $action),
            'workflowConfig' => $this->workflowSettings()->actionValidationSummary(),
            'justificatifCategoryLabels' => app(DynamicReferentialSettings::class)->justificatifCategoryLabels(),
            'alertLevelLabels' => app(DynamicReferentialSettings::class)->alertLevelLabels(),
            'validationStatusLabels' => app(DynamicReferentialSettings::class)->validationStatusLabels(),
            'documentAccept' => app(DocumentPolicySettings::class)->acceptAttribute(),
        ]);
    }

    public function updateSubAction(
        Request $request,
        Action $action,
        SousAction $sousAction,
        ActionBusinessRules $businessRules,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage,
        WorkspaceNotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ((int) $sousAction->action_id !== (int) $action->id) {
            abort(404);
        }

        $action->loadMissing([
            'pta:id,direction_id,service_id,statut,date_debut,date_fin,responsable_id',
            'objectifOperationnel:id,echeance',
        ]);
        if (! $this->canUpdateSubAction($user, $action, $sousAction)) {
            abort(403, 'Acces non autorise.');
        }

        if (in_array((string) $action->pta?->statut, ['cloture', 'archive'], true)) {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Modification de sous-action'),
            ]);
        }

        if (! $this->isExecutionEditableByAgent($action)) {
            return back()->withErrors([
                'general' => 'Saisie gelee: action en cours de validation. Modifications autorisees uniquement apres rejet motive.',
            ]);
        }

        if ((bool) $sousAction->est_effectuee) {
            return back()->withErrors([
                'general' => 'Cette sous-action est deja realisee et ne peut plus etre modifiee depuis le suivi agent.',
            ]);
        }

        if ($request->boolean('execution_only')) {
            $submissionRequirements = $businessRules->subActionSubmissionRequirements($sousAction);
            $intent = (string) $request->input('tracking_action', 'submit') === 'save' ? 'save' : 'submit';
            $isSubmit = $intent === 'submit';
            $hasJustificatif = $request->hasFile('justificatif') || $sousAction->justificatifs()->exists();

            /** @var array<string, mixed> $validated */
            $validated = $request->validate([
                'quantite_realisee' => [
                    Rule::requiredIf($submissionRequirements['quantity']),
                    'nullable',
                    'numeric',
                    'min:0',
                ],
                'commentaire' => [
                    Rule::requiredIf($isSubmit),
                    'nullable',
                    'string',
                    'max:5000',
                ],
                'resultat_obtenu' => ['nullable', 'string', 'max:5000'],
                'difficultes' => [
                    Rule::requiredIf($submissionRequirements['difficulties']),
                    'nullable',
                    'string',
                    'max:5000',
                ],
                'justificatif' => [
                    Rule::requiredIf($isSubmit && $submissionRequirements['proof'] && ! $hasJustificatif),
                    'nullable',
                    'file',
                    'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(),
                    app(DocumentPolicySettings::class)->mimesRule(),
                ],
            ]);

            if ($isSubmit && trim((string) ($validated['commentaire'] ?? '')) === '') {
                return back()->withInput()->withErrors([
                    'commentaire' => 'Le commentaire est obligatoire pour soumettre une sous-action.',
                ]);
            }

            $plannedTargetValue = $sousAction->cible_prevue === null || $sousAction->cible_prevue === ''
                ? null
                : max(0.0, (float) $sousAction->cible_prevue);
            $realizedValue = max(0.0, (float) ($validated['quantite_realisee'] ?? $sousAction->quantite_realisee ?? 0));

            if ($submissionRequirements['quantity'] && $realizedValue <= 0.0) {
                return back()->withInput()->withErrors([
                    'quantite_realisee' => 'La quantite effectuee est obligatoire pour cette sous-action.',
                ]);
            }

            $before = $sousAction->toArray();

            DB::transaction(function () use ($sousAction, $validated, $user, $request, $secureStorage, $plannedTargetValue, $realizedValue, $intent, $isSubmit): void {
                $completionRate = $plannedTargetValue !== null && $plannedTargetValue > 0.0
                    ? round(min(100.0, ($realizedValue / $plannedTargetValue) * 100), 2)
                    : 100;

                $sousAction->fill([
                    'quantite_realisee' => $realizedValue,
                    'resultat_obtenu' => $validated['resultat_obtenu'] ?? $sousAction->resultat_obtenu,
                    'taux_realisation' => $completionRate,
                    'commentaire' => $validated['commentaire'] ?? $sousAction->commentaire,
                    'date_realisation' => $isSubmit ? ($sousAction->date_realisation ?: now()) : null,
                    'completed_at' => $isSubmit ? ($sousAction->completed_at ?: now()) : null,
                    'statut' => $isSubmit ? 'en_attente_validation_chef' : 'en_cours',
                    'est_effectuee' => $isSubmit,
                    'taux_execution' => $completionRate,
                ])->save();

                if ($request->hasFile('justificatif')) {
                    $file = $request->file('justificatif');
                    $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));

                    Justificatif::query()->create([
                        'justifiable_type' => Action::class,
                        'justifiable_id' => $sousAction->action_id,
                        'sous_action_id' => $sousAction->id,
                        'categorie' => 'sous_action',
                        'nom_original' => $storedFile['nom_original'],
                        'chemin_stockage' => $storedFile['path'],
                        'est_chiffre' => $storedFile['est_chiffre'],
                        'mime_type' => $storedFile['mime_type'],
                        'taille_octets' => $storedFile['taille_octets'],
                        'description' => 'Justificatif de sous-action agent',
                        'ajoute_par' => $user->id,
                    ]);
                }

                ActionLog::query()->create([
                    'action_id' => $sousAction->action_id,
                    'niveau' => 'info',
                    'type_evenement' => $isSubmit ? 'sous_action_effectuee' : 'sous_action_suivi_enregistre',
                    'message' => $isSubmit
                        ? 'Sous-action soumise au chef par l agent.'
                        : 'Suivi de sous-action enregistre par l agent.',
                    'details' => [
                        'tracking_action' => $intent,
                        'sous_action_id' => (int) $sousAction->id,
                        'libelle' => (string) $sousAction->libelle,
                        'est_effectuee' => $isSubmit,
                        'quantite_realisee' => $realizedValue,
                        'performance_recalculee' => $completionRate,
                        'difficultes' => $validated['difficultes'] ?? null,
                    ],
                    'cible_role' => 'chef_service',
                    'utilisateur_id' => $user->id,
                ]);
            });

            $trackingService->refreshActionMetrics($action);

            // Bascule auto si la derniÃ¨re sous-action vient d'Ãªtre marquÃ©e
            // effectuÃ©e (mode sous_actions / mixte).
            // Soumission parentale explicite: la sous-action part au chef,
            // l'action globale reste a valider par le chef quand le dossier est pret.

            $this->recordAudit(
                $request,
                'sous_action',
                'update',
                $sousAction,
                $before,
                $sousAction->fresh()?->toArray()
            );

            $freshSubAction = $sousAction->fresh() ?? $sousAction;
            if ($isSubmit) {
                $notificationService->notifySubActionCompleted($action, $freshSubAction, $user);
            }
            if ($request->hasFile('justificatif')) {
                $notificationService->notifyJustificatifAdded($action, $user, $freshSubAction, 'sous_action');
            }

            if (! $isSubmit) {
                return redirect()
                    ->route('workspace.actions.suivi', $action)
                    ->with('success', 'Suivi de sous-action enregistre.');
            }

            return redirect()
                ->route('workspace.actions.suivi', $action)
                ->with('success', 'Sous-action marquÃ©e comme rÃ©alisÃ©e.');
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'libelle_sous_action' => ['required', 'string', 'max:255'],
            'description_sous_action' => ['nullable', 'string', 'max:5000'],
            'resultat_attendu' => ['nullable', 'string', 'max:5000'],
            'cible_prevue' => ['nullable', 'numeric', 'min:0'],
            'quantite_realisee' => ['nullable', 'numeric', 'min:0'],
            'unite' => ['nullable', 'string', 'max:100'],
            'resultat_obtenu' => ['nullable', 'string', 'max:5000'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'commentaire' => ['nullable', 'string', 'max:5000'],
            'difficultes' => ['nullable', 'string', 'max:5000'],
            'est_effectuee' => ['nullable', 'boolean'],
            'justificatif' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ]);

        $isDone = $request->boolean('est_effectuee');
        $submissionRequirements = $businessRules->subActionSubmissionRequirements($sousAction);
        $hasJustificatif = $request->hasFile('justificatif') || $sousAction->justificatifs()->exists();
        if ($isDone && (
            ($submissionRequirements['comment'] && trim((string) ($validated['commentaire'] ?? '')) === '')
            || ($submissionRequirements['difficulties'] && trim((string) ($validated['difficultes'] ?? '')) === '')
            || ! $hasJustificatif
        )) {
            return back()->withInput()->withErrors([
                'general' => 'Veuillez ajouter les elements obligatoires avant de marquer cette sous-action comme realisee.',
            ]);
        }

        if ($submissionRequirements['quantity'] && $isDone && (float) ($validated['quantite_realisee'] ?? 0) <= 0) {
            return back()->withInput()->withErrors([
                'quantite_realisee' => 'La quantite realisee est obligatoire pour une sous-action quantitative effectuee.',
            ]);
        }

        if ($action->date_debut !== null
            && Carbon::parse((string) $validated['date_debut'])->startOfDay()->lt(Carbon::parse($action->date_debut)->startOfDay())
        ) {
            return back()->withInput()->withErrors([
                'date_debut' => 'La date de dÃ©but de la sous-action doit rester dans la pÃ©riode de l action.',
            ]);
        }

        if ($action->date_fin !== null
            && Carbon::parse((string) $validated['date_fin'])->startOfDay()->gt(Carbon::parse($action->date_fin)->startOfDay())
        ) {
            return back()->withInput()->withErrors([
                'date_fin' => 'La date de fin de la sous-action ne doit pas dÃ©passer l Ã©chÃ©ance de l action.',
            ]);
        }

        $objectiveDeadline = $action->objectifOperationnel?->echeance;
        if ($objectiveDeadline !== null
            && Carbon::parse((string) $validated['date_fin'])->startOfDay()->gt(Carbon::parse($objectiveDeadline)->startOfDay())
        ) {
            return back()->withInput()->withErrors([
                'date_fin' => 'La date de fin de la sous-action ne doit pas dÃ©passer l Ã©chÃ©ance de l objectif opÃ©rationnel.',
            ]);
        }

        $before = $sousAction->toArray();

        DB::transaction(function () use ($action, $sousAction, $validated, $user, $request, $secureStorage, $isDone): void {
            $plannedTarget = $validated['cible_prevue'] ?? null;
            $realizedValue = max(0.0, (float) ($validated['quantite_realisee'] ?? 0));
            $plannedTargetValue = $plannedTarget === null || $plannedTarget === '' ? null : max(0.0, (float) $plannedTarget);
            $completionRate = $plannedTargetValue !== null && $plannedTargetValue > 0.0
                ? round(min(100.0, ($realizedValue / $plannedTargetValue) * 100), 2)
                : ($isDone ? 100 : 0);

            $sousAction->fill([
                'libelle' => trim((string) $validated['libelle_sous_action']),
                'description' => $validated['description_sous_action'] ?? null,
                'resultat_attendu' => $validated['resultat_attendu'] ?? null,
                'cible_prevue' => $plannedTargetValue,
                'quantite_realisee' => $realizedValue,
                'unite' => ($validated['unite'] ?? null) ?: $action->unite_cible,
                'resultat_obtenu' => $validated['resultat_obtenu'] ?? null,
                'taux_realisation' => $completionRate,
                'commentaire' => $validated['commentaire'] ?? null,
                'date_debut' => (string) $validated['date_debut'],
                'date_fin' => (string) $validated['date_fin'],
                'date_realisation' => $isDone ? ($sousAction->date_realisation ?: now()) : null,
                'completed_at' => $isDone ? ($sousAction->completed_at ?: now()) : null,
                'statut' => $isDone ? 'en_attente_validation_chef' : 'non_demarre',
                'est_effectuee' => $isDone,
                'taux_execution' => $completionRate,
            ]);
            $sousAction->save();

            if ($request->hasFile('justificatif')) {
                $file = $request->file('justificatif');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));

                Justificatif::query()->create([
                    'justifiable_type' => Action::class,
                    'justifiable_id' => $action->id,
                    'sous_action_id' => $sousAction->id,
                    'categorie' => 'sous_action',
                    'nom_original' => $storedFile['nom_original'],
                    'chemin_stockage' => $storedFile['path'],
                    'est_chiffre' => $storedFile['est_chiffre'],
                    'mime_type' => $storedFile['mime_type'],
                    'taille_octets' => $storedFile['taille_octets'],
                    'description' => 'Justificatif de sous-action agent',
                    'ajoute_par' => $user->id,
                ]);
            }

            ActionLog::query()->create([
                'action_id' => $action->id,
                'niveau' => 'info',
                'type_evenement' => $isDone ? 'sous_action_effectuee' : 'sous_action_mise_a_jour',
                'message' => $isDone
                    ? 'Sous-action marquee comme effectuee par l agent.'
                    : 'Sous-action mise a jour par l agent.',
                'details' => [
                    'sous_action_id' => (int) $sousAction->id,
                    'libelle' => (string) $sousAction->libelle,
                    'est_effectuee' => $isDone,
                    'quantite_realisee' => $realizedValue,
                    'difficultes' => $validated['difficultes'] ?? null,
                ],
                'cible_role' => 'chef_service',
                'utilisateur_id' => $user->id,
            ]);
        });

        $trackingService->refreshActionMetrics($action);

        // Bascule auto si l'agent vient de marquer la derniÃ¨re sous-action.
        // La soumission parentale se fait via le circuit sous-actions validees.

        $this->recordAudit(
            $request,
            'sous_action',
            'update',
            $sousAction,
            $before,
            $sousAction->fresh()?->toArray()
        );

        $freshSubAction = $sousAction->fresh() ?? $sousAction;
        if ($isDone) {
            $notificationService->notifySubActionCompleted($action, $freshSubAction, $user);
        }
        if ($request->hasFile('justificatif')) {
            $notificationService->notifyJustificatifAdded($action, $user, $freshSubAction, 'sous_action');
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', $isDone ? 'Sous-action marquÃ©e comme rÃ©alisÃ©e.' : 'Sous-action mise Ã  jour avec succÃ¨s.');
    }

    public function reviewSubAction(
        Request $request,
        Action $action,
        SousAction $sousAction,
        ActionTrackingService $trackingService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ((int) $sousAction->action_id !== (int) $action->id) {
            abort(404);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin');
        if (! $this->canReviewByChef($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if (in_array((string) $action->pta?->statut, ['cloture', 'archive'], true)) {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Validation de sous-action'),
            ]);
        }

        $commentRules = ['nullable', 'string', 'max:5000'];
        if (in_array((string) $request->input('decision_sous_action'), ['rejeter', 'demander_correction'], true)) {
            array_unshift($commentRules, 'required');
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'decision_sous_action' => ['required', Rule::in(['valider', 'demander_correction', 'rejeter'])],
            'commentaire_sous_action' => $commentRules,
        ]);

        $before = $sousAction->toArray();

        try {
            DB::transaction(function () use ($trackingService, $action, $sousAction, $validated, $user): void {
                $trackingService->reviewSubActionByChef($action, $sousAction, $validated, $user);
            });
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['general' => $exception->getMessage()]);
        }

        $freshSubAction = $sousAction->fresh() ?? $sousAction;
        $this->recordAudit(
            $request,
            'sous_action',
            'review_by_chef',
            $freshSubAction,
            $before,
            $freshSubAction->toArray()
        );

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', (string) $validated['decision_sous_action'] === 'valider'
                ? 'Sous-action validee par le chef.'
                : 'Sous-action renvoyee pour correction.');
    }

    // submitWeek : methode supprimee.
    // Le suivi hebdomadaire des actions a ete retire du modele metier.
    // Le suivi se fait desormais via updateQuantitativeProgress et les
    // sous-actions (updateSubAction).

    public function updateQuantitativeProgress(
        Request $request,
        Action $action,
        ActionBusinessRules $businessRules,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage,
        WorkspaceNotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,responsable_id');
        if (! $this->canTrackWeekly($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        $hasSubActions = $action->sousActions()->exists();
        if (! $action->usesQuantitativeProgress() && $hasSubActions) {
            return back()->withErrors([
                'general' => 'Cette action se suit par sous-actions.',
            ]);
        }

        if (in_array((string) $action->pta?->statut, ['cloture', 'archive'], true)) {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Mise a jour quantitative'),
            ]);
        }

        if (! $this->isExecutionEditableByAgent($action)) {
            return back()->withErrors([
                'general' => 'Saisie gelee: action en cours de validation. Modifications autorisees uniquement apres rejet motive.',
            ]);
        }

        $submissionRequirements = $businessRules->actionSubmissionRequirements($action);
        $intent = (string) $request->input('tracking_action', 'submit') === 'save' ? 'save' : 'submit';
        $isSubmit = $intent === 'submit';
        $proofCategory = $submissionRequirements['quantity'] ? 'execution_quantitative' : 'execution_non_quantitative';
        $hasExistingProof = $action->justificatifs()
            ->whereIn('categorie', ['execution_quantitative', 'execution_non_quantitative', 'execution_mixte', 'final'])
            ->exists();
        $hasExistingFollowUp = ActionLog::query()
            ->where('action_id', (int) $action->id)
            ->where('type_evenement', 'execution_quantitative')
            ->exists();

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'quantite_realisee' => [
                Rule::requiredIf($submissionRequirements['quantity'] && (! $isSubmit || ! $hasExistingFollowUp)),
                'nullable',
                'numeric',
                'min:0',
            ],
            'commentaire_quantitatif' => [
                Rule::requiredIf($isSubmit),
                'nullable',
                'string',
                'max:5000',
            ],
            'difficultes_quantitatives' => [
                Rule::requiredIf($submissionRequirements['difficulties']),
                'nullable',
                'string',
                'max:5000',
            ],
            'justificatif_quantitatif' => [
                Rule::requiredIf($isSubmit && $submissionRequirements['proof'] && ! $hasExistingProof),
                'nullable',
                'file',
                'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(),
                app(DocumentPolicySettings::class)->mimesRule(),
            ],
        ]);

        if ($isSubmit && trim((string) ($validated['commentaire_quantitatif'] ?? '')) === '') {
            return back()->withInput()->withErrors([
                'commentaire_quantitatif' => 'Le commentaire est obligatoire pour soumettre au chef.',
            ]);
        }

        $before = $action->toArray();
        $oldTotal = max(0.0, (float) ($action->quantite_realisee ?? 0));
        $enteredQuantity = $submissionRequirements['quantity']
            ? max(0.0, (float) ($validated['quantite_realisee'] ?? 0))
            : 0.0;
        $realizedValue = $submissionRequirements['quantity'] ? $oldTotal + $enteredQuantity : $oldTotal;

        if ($isSubmit && $submissionRequirements['quantity'] && ! $hasExistingFollowUp && $enteredQuantity <= 0.0) {
            return back()->withInput()->withErrors([
                'quantite_realisee' => 'Au moins un suivi quantitatif est obligatoire avant soumission.',
            ]);
        }

        $declaredProgress = $submissionRequirements['quantity'] ? null : 100.0;

        DB::transaction(function () use ($action, $validated, $trackingService, $request, $user, $secureStorage, $submissionRequirements, $realizedValue, $declaredProgress, $proofCategory, $intent, $oldTotal, $enteredQuantity): void {
            if ($submissionRequirements['quantity']) {
                $action->forceFill([
                    'quantite_realisee' => $realizedValue,
                    'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
                    'statut' => ActionTrackingService::STATUS_EN_COURS,
                ])->save();
            } else {
                $action->forceFill([
                    'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
                    'statut' => ActionTrackingService::STATUS_EN_COURS,
                ])->save();
            }

            if (trim((string) ($validated['commentaire_quantitatif'] ?? '')) !== '') {
                $trackingService->addDiscussionEntry(
                    $action,
                    (string) $validated['commentaire_quantitatif'],
                    $proofCategory,
                    'info',
                    [
                        'quantite_saisie' => $submissionRequirements['quantity'] ? $enteredQuantity : null,
                        'ancien_total' => $submissionRequirements['quantity'] ? $oldTotal : null,
                        'nouveau_total' => $submissionRequirements['quantity'] ? $realizedValue : null,
                        'progression_declaree' => $declaredProgress,
                        'difficultes' => $validated['difficultes_quantitatives'] ?? null,
                        'tracking_action' => $intent,
                    ],
                    $user
                );
            }

            if ($request->hasFile('justificatif_quantitatif')) {
                $file = $request->file('justificatif_quantitatif');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    $proofCategory,
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    $submissionRequirements['quantity']
                        ? 'Justificatif de progression quantitative'
                        : 'Justificatif d execution non quantifiable',
                    $user,
                    $storedFile['est_chiffre']
                );
            }

            ActionLog::query()->create([
                'action_id' => $action->id,
                'niveau' => 'info',
                'type_evenement' => 'execution_quantitative',
                'message' => $submissionRequirements['quantity']
                    ? ($intent === 'submit' ? 'Progression quantitative soumise au chef par l agent.' : 'Progression quantitative enregistree par l agent.')
                    : ($intent === 'submit' ? 'Execution non quantifiable soumise au chef par l agent.' : 'Execution non quantifiable enregistree par l agent.'),
                'details' => [
                    'tracking_action' => $intent,
                    'quantite_saisie' => $submissionRequirements['quantity'] ? $enteredQuantity : null,
                    'ancien_total' => $submissionRequirements['quantity'] ? $oldTotal : null,
                    'nouveau_total' => $submissionRequirements['quantity'] ? $realizedValue : null,
                    'performance_recalculee' => $submissionRequirements['quantity'] && (float) ($action->quantite_cible ?? 0) > 0
                        ? round(min(100.0, ($realizedValue / (float) $action->quantite_cible) * 100), 2)
                        : $declaredProgress,
                    'progression_declaree' => $declaredProgress,
                    'difficultes' => $validated['difficultes_quantitatives'] ?? null,
                ],
                'cible_role' => 'chef_service',
                'utilisateur_id' => $user->id,
            ]);
        });

        $trackingService->refreshActionMetrics($action);

        // Bascule auto si la derniÃ¨re saisie quantitative atteint la cible.
        if ($isSubmit) {
            try {
                $trackingService->submitClosureForReview($action->fresh() ?? $action, [
                    'date_fin_reelle' => now()->toDateString(),
                    'rapport_final' => (string) ($validated['commentaire_quantitatif'] ?? ''),
                    'difficultes_rencontrees' => $validated['difficultes_quantitatives'] ?? null,
                ], $user);
            } catch (\InvalidArgumentException $exception) {
                return back()->withErrors(['general' => $exception->getMessage()]);
            }

            $action->refresh()->loadMissing('pta:id,direction_id,service_id');
            $notificationService->notifyActionSubmittedToChef($action, $user);
        }

        $trackingService->attemptAutoSubmitClosure($action, $user);

        $this->recordAudit($request, 'action', 'execution_quantitative_update', $action, $before, $action->fresh()?->toArray());
        if ($request->hasFile('justificatif_quantitatif')) {
            $notificationService->notifyJustificatifAdded($action, $user, null, $proofCategory);
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', $isSubmit ? $this->submissionSuccessMessage() : 'Suivi enregistre. Vous pouvez continuer avant soumission.');
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

    public function signalAnomaly(
        Request $request,
        Action $action,
        WorkspaceNotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');
        if (! $this->canSignalControlAnomaly($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'type_anomalie' => ['required', Rule::in(array_keys($this->anomalyTypeOptions()))],
            'niveau' => ['required', Rule::in(['info', 'warning', 'critical'])],
            'cible_role' => ['required', Rule::in(['responsable', 'chef_service', 'direction', 'planification', 'dg'])],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'correction_attendue' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $action->toArray();
        $type = (string) $validated['type_anomalie'];
        $details = [
            'manual' => true,
            'resolved' => false,
            'anomaly_type' => $type,
            'blocked_scope' => $this->blockedScopeForAnomaly($type),
            'correction_attendue' => trim((string) ($validated['correction_attendue'] ?? '')),
            'declared_by_role' => (string) $user->role,
        ];

        $log = ActionLog::query()->create([
            'action_id' => $action->id,
            'niveau' => (string) $validated['niveau'],
            'type_evenement' => 'anomalie_'.$type,
            'message' => trim((string) $validated['message']),
            'details' => $details,
            'cible_role' => (string) $validated['cible_role'],
            'utilisateur_id' => $user->id,
            'lu' => false,
        ]);

        $notificationService->notifyActionAlertEscalation($log, $user->id);
        $this->recordAudit($request, 'action', 'signal_anomaly', $action, $before, [
            'action_id' => (int) $action->id,
            'action_log_id' => (int) $log->id,
            'anomaly_type' => $type,
            'niveau' => (string) $validated['niveau'],
            'cible_role' => (string) $validated['cible_role'],
            'details' => $details,
        ]);

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Anomalie signalee et notification envoyee.');
    }

    public function resolveAnomaly(Request $request, Action $action, ActionLog $log): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');
        if ((int) $log->action_id !== (int) $action->id) {
            abort(404);
        }

        if (! $this->canResolveControlAnomaly($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        /** @var array{commentaire_resolution?: string|null} $validated */
        $validated = $request->validate([
            'commentaire_resolution' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $log->toArray();
        $details = is_array($log->details) ? $log->details : [];
        $details['resolved'] = true;
        $details['resolved_by'] = (int) $user->id;
        $details['resolved_at'] = now()->toIso8601String();
        $details['resolution_comment'] = trim((string) ($validated['commentaire_resolution'] ?? ''));

        $log->forceFill([
            'details' => $details,
            'lu' => true,
        ])->save();

        $this->recordAudit($request, 'action', 'resolve_anomaly', $action, $before, $log->toArray());

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Anomalie cloturee.');
    }

    public function reviewClosure(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        WorkspaceNotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin');
        if (! $this->canReviewByChef($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if (in_array((string) $action->pta?->statut, ['cloture', 'archive'], true)) {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Validation'),
            ]);
        }

        if ((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE) !== ActionTrackingService::VALIDATION_SOUMISE_CHEF) {
            return back()->withErrors(['general' => 'Cette action n est pas en attente de validation chef de service.']);
        }

        if ($message = $this->activeBlockingAnomalyMessage($action, 'validation')) {
            return back()->withErrors(['general' => $message]);
        }

        // Formulaire simplifie : 3 champs uniquement.
        $commentRules = ['nullable', 'string', 'max:5000'];
        if ($this->workflowSettings()->rejectionCommentRequired()
            && in_array((string) $request->input('decision_validation'), ['rejeter', 'demander_correction'], true)) {
            array_unshift($commentRules, 'required');
        }

        // Spec v2 : le chef ne note plus. Seul le motif de rejet/correction est requis.
        $validated = $request->validate([
            'decision_validation' => ['required', Rule::in(['valider', 'demander_correction', 'rejeter'])],
            'motif_validation_chef' => $commentRules,
        ]);

        $before = $action->toArray();

        try {
            DB::transaction(function () use ($trackingService, $action, $validated, $user): void {
                $trackingService->reviewClosureByChef($action, $validated, $user);
            });
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['general' => $exception->getMessage()]);
        }

        $action->refresh();
        $this->recordAudit($request, 'action', 'review_closure', $action, $before, $action->toArray());

        $decision = (string) ($validated['decision_validation'] ?? 'rejeter');
        if ($decision === 'valider') {
            $notificationService->notifyActionFinalizedByChef($action, $user);
        } else {
            $notificationService->notifyActionReviewedByChef($action, $decision === 'valider', $user);
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', match ($decision) {
                'valider' => $this->workflowSettings()->actionValidationSummary()['service_review_success_text'],
                'demander_correction' => 'Correction demandee. L agent peut mettre a jour et resoumettre.',
                default => 'Action rejetee. L agent peut mettre a jour et resoumettre.',
            });
    }

    // reviewClosureByDirection : methode supprimee.
    // L'etape de validation direction a ete retiree du circuit metier.
    // La route correspondante renvoie desormais 403 (cf. routes/web.php).

    public function storeDeadlineExtensionRequest(
        Request $request,
        Action $action,
        DeadlineExtensionRequestService $deadlineExtensionService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut', 'objectifOperationnel:id,echeance');
        if (! $this->canManageAction($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        $sousAction = null;
        if ($request->filled('sous_action_id')) {
            $sousAction = $action->sousActions()->whereKey((int) $request->input('sous_action_id'))->firstOrFail();
        }

        $currentDeadline = $sousAction?->date_fin ?: $action->date_fin ?: $action->date_echeance;
        if ($currentDeadline === null) {
            return back()->withErrors(['general' => 'Aucune echeance actuelle n est definie.']);
        }

        $validated = $request->validate([
            'sous_action_id' => ['nullable', 'integer'],
            'requested_deadline' => ['required', 'date', 'after:'.Carbon::parse($currentDeadline)->toDateString()],
            'motif' => ['required', 'string', 'max:1000'],
            'justification' => ['required', 'string', 'max:5000'],
            'report_attachment' => ['required', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ]);

        try {
            $deadlineExtensionService->create(
                $action,
                $sousAction,
                [
                    'requested_deadline' => (string) $validated['requested_deadline'],
                    'motif' => (string) $validated['motif'],
                    'justification' => (string) $validated['justification'],
                ],
                $request->file('report_attachment'),
                $user,
                $request->ip()
            );
        } catch (\Throwable $exception) {
            return back()->withInput()->withErrors(['general' => $exception->getMessage()]);
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Demande de report transmise a SCIQ / Planification.');
    }

    public function reviewDeadlineExtensionBySciq(
        Request $request,
        DeadlineExtensionRequest $deadlineExtensionRequest,
        DeadlineExtensionRequestService $deadlineExtensionService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }
        if (! $this->canReviewDeadlineExtensionBySciq($user)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validate([
            'sciq_avis' => ['required', Rule::in([
                DeadlineExtensionRequest::AVIS_FAVORABLE,
                DeadlineExtensionRequest::AVIS_DEFAVORABLE,
                DeadlineExtensionRequest::AVIS_COMPLEMENT,
            ])],
            'sciq_comment' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $deadlineExtensionService->reviewBySciq($deadlineExtensionRequest, $validated, $user, $request->ip());
        } catch (\Throwable $exception) {
            return back()->withErrors(['general' => $exception->getMessage()]);
        }

        return redirect()
            ->route('workspace.actions.suivi', $deadlineExtensionRequest->action_id)
            ->with('success', 'Avis SCIQ / Planification enregistre.');
    }

    public function reviewDeadlineExtensionByDg(
        Request $request,
        DeadlineExtensionRequest $deadlineExtensionRequest,
        DeadlineExtensionRequestService $deadlineExtensionService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }
        if (! $this->canReviewDeadlineExtensionByDg($user)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validate([
            'dg_decision' => ['required', Rule::in([
                DeadlineExtensionRequest::DECISION_APPROUVER,
                DeadlineExtensionRequest::DECISION_REJETER,
                DeadlineExtensionRequest::DECISION_COMPLEMENT,
            ])],
            'approved_deadline' => ['nullable', 'date'],
            'dg_comment' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $deadlineExtensionService->decideByDg($deadlineExtensionRequest, $validated, $user, $request->ip());
        } catch (\Throwable $exception) {
            return back()->withErrors(['general' => $exception->getMessage()]);
        }

        return redirect()
            ->route('workspace.actions.suivi', $deadlineExtensionRequest->action_id)
            ->with('success', 'Decision DG enregistree.');
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

        if ($message = $this->activeBlockingAnomalyMessage($action, 'circuit_daf')) {
            return back()->withErrors(['general' => $message]);
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
            'montant_valide' => ['nullable', 'numeric', 'min:0'],
        ]);

        $before = $action->toArray();
        $status = (string) $validated['statut_financement'];

        $action->forceFill([
            'financement_statut' => $status,
            'financement_daf_par' => $user->id,
            'financement_daf_le' => now(),
            'financement_daf_decision' => $status,
            'financement_daf_commentaire' => $validated['commentaire_financement'] ?? $action->financement_daf_commentaire,
            'financement_montant_valide' => $validated['montant_valide'] ?? $action->financement_montant_valide,
        ])->save();

        $this->recordAudit($request, 'action', 'update_financing_status_daf', $action, $before, $action->toArray());

        return back()->with('success', 'Statut de financement mis a jour par la DAF.');
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

    private function canSignalControlAnomaly(User $user, Action $action): bool
    {
        if (! $this->canReadAction($user, $action)) {
            return false;
        }

        return $user->hasRole(
            User::ROLE_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_SUPER_ADMIN,
            User::ROLE_ADMIN
        );
    }

    private function canResolveControlAnomaly(User $user, Action $action): bool
    {
        return $this->canSignalControlAnomaly($user, $action)
            || $this->canManageAction($user, $action);
    }

    /**
     * @return array<string, string>
     */
    private function anomalyTypeOptions(): array
    {
        return [
            'justificatif_manquant' => 'Justificatif manquant',
            'commentaire_absent' => 'Commentaire absent',
            'date_incoherente' => 'Date incoherente',
            'financement_incomplet' => 'Financement incomplet',
            'kpi_incoherent' => 'KPI incoherent',
            'autre' => 'Autre anomalie',
        ];
    }

    private function blockedScopeForAnomaly(string $type): string
    {
        return match ($type) {
            'justificatif_manquant' => 'validation',
            'commentaire_absent' => 'soumission',
            'date_incoherente' => 'enregistrement',
            'financement_incomplet' => 'circuit_daf',
            'kpi_incoherent' => 'reporting',
            default => 'controle',
        };
    }

    private function activeBlockingAnomalyMessage(Action $action, string $scope): ?string
    {
        $log = ActionLog::query()
            ->activeAlert()
            ->where('action_id', (int) $action->id)
            ->where('type_evenement', 'like', 'anomalie_%')
            ->where('details->blocked_scope', $scope)
            ->latest()
            ->first();

        if (! $log instanceof ActionLog) {
            return null;
        }

        $details = is_array($log->details) ? $log->details : [];
        $correction = trim((string) ($details['correction_attendue'] ?? ''));
        $reason = trim((string) ($log->message ?: 'anomalie active'));

        return trim(sprintf(
            'Operation bloquee : %s%s',
            $reason,
            $correction !== '' ? ' Correction attendue : '.$correction : ''
        ));
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
    private function canTrackWeekly(User $user, Action $action): bool
    {
        if ((string) ($action->statut_parametrage ?? '') === 'a_parametrer') {
            return false;
        }

        return $action->isResponsible($user)
            && ($user->isAgent() || $action->isOperationalContext());
    }

    private function canUpdateSubAction(User $user, Action $action, SousAction $sousAction): bool
    {
        if ((int) $sousAction->action_id !== (int) $action->id) {
            return false;
        }

        if ((int) $sousAction->agent_id !== (int) $user->id) {
            return false;
        }

        return $user->isAgent()
            || $action->isOperationalContext()
            || $action->isResponsible($user);
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

    private function canReviewByChef(User $user, Action $action): bool
    {
        if ($action->isResponsible($user)) {
            return false;
        }

        if (! $this->workflowSettings()->serviceValidationEnabled()) {
            return false;
        }

        return ($user->hasRole(User::ROLE_SERVICE)
                && $this->canManageAction($user, $action))
            || app(DelegationService::class)->canReviewServiceAction(
                $user,
                (int) $action->pta?->direction_id,
                (int) $action->pta?->service_id
            );
    }

    private function canReviewDeadlineExtensionBySciq(User $user): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_SCIQ, User::ROLE_PLANIFICATION, User::ROLE_CHEF_UNITE_SCIQ);
    }

    private function canReviewDeadlineExtensionByDg(User $user): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_DG);
    }

    // canReviewByDirection : helper supprime â€” l'etape de validation direction
    // n'existe plus dans le circuit metier.

    private function isExecutionEditableByAgent(Action $action): bool
    {
        if ((string) ($action->statut_parametrage ?? '') === 'a_parametrer') {
            return false;
        }

        $status = (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE);

        return in_array($status, [
            ActionTrackingService::VALIDATION_NON_SOUMISE,
            ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
            ActionTrackingService::VALIDATION_REJETEE_CHEF,
            ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
        ], true);
    }

    private function workflowSettings(): WorkflowSettings
    {
        return app(WorkflowSettings::class);
    }

    private function submissionSuccessMessage(): string
    {
        return 'Action soumise au chef de service pour validation.';
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
            'montant_valide' => ['required_if:decision_financement,'.ActionTrackingService::FINANCEMENT_DECISION_VALIDER, 'nullable', 'numeric', 'min:0'],
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
    /**
     * @return array<string, mixed>
     */
    private function reviewValidationRules(Request $request, bool $serviceStep): array
    {
        $commentRules = ['nullable', 'string'];
        $allowedDecisions = $serviceStep ? ['valider', 'rejeter', 'demander_correction'] : ['valider', 'rejeter'];

        if ($this->workflowSettings()->rejectionCommentRequired()
            && in_array((string) $request->input('decision_validation'), ['rejeter', 'demander_correction'], true)) {
            array_unshift($commentRules, 'required');
        }

        $rules = [
            'decision_validation' => ['required', Rule::in($allowedDecisions)],
            // Spec v2 : motif_validation_chef remplace evaluation_commentaire.
            'motif_validation_chef' => $commentRules,
        ];

        if ($serviceStep) {
            $rules['validation_sans_correction'] = ['nullable', Rule::in(['0', '1', 0, 1, true, false])];
            $rules['justificatif_evaluation'] = ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()];

            return $rules;
        }

        $rules['justificatif_evaluation_direction'] = [
            'nullable',
            'file',
            'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(),
            app(DocumentPolicySettings::class)->mimesRule(),
        ];

        return $rules;
    }
}
