<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Justificatif;
use App\Models\SousAction;
use App\Models\User;
use App\Services\ActionManagementSettings;
use App\Services\Actions\ActionTrackingService;
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
        ]);

        return view('workspace.actions.suivi', [
            'action' => $action,
            'canTrackWeekly' => $this->canTrackWeekly($user, $action) && $this->isExecutionEditableByAgent($action),
            'canManageAction' => $this->canManageAction($user, $action),
            'canReviewClosure' => $this->canReviewByChef($user, $action),
            // canReviewDirection retire : l'etape de validation direction
            // a ete supprimee du circuit. Voir WorkflowSettings.
            'canReviewFinancingByDaf' => $this->canReviewFinancingByDaf($user, $action),
            'canReviewFinancingByDg' => $this->canReviewFinancingByDg($user, $action),
            'workflowConfig' => $this->workflowSettings()->actionValidationSummary(),
            'justificatifCategoryLabels' => app(DynamicReferentialSettings::class)->justificatifCategoryLabels(),
            'alertLevelLabels' => app(DynamicReferentialSettings::class)->alertLevelLabels(),
            'validationStatusLabels' => app(DynamicReferentialSettings::class)->validationStatusLabels(),
            'documentAccept' => app(DocumentPolicySettings::class)->acceptAttribute(),
        ]);
    }

    public function storeSubAction(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage,
        WorkspaceNotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin,responsable_id');
        if (! $this->canTrackWeekly($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Creation de sous-action'),
            ]);
        }

        if (! $this->isExecutionEditableByAgent($action)) {
            return back()->withErrors([
                'general' => 'Saisie gelee: action en cours de validation. Modifications autorisees uniquement apres rejet motive.',
            ]);
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
            'est_effectuee' => ['nullable', 'boolean'],
            'justificatif' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ]);

        $isDone = $request->boolean('est_effectuee');
        if ($isDone && (trim((string) ($validated['commentaire'] ?? '')) === '' || ! $request->hasFile('justificatif'))) {
            return back()->withInput()->withErrors([
                'general' => 'Veuillez ajouter un commentaire de realisation et un justificatif avant de marquer cette sous-action comme realisee.',
            ]);
        }

        if ($action->usesQuantitativeProgress() && $isDone && (float) ($validated['quantite_realisee'] ?? 0) <= 0) {
            return back()->withInput()->withErrors([
                'quantite_realisee' => 'La quantite realisee est obligatoire pour une sous-action quantitative effectuee.',
            ]);
        }

        if ($action->date_debut !== null
            && Carbon::parse((string) $validated['date_debut'])->startOfDay()->lt(Carbon::parse($action->date_debut)->startOfDay())
        ) {
            return back()->withErrors([
                'date_debut' => 'La date de debut de la sous-action doit rester dans la periode de l action.',
            ])->withInput();
        }

        if ($action->date_fin !== null
            && Carbon::parse((string) $validated['date_fin'])->startOfDay()->gt(Carbon::parse($action->date_fin)->startOfDay())
        ) {
            return back()->withErrors([
                'date_fin' => 'La date de fin de la sous-action ne doit pas depasser l echeance de l action.',
            ])->withInput();
        }

        $sousAction = DB::transaction(function () use ($action, $validated, $user, $request, $secureStorage, $isDone): SousAction {
            $plannedTarget = $validated['cible_prevue'] ?? null;
            $realizedValue = max(0.0, (float) ($validated['quantite_realisee'] ?? 0));
            $plannedTargetValue = $plannedTarget === null || $plannedTarget === '' ? null : max(0.0, (float) $plannedTarget);
            $completionRate = $plannedTargetValue !== null && $plannedTargetValue > 0.0
                ? round(min(100.0, ($realizedValue / $plannedTargetValue) * 100), 2)
                : ($isDone ? 100 : 0);

            $sousAction = $action->sousActions()->create([
                'agent_id' => (int) $user->id,
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
                'date_realisation' => $isDone ? now() : null,
                'completed_at' => $isDone ? now() : null,
                'statut' => $isDone ? 'effectuee' : 'a_faire',
                'est_effectuee' => $isDone,
                'taux_execution' => $completionRate,
            ]);

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
                'type_evenement' => $isDone ? 'sous_action_effectuee' : 'sous_action_creee',
                'message' => $isDone
                    ? 'Sous-action creee et marquee comme effectuee par l agent.'
                    : 'Sous-action creee par l agent.',
                'details' => [
                    'sous_action_id' => (int) $sousAction->id,
                    'libelle' => (string) $sousAction->libelle,
                    'est_effectuee' => $isDone,
                    'quantite_realisee' => $realizedValue,
                ],
                'cible_role' => 'chef_service',
                'utilisateur_id' => $user->id,
            ]);

            return $sousAction;
        });

        $trackingService->refreshActionMetrics($action);

        // Si la sous-action vient d'être marquée effectuée et que cela complète
        // la liste, on tente la bascule auto vers le chef de service.
        $trackingService->attemptAutoSubmitClosure($action, $user);

        $this->recordAudit(
            $request,
            'sous_action',
            'create',
            $sousAction,
            null,
            $sousAction->toArray()
        );

        $notificationService->notifySubActionCreated($action, $sousAction, $user);
        if ($isDone) {
            $notificationService->notifySubActionCompleted($action, $sousAction, $user);
        }
        if ($request->hasFile('justificatif')) {
            $notificationService->notifyJustificatifAdded($action, $user, $sousAction, 'sous_action');
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Sous-action ajoutee avec succes.');
    }

    public function updateSubAction(
        Request $request,
        Action $action,
        SousAction $sousAction,
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

        $action->loadMissing('pta:id,direction_id,service_id,statut,date_debut,date_fin,responsable_id');
        if (! $this->canTrackWeekly($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ($user->isAgent() && ! $this->canUpdateSubAction($user, $action, $sousAction)) {
            abort(403, 'Acces non autorise.');
        }

        if ($action->pta?->statut === 'verrouille') {
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
            /** @var array<string, mixed> $validated */
            $validated = $request->validate([
                'quantite_realisee' => ['nullable', 'numeric', 'min:0'],
                'commentaire' => ['nullable', 'string', 'max:5000'],
                'resultat_obtenu' => ['nullable', 'string', 'max:5000'],
                'est_effectuee' => ['accepted'],
                'justificatif' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
            ]);

            $hasJustificatif = $request->hasFile('justificatif') || $sousAction->justificatifs()->exists();
            if (! $hasJustificatif) {
                return back()->withInput()->withErrors([
                    'justificatif' => 'Veuillez televerser un justificatif avant de marquer cette sous-action comme realisee.',
                ]);
            }

            $plannedTargetValue = $sousAction->cible_prevue === null || $sousAction->cible_prevue === ''
                ? null
                : max(0.0, (float) $sousAction->cible_prevue);
            $realizedValue = max(0.0, (float) ($validated['quantite_realisee'] ?? $sousAction->quantite_realisee ?? 0));

            if ($plannedTargetValue !== null && $plannedTargetValue > 0.0 && $realizedValue <= 0.0) {
                return back()->withInput()->withErrors([
                    'quantite_realisee' => 'La quantite effectuee est obligatoire pour cette sous-action.',
                ]);
            }

            $before = $sousAction->toArray();

            DB::transaction(function () use ($sousAction, $validated, $user, $request, $secureStorage, $plannedTargetValue, $realizedValue): void {
                $completionRate = $plannedTargetValue !== null && $plannedTargetValue > 0.0
                    ? round(min(100.0, ($realizedValue / $plannedTargetValue) * 100), 2)
                    : 100;

                $sousAction->fill([
                    'quantite_realisee' => $realizedValue,
                    'resultat_obtenu' => $validated['resultat_obtenu'] ?? $sousAction->resultat_obtenu,
                    'taux_realisation' => $completionRate,
                    'commentaire' => $validated['commentaire'] ?? $sousAction->commentaire,
                    'date_realisation' => $sousAction->date_realisation ?: now(),
                    'completed_at' => $sousAction->completed_at ?: now(),
                    'statut' => 'effectuee',
                    'est_effectuee' => true,
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
                    'type_evenement' => 'sous_action_effectuee',
                    'message' => 'Sous-action marquee comme effectuee par l agent.',
                    'details' => [
                        'sous_action_id' => (int) $sousAction->id,
                        'libelle' => (string) $sousAction->libelle,
                        'est_effectuee' => true,
                        'quantite_realisee' => $realizedValue,
                    ],
                    'cible_role' => 'chef_service',
                    'utilisateur_id' => $user->id,
                ]);
            });

            $trackingService->refreshActionMetrics($action);

            // Bascule auto si la dernière sous-action vient d'être marquée
            // effectuée (mode sous_actions / mixte).
            $trackingService->attemptAutoSubmitClosure($action, $user);

            $this->recordAudit(
                $request,
                'sous_action',
                'update',
                $sousAction,
                $before,
                $sousAction->fresh()?->toArray()
            );

            $freshSubAction = $sousAction->fresh() ?? $sousAction;
            $notificationService->notifySubActionCompleted($action, $freshSubAction, $user);
            if ($request->hasFile('justificatif')) {
                $notificationService->notifyJustificatifAdded($action, $user, $freshSubAction, 'sous_action');
            }

            return redirect()
                ->route('workspace.actions.suivi', $action)
                ->with('success', 'Sous-action marquee comme realisee.');
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
            'est_effectuee' => ['nullable', 'boolean'],
            'justificatif' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ]);

        $isDone = $request->boolean('est_effectuee');
        $hasJustificatif = $request->hasFile('justificatif') || $sousAction->justificatifs()->exists();
        if ($isDone && (trim((string) ($validated['commentaire'] ?? '')) === '' || ! $hasJustificatif)) {
            return back()->withInput()->withErrors([
                'general' => 'Veuillez ajouter un commentaire de realisation et un justificatif avant de marquer cette sous-action comme realisee.',
            ]);
        }

        if ($action->usesQuantitativeProgress() && $isDone && (float) ($validated['quantite_realisee'] ?? 0) <= 0) {
            return back()->withInput()->withErrors([
                'quantite_realisee' => 'La quantite realisee est obligatoire pour une sous-action quantitative effectuee.',
            ]);
        }

        if ($action->date_debut !== null
            && Carbon::parse((string) $validated['date_debut'])->startOfDay()->lt(Carbon::parse($action->date_debut)->startOfDay())
        ) {
            return back()->withInput()->withErrors([
                'date_debut' => 'La date de debut de la sous-action doit rester dans la periode de l action.',
            ]);
        }

        if ($action->date_fin !== null
            && Carbon::parse((string) $validated['date_fin'])->startOfDay()->gt(Carbon::parse($action->date_fin)->startOfDay())
        ) {
            return back()->withInput()->withErrors([
                'date_fin' => 'La date de fin de la sous-action ne doit pas depasser l echeance de l action.',
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
                'statut' => $isDone ? 'effectuee' : 'a_faire',
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
                ],
                'cible_role' => 'chef_service',
                'utilisateur_id' => $user->id,
            ]);
        });

        $trackingService->refreshActionMetrics($action);

        // Bascule auto si l'agent vient de marquer la dernière sous-action.
        $trackingService->attemptAutoSubmitClosure($action, $user);

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
            ->with('success', $isDone ? 'Sous-action marquee comme realisee.' : 'Sous-action mise a jour avec succes.');
    }

    // submitWeek : methode supprimee.
    // Le suivi hebdomadaire des actions a ete retire du modele metier.
    // Le suivi se fait desormais via updateQuantitativeProgress et les
    // sous-actions (storeSubAction / updateSubAction).

    public function updateQuantitativeProgress(
        Request $request,
        Action $action,
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

        if (! $action->usesQuantitativeProgress()) {
            return back()->withErrors([
                'general' => 'Cette action ne permet pas de suivi quantitatif.',
            ]);
        }

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Mise a jour quantitative'),
            ]);
        }

        if (! $this->isExecutionEditableByAgent($action)) {
            return back()->withErrors([
                'general' => 'Saisie gelee: action en cours de validation. Modifications autorisees uniquement apres rejet motive.',
            ]);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'quantite_realisee' => ['required', 'numeric', 'min:0'],
            'commentaire_quantitatif' => ['nullable', 'string', 'max:5000'],
            'justificatif_quantitatif' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ]);

        $before = $action->toArray();

        DB::transaction(function () use ($action, $validated, $trackingService, $request, $user, $secureStorage): void {
            $action->forceFill([
                'quantite_realisee' => (float) $validated['quantite_realisee'],
            ])->save();

            if (trim((string) ($validated['commentaire_quantitatif'] ?? '')) !== '') {
                $trackingService->addDiscussionEntry(
                    $action,
                    (string) $validated['commentaire_quantitatif'],
                    'execution_quantitative',
                    'info',
                    ['quantite_realisee' => (float) $validated['quantite_realisee']],
                    $user
                );
            }

            if ($request->hasFile('justificatif_quantitatif')) {
                $file = $request->file('justificatif_quantitatif');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));
                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'execution_quantitative',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif de progression quantitative',
                    $user,
                    $storedFile['est_chiffre']
                );
            }

            ActionLog::query()->create([
                'action_id' => $action->id,
                'niveau' => 'info',
                'type_evenement' => 'execution_quantitative',
                'message' => 'Progression quantitative mise a jour par l agent.',
                'details' => ['quantite_realisee' => (float) $validated['quantite_realisee']],
                'cible_role' => 'chef_service',
                'utilisateur_id' => $user->id,
            ]);
        });

        $trackingService->refreshActionMetrics($action);

        // Bascule auto si la dernière saisie quantitative atteint la cible.
        $trackingService->attemptAutoSubmitClosure($action, $user);

        $this->recordAudit($request, 'action', 'execution_quantitative_update', $action, $before, $action->fresh()?->toArray());
        if ($request->hasFile('justificatif_quantitatif')) {
            $notificationService->notifyJustificatifAdded($action, $user, null, 'execution_quantitative');
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Progression quantitative mise a jour avec succes.');
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

    /**
     * Soumission explicite par l'agent (ou un gestionnaire d'action) de la
     * demande de clôture. Bascule l'action en `soumise_chef` (ou directement à
     * l'étape configurée par `WorkflowSettings::actionSubmissionTarget`) et
     * notifie le chef de service.
     */
    public function submitClosure(
        Request $request,
        Action $action,
        ActionTrackingService $trackingService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');

        // Seuls le responsable de l'action ou un gestionnaire (chef de service
        // non-agent) peuvent forcer la soumission.
        if (! $action->isResponsible($user) && ! $this->canManageAction($user, $action)) {
            abort(403, 'Acces non autorise.');
        }

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Soumission'),
            ]);
        }

        $current = (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE);
        $resoumissionAutorisee = [
            ActionTrackingService::VALIDATION_NON_SOUMISE,
            ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
            ActionTrackingService::VALIDATION_REJETEE_CHEF,
            ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
        ];
        if (! in_array($current, $resoumissionAutorisee, true)) {
            return back()->withErrors([
                'general' => 'Cette action est deja soumise ou validee.',
            ]);
        }

        $validated = $request->validate([
            'rapport_final' => ['nullable', 'string', 'max:5000'],
            'date_fin_reelle' => ['nullable', 'date'],
        ]);

        $payload = [
            'date_fin_reelle' => $validated['date_fin_reelle']
                ?? optional($action->date_fin_reelle)->toDateString()
                ?? now()->toDateString(),
            'rapport_final' => $validated['rapport_final']
                ?? $action->rapport_final
                ?? 'Demande de cloture deposee par l agent.',
        ];

        $before = $action->toArray();

        try {
            DB::transaction(function () use ($trackingService, $action, $payload, $user): void {
                $trackingService->submitClosureForReview($action, $payload, $user);
            });
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['general' => $exception->getMessage()]);
        }

        $action->refresh();
        $this->recordAudit($request, 'action', 'submit_closure', $action, $before, $action->toArray());

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Demande de cloture envoyee au chef de service.');
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

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Validation'),
            ]);
        }

        if ((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE) !== ActionTrackingService::VALIDATION_SOUMISE_CHEF) {
            return back()->withErrors(['general' => 'Cette action n est pas en attente de validation chef de service.']);
        }

        // Formulaire simplifie : 3 champs uniquement.
        $commentRules = ['nullable', 'string', 'max:5000'];
        if ($this->workflowSettings()->rejectionCommentRequired()
            && in_array((string) $request->input('decision_validation'), ['rejeter', 'demander_correction'], true)) {
            array_unshift($commentRules, 'required');
        }

        $validated = $request->validate([
            'decision_validation' => ['required', Rule::in(['valider', 'demander_correction', 'rejeter'])],
            'evaluation_note' => ['required', 'numeric', 'min:0', 'max:100'],
            'evaluation_commentaire' => $commentRules,
        ]);

        $before = $action->toArray();

        DB::transaction(function () use ($trackingService, $action, $validated, $user): void {
            $trackingService->reviewClosureByChef($action, $validated, $user);
        });

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

        if ($action->pta?->statut === 'verrouille') {
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
        $notificationService->notifyActionFinancingReviewedByDaf($action, $decision === ActionTrackingService::FINANCEMENT_DECISION_VALIDER, $user);

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', $decision === ActionTrackingService::FINANCEMENT_DECISION_VALIDER
                ? 'Financement valide par la DAF. Accord DG requis.'
                : 'Financement rejete par la DAF avec tracabilite complete.');
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

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Accord DG'),
            ]);
        }

        if ($action->financementStatus() !== Action::FINANCEMENT_VALIDE_DAF) {
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

        if ($action->pta?->statut === 'verrouille') {
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
            && $action->financementStatus() === Action::FINANCEMENT_VALIDE_DAF;
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
    private function canTrackWeekly(User $user, Action $action): bool
    {
        return $action->isResponsible($user)
            && ($user->isAgent() || $action->isOperationalContext());
    }

    private function canUpdateSubAction(User $user, Action $action, SousAction $sousAction): bool
    {
        if ((int) $sousAction->action_id !== (int) $action->id) {
            return false;
        }

        if (! $this->canTrackWeekly($user, $action)) {
            return false;
        }

        if ((int) $sousAction->agent_id === (int) $user->id) {
            return true;
        }

        return $action->isResponsible($user);
    }

    private function canReadAction(User $user, Action $action): bool
    {
        if ($action->isResponsible($user)) {
            return true;
        }

        if ($user->isAgent()) {
            return (int) $action->responsable_id === (int) $user->id;
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

    // canReviewByDirection : helper supprime — l'etape de validation direction
    // n'existe plus dans le circuit metier.

    private function isExecutionEditableByAgent(Action $action): bool
    {
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
        return match ($this->workflowSettings()->actionSubmissionTarget()) {
            'direction' => 'Action soumise directement a la direction pour evaluation.',
            'final' => 'Action cloturee sans validation supplementaire. Elle est maintenant prise en compte dans les statistiques.',
            default => 'Action soumise au chef de service pour evaluation.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function financeDafValidationRules(Request $request): array
    {
        $commentRules = ['nullable', 'string'];
        if ((string) $request->input('decision_financement') === ActionTrackingService::FINANCEMENT_DECISION_REJETER) {
            array_unshift($commentRules, 'required');
        }

        return [
            'decision_financement' => ['required', Rule::in([ActionTrackingService::FINANCEMENT_DECISION_VALIDER, ActionTrackingService::FINANCEMENT_DECISION_REJETER])],
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
            'evaluation_note' => ['nullable', 'required_without:taux_valide_chef', 'numeric', 'min:0', 'max:100'],
            'taux_valide_chef' => ['nullable', 'required_without:evaluation_note', 'numeric', 'min:0', 'max:100'],
            'conformite_chef' => ['nullable', Rule::in(['conforme', 'partiellement_conforme', 'non_conforme'])],
            'observation_qualite_chef' => ['nullable', 'string', 'max:3000'],
            'evaluation_commentaire' => $commentRules,
        ];

        if ($serviceStep) {
            $rules['validation_sans_correction'] = ['nullable', Rule::in(['0', '1', 0, 1, true, false])];
            $rules['justificatif_evaluation'] = ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()];

            return $rules;
        }

        $rules['justificatif_evaluation_direction'] = ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()];

        return $rules;
    }
}
