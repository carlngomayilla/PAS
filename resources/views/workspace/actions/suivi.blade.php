@extends('layouts.workspace')

@section('content')
    @php
        $metricLabel = static fn (string $metric): string => \App\Support\UiLabel::metric($metric);
        $actionStatusLabel = static fn (string $status): string => \App\Support\UiLabel::actionStatus($status);
        $validationStatusLabel = static fn (string $status): string => \App\Support\UiLabel::validationStatus($status);
        $kpi = $action->actionKpi;
        $status = $action->statut_dynamique ?: 'non_demarre';
        $pta = $action->pta;
        $pao = $action->pao ?: $pta?->pao;
        $pas = $pao?->pas;
        $objectifOperationnel = $action->objectifOperationnel;
        $validationStatus = (string) ($action->statut_validation ?: 'non_soumise');
        $validationStatusLabels = is_array($validationStatusLabels ?? null) ? $validationStatusLabels : [];
        $justificatifCategoryLabels = is_array($justificatifCategoryLabels ?? null) ? $justificatifCategoryLabels : [];
        $alertLevelLabels = is_array($alertLevelLabels ?? null) ? $alertLevelLabels : [];
        $validationLabel = $validationStatusLabels[$validationStatus] ?? $validationStatusLabel($validationStatus);
        $currentUser = auth()->user();
        $deadlineRequestTargets = $action->sousActions;
        $isExecutorAgent = $currentUser instanceof \App\Models\User
            && $currentUser->isAgent()
            && (
                $action->isResponsible($currentUser)
                || ($action->relationLoaded('sousActions') && $action->sousActions->contains(fn ($subAction): bool => (int) $subAction->agent_id === (int) $currentUser->id))
            );
        $executorMetricsReleased = in_array($validationStatus, ['validee_controle', 'validee_planification', 'validee_direction'], true);
        $hideExecutorMetrics = $isExecutorAgent && ! $executorMetricsReleased;
        $financingStatusOptions = \App\Models\Action::financingStatusOptions();
        $financingStatus = $action->financementStatus();
        $financingLabel = $financingStatusOptions[$financingStatus] ?? 'A traiter DAF';
        $modeEvaluation = $action->resolvedEvaluationMode();
        $modeEvaluationLabel = $action->mode_evaluation_label;
        $usesSubTasksProgress = $action->usesSubTasksProgress();
        $usesQuantitativeProgress = $action->usesQuantitativeProgress();
        $usesNoQuantityProgress = $action->usesNoQuantityProgress();
        $usesStructuredProgress = $action->usesStructuredProgressTracking();
        $usesHistoricalProgress = ! $usesStructuredProgress;
        $showSubActionsPanel = $usesHistoricalProgress || $usesSubTasksProgress;
        $actionBusinessRules = app(\App\Services\Actions\ActionBusinessRules::class);
        $isActionQuantifiable = $actionBusinessRules->isActionQuantifiable($action);
        $actionSubmissionRequirements = $actionBusinessRules->actionSubmissionRequirements($action);
        $declaredProgression = app(\App\Services\ActionPerformanceService::class)->calculateDeclaredProgress($action);
        $workflow = $workflowConfig ?? [
            'service_enabled' => true,
            'direction_enabled' => false,
            'submission_target' => 'service',
            'chain_label' => 'Agent -> Chef de service -> Controleur',
            'submission_help_text' => "L'action est visee par le chef puis controlee par SCIQ ou Planification.",
            'submission_button_label' => 'Soumettre',
            'service_review_button_label' => 'Viser et transmettre',
            'service_review_success_text' => 'Visa chef enregistre.',
            'final_statistics_hint' => 'Oui apres validation finale du controleur.',
            'rejection_comment_required' => true,
        ];
        $agentLocked = auth()->check()
            && (int) auth()->id() === (int) $action->responsable_id
            && !in_array($validationStatus, ['non_soumise', 'correction_demandee', 'rejetee_chef', 'correction_controle', 'rejetee_direction'], true);
        $isAwaitingChef = $workflow['service_enabled'] && $validationStatus === 'soumise_chef';
        $isAwaitingControl = $validationStatus === 'soumise_controle';
        // L'etape « validation direction » a ete supprimee du circuit metier.
        // Les statuts `validee_direction` / `rejetee_direction` ne sont
        // conserves qu'en lecture historique (actions cloturees avant la
        // suppression). Voir routes/web.php pour les stubs 410.
        $ressources = $action->resourceLabels();
        $financingJustificatif = $action->justificatifs->firstWhere('categorie', 'financement');
        $financingDafJustificatif = $action->justificatifs->firstWhere('categorie', 'financement_daf');
        $financingDgJustificatif = $action->justificatifs->firstWhere('categorie', 'financement_dg');
        $financingStage = match ($financingStatus) {
            \App\Models\Action::FINANCEMENT_PRE_SIGNALE_DAF,
            \App\Models\Action::FINANCEMENT_COMPLEMENT_DEMANDE,
            \App\Models\Action::FINANCEMENT_REJETE_DAF => 1,
            \App\Models\Action::FINANCEMENT_SOUMIS_DAF => 2,
            \App\Models\Action::FINANCEMENT_TRANSMIS_DG,
            \App\Models\Action::FINANCEMENT_VALIDE_DAF => 3,
            \App\Models\Action::FINANCEMENT_VALIDE_DG,
            \App\Models\Action::FINANCEMENT_REJETE_DG => 4,
            default => 0,
        };
        $rmoNames = $action->relationLoaded('responsables')
            ? $action->responsables->pluck('name')->filter()->values()->all()
            : [];
        $rmoIds = $action->relationLoaded('responsables')
            ? $action->responsables->pluck('id')->push($action->responsable_id)->filter()->map(fn ($id) => (int) $id)->unique()->values()
            : collect(array_filter([(int) $action->responsable_id]));
        $discussionEntries = $action->actionLogs
            ->filter(fn ($log) => in_array($log->type_evenement, [
                'commentaire',
                'action_soumise_validation',
                'action_validee_chef',
                'action_rejetee_chef',
                'action_transmise_controle',
                'action_validee_controle',
                'action_rejetee_controle',
                'action_validee_direction',
                'action_rejetee_direction',
                'financement_demande',
                'financement_prepare',
                'financement_soumis_daf',
                'financement_resoumis_daf',
                'financement_valide_daf',
                'financement_complement_demande',
                'financement_rejete_daf',
                'financement_accord_dg',
                'financement_refus_dg',
            ], true))
            ->sortBy('created_at')
            ->values();
        $deadlineExtensionRequests = $action->relationLoaded('deadlineExtensionRequests')
            ? $action->deadlineExtensionRequests
            : collect();
        $activeDeadlineExtensionStatuses = [
            \App\Models\DeadlineExtensionRequest::STATUS_SOUMISE,
            \App\Models\DeadlineExtensionRequest::STATUS_EN_ANALYSE,
            \App\Models\DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE,
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION,
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE,
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE,
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DG,
            \App\Models\DeadlineExtensionRequest::STATUS_APPROUVEE,
        ];
        $activeDeadlineExtensionRequest = $deadlineExtensionRequests
            ->first(fn ($request): bool => in_array((string) $request->status, $activeDeadlineExtensionStatuses, true));
        $deadlineExtensionStatusLabels = [
            \App\Models\DeadlineExtensionRequest::STATUS_SOUMISE => 'Soumise',
            \App\Models\DeadlineExtensionRequest::STATUS_EN_ANALYSE => 'En analyse',
            \App\Models\DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE => 'Complement demande',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION => 'Accord directeur attendu',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE => 'Migration vers la direction',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE => 'Migration vers la direction',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DG => 'Accord final DG attendu',
            \App\Models\DeadlineExtensionRequest::STATUS_APPROUVEE => 'Ancienne décision à appliquer par la DG',
            \App\Models\DeadlineExtensionRequest::STATUS_REJETEE => 'Rejetee',
            \App\Models\DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE => 'Modifications appliquées',
        ];
        $deadlineExtensionStatusStyles = [
            \App\Models\DeadlineExtensionRequest::STATUS_SOUMISE => 'anbg-badge anbg-badge-info',
            \App\Models\DeadlineExtensionRequest::STATUS_EN_ANALYSE => 'anbg-badge anbg-badge-info',
            \App\Models\DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE => 'anbg-badge anbg-badge-warning',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION => 'anbg-badge anbg-badge-warning',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE => 'anbg-badge anbg-badge-warning',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE => 'anbg-badge anbg-badge-warning',
            \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DG => 'anbg-badge anbg-badge-warning',
            \App\Models\DeadlineExtensionRequest::STATUS_APPROUVEE => 'anbg-badge anbg-badge-success',
            \App\Models\DeadlineExtensionRequest::STATUS_REJETEE => 'anbg-badge anbg-badge-danger',
            \App\Models\DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE => 'anbg-badge anbg-badge-success',
        ];
        $activeAnomalyLogs = $action->actionLogs
            ->filter(function ($log): bool {
                $details = is_array($log->details) ? $log->details : [];

                $level = (string) $log->niveau;
                $isVisibleAlert = in_array($level, ['warning', 'critical', 'urgence'], true)
                    || ($level === 'info' && ($details['manual'] ?? false) === true);

                return $isVisibleAlert
                    && str_starts_with((string) $log->type_evenement, 'anomalie_')
                    && ($details['resolved'] ?? false) !== true;
            })
            ->sortByDesc('created_at')
            ->values();
        $statusStyles = [
            'non_demarre' => 'anbg-badge anbg-badge-neutral',
            'en_cours' => 'anbg-badge anbg-badge-info',
            'a_risque' => 'anbg-badge anbg-badge-warning',
            'en_avance' => 'anbg-badge anbg-badge-success',
            'en_retard' => 'anbg-badge anbg-badge-danger',
            'suspendu' => 'anbg-badge anbg-badge-danger',
            'annule' => 'anbg-badge anbg-badge-neutral',
            'acheve_dans_delai' => 'anbg-badge anbg-badge-success',
            'acheve_hors_delai' => 'anbg-badge anbg-badge-warning',
        ];
        $validationStyles = [
            'non_soumise' => 'anbg-badge anbg-badge-neutral',
            'soumise_chef' => 'anbg-badge anbg-badge-warning',
            'rejetee_chef' => 'anbg-badge anbg-badge-danger',
            'correction_demandee' => 'anbg-badge anbg-badge-warning',
            'validee_chef' => 'anbg-badge anbg-badge-info',
            'soumise_controle' => 'anbg-badge anbg-badge-info',
            'correction_controle' => 'anbg-badge anbg-badge-warning',
            'validee_controle' => 'anbg-badge anbg-badge-success',
            'rejetee_direction' => 'anbg-badge anbg-badge-danger',
            'validee_direction' => 'anbg-badge anbg-badge-success',
        ];
        $financingStyles = [
            \App\Models\Action::FINANCEMENT_NON_REQUIS => 'anbg-badge anbg-badge-neutral',
            \App\Models\Action::FINANCEMENT_A_TRAITER_DAF => 'anbg-badge anbg-badge-warning',
            \App\Models\Action::FINANCEMENT_VALIDE_DAF => 'anbg-badge anbg-badge-info',
            \App\Models\Action::FINANCEMENT_REJETE_DAF => 'anbg-badge anbg-badge-danger',
            \App\Models\Action::FINANCEMENT_ACCORDE_DG => 'anbg-badge anbg-badge-success',
            \App\Models\Action::FINANCEMENT_REFUSE_DG => 'anbg-badge anbg-badge-danger',
        ];
        $detailSections = [
            'action-validation' => 'Validation',
            'action-fiche' => 'Fiche',
            'action-echeances' => 'Echeances',
            'action-financement' => 'Financement',
            'action-discussion' => 'Discussion',
            'action-justificatifs' => 'Justificatifs',
            'action-logs' => 'Journal',
        ];
        $actionWorkspace = is_array($actionWorkspace ?? null) ? $actionWorkspace : [];
        $workspaceNextStep = is_array($actionWorkspace['next_step'] ?? null) ? $actionWorkspace['next_step'] : [];
        $workspaceHierarchy = is_array($actionWorkspace['hierarchy'] ?? null) ? $actionWorkspace['hierarchy'] : [];
        $workspaceDeadline = is_array($actionWorkspace['deadline'] ?? null) ? $actionWorkspace['deadline'] : [];
        $workspaceConfiguration = is_array($actionWorkspace['configuration'] ?? null) ? $actionWorkspace['configuration'] : [];
        $workspaceDeadlineClasses = [
            'late' => 'text-red-700 dark:text-red-300',
            'urgent' => 'text-red-700 dark:text-red-300',
            'warning' => 'text-amber-700 dark:text-amber-300',
            'planned' => 'text-emerald-700 dark:text-emerald-300',
            'closed' => 'text-emerald-700 dark:text-emerald-300',
            'missing' => 'text-slate-500 dark:text-slate-400',
        ];
        $progressionReelle = max(0, min(100, (float) ($action->progression_reelle ?? 0)));
        $progressionDeclaree = max(0, min(100, (float) $declaredProgression));
        $progressionTheorique = max(0, min(100, (float) ($action->progression_theorique ?? 0)));
        $sousActionsTotal = $action->relationLoaded('sousActions') ? $action->sousActions->count() : 0;
        $sousActionsDone = $action->relationLoaded('sousActions') ? $action->sousActions->where('est_effectuee', true)->count() : 0;
        $showActionExecutionForm = $usesStructuredProgress
            && ($canTrackWeekly ?? false)
            && ($usesQuantitativeProgress || $usesNoQuantityProgress || ($usesSubTasksProgress && $sousActionsTotal === 0));
        $targetValue = max(0, (float) ($action->quantite_cible ?? 0));
        $realizedValue = max(0, (float) ($action->quantite_realisee ?? 0));
        $remainingValue = $targetValue > 0 ? max(0, $targetValue - $realizedValue) : 0;
        $overachievementRate = (float) ($action->taux_depassement ?? ($targetValue > 0 && $realizedValue > $targetValue ? (($realizedValue - $targetValue) / $targetValue) * 100 : 0));
        $performanceLabels = [
            'non_evaluee' => 'Non evaluee',
            'critique' => 'Critique',
            'sous_seuil' => 'Sous-seuil',
            'acceptable' => 'Acceptable',
            'satisfaisante' => 'Satisfaisante',
            'excellente' => 'Excellente',
            'cible_depassee' => 'Seuil depasse',
        ];
        $quantitativeStatusLabels = [
            'non_demarre' => 'Non demarree',
            'faible_avancement' => 'Faible avancement',
            'en_progression' => 'En progression',
            'presque_atteinte' => 'Presque atteinte',
            'cible_atteinte' => 'Seuil atteint',
            'cible_depassee' => 'Seuil depasse',
        ];
        $statusClass = $statusStyles[$status] ?? $statusStyles['non_demarre'];
        $validationClass = $validationStyles[$validationStatus] ?? $validationStyles['non_soumise'];
        $financingClass = $financingStyles[$financingStatus] ?? $financingStyles[\App\Models\Action::FINANCEMENT_A_TRAITER_DAF];
        $responsableDisplay = $rmoNames !== []
            ? implode(', ', array_slice($rmoNames, 0, 2))
            : (string) ($action->responsable?->name ?? 'Non attribue');
        if (count($rmoNames) > 2) {
            $responsableDisplay .= ' +'.(count($rmoNames) - 2);
        }
        $periodDisplay = (optional($action->date_debut)->format('d/m/Y') ?: '-')
            .' au '
            .(optional($action->date_fin)->format('d/m/Y') ?: '-');
        $stepperStoppedStatuses = ['suspendu', 'annule'];
        $stepperFinishedStatuses = ['acheve_dans_delai', 'acheve_hors_delai', 'cloturee'];
        $stepperSubmittedStatuses = ['soumise_chef', 'validee_chef', 'soumise_controle', 'correction_demandee', 'correction_controle', 'rejetee_chef', 'validee_controle', 'validee_direction', 'rejetee_direction'];
        $stepperChefApprovedStatuses = ['validee_chef', 'soumise_controle', 'soumise_planification', 'validee_controle', 'validee_planification', 'validee_direction'];
        $stepperValidatedStatuses = ['validee_controle', 'validee_planification', 'validee_direction'];
        $stepperCorrectionStatuses = ['correction_demandee', 'correction_controle', 'rejetee_chef', 'rejetee_direction'];
        $stepperIsStopped = in_array($status, $stepperStoppedStatuses, true);
        $stepperHasStarted = $progressionReelle > 0
            || ! in_array($status, ['non_demarre'], true)
            || $validationStatus !== 'non_soumise';
        $stepperExecutionDone = $progressionReelle >= 100 || in_array($status, $stepperFinishedStatuses, true);
        $stepperHasSubmitted = in_array($validationStatus, $stepperSubmittedStatuses, true);
        $stepperChefApproved = in_array($validationStatus, $stepperChefApprovedStatuses, true);
        $stepperControlPending = in_array($validationStatus, ['validee_chef', 'soumise_controle'], true);
        $stepperIsValidated = in_array($validationStatus, $stepperValidatedStatuses, true);
        $stepperNeedsCorrection = in_array($validationStatus, $stepperCorrectionStatuses, true) || $status === 'a_corriger';
        $stepperIsClosed = $stepperIsValidated;
        $executionStepCaption = $hideExecutorMetrics
            ? 'Realise'
            : number_format($progressionReelle, 0, ',', ' ').'% realise';
        $actionStepperSteps = [
            [
                'label' => 'Planification',
                'caption' => 'Action créée',
                'state' => 'done',
            ],
            [
                'label' => 'Exécution',
                'caption' => $executionStepCaption,
                'state' => $stepperIsStopped
                    ? 'blocked'
                    : ($stepperExecutionDone || $stepperIsValidated ? 'done' : ($stepperHasStarted ? 'current' : 'pending')),
            ],
            [
                'label' => 'Visa chef',
                'caption' => $stepperChefApproved ? 'Visa enregistre' : $validationLabel,
                'state' => $stepperNeedsCorrection
                    ? 'warning'
                    : ($stepperChefApproved ? 'done' : ($stepperHasSubmitted ? 'current' : 'pending')),
            ],
            [
                'label' => 'Controle',
                'caption' => $stepperIsValidated ? 'Controle valide' : ($stepperControlPending ? 'Decision attendue' : 'A venir'),
                'state' => $validationStatus === 'correction_controle'
                    ? 'warning'
                    : ($stepperIsValidated ? 'done' : ($stepperControlPending ? 'current' : 'pending')),
            ],
            [
                'label' => 'Clôture',
                'caption' => $stepperIsClosed ? 'Dossier finalisé' : 'À venir',
                'state' => $stepperIsStopped
                    ? 'blocked'
                    : ($stepperIsClosed ? 'done' : 'pending'),
            ],
        ];
        $actionStepperActiveIndex = 0;
        foreach ($actionStepperSteps as $stepIndex => $step) {
            if ($step['state'] !== 'pending') {
                $actionStepperActiveIndex = $stepIndex;
            }
        }
    @endphp

    <section id="action-header" class="action-detail-hero mb-4">
        <div class="action-detail-hero-body">
            <div class="action-detail-copy">
                <span class="action-detail-eyebrow">Action {{ $action->code ?? $action->id }}</span>
                <h1 class="action-detail-title">{{ $action->libelle }}</h1>
                <div class="action-detail-meta-grid">
                    <span class="action-detail-meta">
                        <span class="action-detail-meta-label">Période</span>
                        <strong>{{ $periodDisplay }}</strong>
                    </span>
                    <span class="action-detail-meta">
                        <span class="action-detail-meta-label">Responsable</span>
                        <strong>{{ $responsableDisplay }}</strong>
                    </span>
                    <span class="action-detail-status {{ $statusClass }}">
                        {{ $actionStatusLabel($status) }}
                    </span>
                    <span class="action-detail-status {{ $validationClass }}">
                        {{ $validationLabel }}
                    </span>
                    @if ($action->financement_requis)
                        <span class="action-detail-status {{ $financingClass }}">
                            Financement: {{ $financingLabel }}
                        </span>
                    @endif
                </div>
                <nav class="action-detail-tabs no-print" aria-label="Sections de l'action" role="tablist" data-action-detail-tabs>
                    @foreach ($detailSections as $anchor => $label)
                        <a
                            id="{{ $anchor }}-tab"
                            class="action-detail-tab {{ $loop->first ? 'active' : '' }}"
                            href="#{{ $anchor }}"
                            role="tab"
                            aria-controls="{{ $anchor }}"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                            tabindex="{{ $loop->first ? '0' : '-1' }}"
                        >
                            {{ $label }}
                        </a>
                    @endforeach
                </nav>
            </div>
            <div class="action-detail-actions no-print">
                @if (($isActionModificationLocked ?? false) && ($canProcessActionUnlock ?? false))
                    <a class="btn btn-secondary" href="{{ route('workspace.planning-unlocks.index') }}">
                        Traiter le deverrouillage
                    </a>
                @endif
                <a class="btn btn-warning" href="#action-echeances">Report de l'action</a>
                <button type="button" onclick="window.print()" class="btn btn-secondary flex items-center gap-2" title="Imprimer la fiche action">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Imprimer
                </button>
                <a class="btn btn-secondary" href="{{ route('workspace.actions.index') }}">Retour liste</a>
            </div>
        </div>
    </section>

    <section class="mb-4 border-y border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950" aria-label="Poste de traitement de l'action">
        <div class="grid lg:grid-cols-[minmax(0,1.5fr)_minmax(19rem,0.8fr)]">
            <div class="p-4 sm:p-5 lg:border-r lg:border-slate-200 dark:lg:border-slate-700">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ $workspaceNextStep['eyebrow'] ?? 'Traitement' }}</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">{{ $workspaceNextStep['title'] ?? 'Consulter le dossier' }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $workspaceNextStep['message'] ?? '' }}</p>
                    </div>
                    <a
                        class="btn btn-primary shrink-0"
                        href="{{ $workspaceNextStep['anchor'] ?? '#action-fiche' }}"
                        data-action-workspace-command
                    >
                        {{ $workspaceNextStep['action_label'] ?? 'Ouvrir' }}
                    </a>
                </div>

                @if ($workspaceHierarchy !== [])
                    <ol class="mt-5 grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-2 xl:grid-cols-3 dark:border-slate-700 dark:bg-slate-700" aria-label="Rattachement strategique">
                        @foreach ($workspaceHierarchy as $hierarchyItem)
                            <li class="min-w-0 bg-white px-3 py-2.5 dark:bg-slate-950">
                                <span class="block text-[0.68rem] font-bold uppercase text-slate-500 dark:text-slate-400">{{ $hierarchyItem['label'] }}</span>
                                <strong class="mt-1 block text-sm text-slate-900 dark:text-slate-100">{{ $hierarchyItem['value'] }}</strong>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>

            <div class="grid grid-cols-2 divide-x divide-y divide-slate-200 dark:divide-slate-700">
                <div class="p-4">
                    <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Profil actif</span>
                    <strong class="mt-1 block text-sm text-slate-900 dark:text-slate-100">{{ $actionWorkspace['role_label'] ?? '-' }}</strong>
                </div>
                <div class="p-4">
                    <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Échéance</span>
                    <strong class="mt-1 block text-sm {{ $workspaceDeadlineClasses[$workspaceDeadline['state'] ?? 'missing'] ?? $workspaceDeadlineClasses['missing'] }}">
                        {{ isset($workspaceDeadline['date']) ? optional($workspaceDeadline['date'])->format('d/m/Y') : '-' }}
                    </strong>
                    <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ $workspaceDeadline['label'] ?? 'Non definie' }}</span>
                </div>
                <div class="p-4">
                    <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Cible</span>
                    <strong class="mt-1 block text-sm text-slate-900 dark:text-slate-100">{{ $workspaceConfiguration['target_label'] ?? '-' }}</strong>
                </div>
                <div class="p-4">
                    <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Preuve</span>
                    <strong class="mt-1 block text-sm text-slate-900 dark:text-slate-100">{{ $workspaceConfiguration['proof_label'] ?? '-' }}</strong>
                </div>
                <div class="col-span-2 p-4">
                    <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">RMO</span>
                    <strong class="mt-1 block text-sm text-slate-900 dark:text-slate-100">{{ $workspaceConfiguration['rmo_label'] ?? $responsableDisplay }}</strong>
                </div>
            </div>
        </div>
    </section>

    <section class="action-stepper-panel mb-4" aria-label="Étapes dynamiques de l'action">
        <div class="action-stepper-head">
            <div>
                <span class="action-tracking-kicker">Workflow</span>
                <h2 class="action-stepper-title">Étapes de suivi</h2>
            </div>
            <span class="action-stepper-pill">
                {{ $hideExecutorMetrics ? 'Realise' : number_format($progressionReelle, 0, ',', ' ').'% realise' }}
            </span>
        </div>
        <ol class="action-stepper">
            @foreach ($actionStepperSteps as $index => $step)
                <li class="action-stepper-item is-{{ $step['state'] }} {{ $index <= $actionStepperActiveIndex ? 'is-line-active' : '' }}" @if (in_array($step['state'], ['current', 'warning', 'blocked'], true)) aria-current="step" @endif>
                    <span class="action-stepper-marker">{{ $index + 1 }}</span>
                    <span class="action-stepper-copy">
                        <strong>{{ $step['label'] }}</strong>
                        <small>{{ $step['caption'] }}</small>
                    </span>
                </li>
            @endforeach
        </ol>
    </section>

    @if ($hideExecutorMetrics)
        <section class="showcase-summary-grid mb-4">
            <article class="showcase-kpi-card">
                <p class="showcase-kpi-label">Execution</p>
                <p class="showcase-kpi-number">Réalisé</p>
            </article>
            <article class="showcase-kpi-card">
                <p class="showcase-kpi-label">Validation</p>
                <p class="showcase-kpi-number text-base">{{ $validationLabel }}</p>
            </article>
            <article class="showcase-kpi-card">
                <p class="showcase-kpi-label">Justificatifs</p>
                <p class="showcase-kpi-number">{{ $action->justificatifs->count() }}</p>
            </article>
            <article class="showcase-kpi-card">
                <p class="showcase-kpi-label">Sous-actions</p>
                <p class="showcase-kpi-number">{{ $sousActionsDone }}/{{ $sousActionsTotal }}</p>
            </article>
        </section>
    @else
    <section class="showcase-summary-grid mb-4">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Avancement déclaré</p>
            <p class="showcase-kpi-number">{{ number_format($progressionDeclaree, 0) }}%</p>
            <div class="mt-3 showcase-progress-track">
                <span class="showcase-progress-bar {{ $progressionDeclaree >= 80 ? 'bg-[#8fc043]' : ($progressionDeclaree >= 50 ? 'bg-blue-500' : 'bg-[#f0e509]') }}" style="width: {{ $progressionDeclaree }}%"></span>
            </div>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Progression théorique</p>
            <p class="showcase-kpi-number">{{ number_format($progressionTheorique, 0) }}%</p>
            <div class="mt-3 showcase-progress-track">
                <span class="showcase-progress-bar bg-slate-400" style="width: {{ $progressionTheorique }}%"></span>
            </div>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Performance d'exécution</p>
            <p class="showcase-kpi-number">{{ number_format((float) ($kpi?->kpi_performance ?? 0), 0) }}%</p>
            <p class="showcase-kpi-meta">
                Délai {{ number_format((float) ($kpi?->kpi_delai ?? 0), 0) }} |
                Global {{ number_format((float) ($kpi?->kpi_global ?? 0), 0) }}
            </p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Sous-actions suivies</p>
            <p class="showcase-kpi-number">{{ $sousActionsDone }}/{{ $sousActionsTotal }}</p>
            <p class="showcase-kpi-meta">Sous-actions planifiées</p>
        </article>
    </section>
    @endif

    {{-- ════════════════════════════════════════════════════════════════════
         SUIVI V2 (cf. docs/WORKFLOW-SUIVI-V2.md)
         Performance officielle/provisoire + saisie agent + validation chef.
         ════════════════════════════════════════════════════════════════════ --}}
    @php
        $v2PerfLabels = [
            'non_demarre' => ['Non démarrée', 'anbg-badge-neutral'],
            'critique' => ['Critique', 'anbg-badge-danger'],
            'en_alerte' => ['En alerte', 'anbg-badge-warning'],
            'acceptable' => ['Acceptable', 'anbg-badge-info'],
            'cible_atteinte' => ['Seuil atteint', 'anbg-badge-success'],
            'cible_depassee' => ['Seuil dépassé', 'anbg-badge-success'],
        ];
        $v2TemporalLabels = [
            'dans_delai' => ['Dans les délais', 'anbg-badge-success'],
            'bientot_retard' => ['Bientôt en retard', 'anbg-badge-warning'],
            'en_retard' => ['En retard', 'anbg-badge-danger'],
            'critique' => ['Critique', 'anbg-badge-danger'],
            'sans_echeance' => ['Sans échéance', 'anbg-badge-neutral'],
        ];
        [$perfLabel, $perfClass] = $v2PerfLabels[$v2PerfStatus ?? 'non_demarre'] ?? $v2PerfLabels['non_demarre'];
        [$tempLabel, $tempClass] = $v2TemporalLabels[$v2TemporalStatus ?? 'sans_echeance'] ?? $v2TemporalLabels['sans_echeance'];
        $v2ValidationStatus = (string) ($action->statut_validation ?? 'non_soumise');
        $v2IsSubmitted = $v2ValidationStatus === 'soumise_chef';
        $v2IsAwaitingControl = in_array($v2ValidationStatus, ['validee_chef', 'soumise_controle'], true);
        $v2IsValidated = in_array($v2ValidationStatus, ['validee_controle', 'validee_planification', 'validee_direction'], true);
    @endphp

    <span id="action-suivi" class="block scroll-mt-24" aria-hidden="true"></span>
    <span id="action-status" class="block scroll-mt-24" aria-hidden="true"></span>
    <span id="action-controle" class="block scroll-mt-24" aria-hidden="true"></span>
    <section
        id="action-validation"
        class="action-tracking-panel action-detail-tab-panel is-active mb-4"
        role="tabpanel"
        aria-labelledby="action-validation-tab"
        tabindex="0"
        data-action-workspace-tracking
        data-action-tab-panel
        data-has-errors="{{ $errors->hasAny(['general', 'quantite_realisee', 'difficulte', 'justificatif', 'commentaire', 'progress_percent']) ? 'true' : 'false' }}"
    >
        <div class="action-tracking-head">
            <div>
                <span class="action-tracking-kicker">Execution</span>
                <h2 class="action-tracking-title">Suivi de l'action</h2>
            </div>
            <div class="action-tracking-badges">
                @if ($hideExecutorMetrics)
                    <span class="anbg-badge anbg-badge-success px-3 py-1">Réalisé</span>
                    <span class="anbg-badge anbg-badge-warning px-3 py-1">{{ $validationLabel }}</span>
                @else
                    <span class="anbg-badge {{ $perfClass }} px-3 py-1">{{ $perfLabel }}</span>
                    <span class="anbg-badge {{ $tempClass }} px-3 py-1">{{ $tempLabel }}</span>
                @endif
            </div>
        </div>

        {{-- Performances : officielle en avant, provisoire en complément --}}
        <div class="action-tracking-metrics">
            @if ($hideExecutorMetrics)
                <article class="action-tracking-stat action-tracking-stat-main">
                    <span class="action-tracking-stat-label">Execution</span>
                    <strong class="action-tracking-stat-value">Réalisé</strong>
                </article>
                <article class="action-tracking-stat">
                    <span class="action-tracking-stat-label">Validation</span>
                    <strong class="action-tracking-type">{{ $validationLabel }}</strong>
                </article>
            @else
            <article class="action-tracking-stat action-tracking-stat-main">
                <span class="action-tracking-stat-label">Performance officielle</span>
                <strong class="action-tracking-stat-value">{{ number_format((float) $v2OfficialPerf, 0, ',', ' ') }}%</strong>
                <span class="action-tracking-stat-note">{{ $v2IsValidated ? 'Validée par le contrôle' : 'En attente de validation finale' }}</span>
            </article>
            <article class="action-tracking-stat">
                <span class="action-tracking-stat-label">Performance provisoire</span>
                <strong class="action-tracking-stat-value">{{ number_format((float) $v2ProvisionalPerf, 0, ',', ' ') }}%</strong>
                <span class="action-tracking-stat-note">Calculée à chaque enregistrement</span>
            </article>
            @endif
            <article class="action-tracking-stat">
                <span class="action-tracking-stat-label">Type d'indicateur</span>
                <strong class="action-tracking-type">{{ $action->typeActionLabel() }}</strong>
                @unless ($hideExecutorMetrics)
                    <span class="action-tracking-stat-note">{{ $action->isComposee() ? $sousActionsTotal.' sous-action(s)' : 'Action simple' }}</span>
                @endunless
            </article>
        </div>

        @if ($v2IsSubmitted)
            <p class="action-section-note mb-3">Action soumise au chef de service — saisie gelée en attente de sa décision.</p>
        @elseif ($v2IsAwaitingControl)
            <p class="action-section-note mb-3">Visa du chef enregistré — saisie gelée en attente du contrôle final SCIQ/Planification.</p>
        @elseif ($v2IsValidated)
            <p class="action-section-note mb-3">Action validée officiellement par le contrôleur et clôturée.</p>
        @elseif ($v2ValidationStatus === 'correction_demandee')
            <p class="action-section-note action-section-note-warning mb-3">Renvoyée pour correction. Motif : <strong>{{ $action->motif_validation_chef ?: '—' }}</strong></p>
        @elseif ($v2ValidationStatus === 'correction_controle')
            <p class="action-section-note action-section-note-warning mb-3">Correction demandée par le contrôle. Motif : <strong>{{ $action->controle_comment ?: '—' }}</strong></p>
        @endif

        {{-- FORMULAIRE AGENT — action simple (quantitative ou non quantitative).
             Visible tant que l'utilisateur est responsable ; FIGÉ (fieldset disabled)
             dès la soumission, réouvert uniquement après rejet motivé du chef. --}}
        @if (($v2ActionResponsible ?? false))
            @php $v2FormFrozen = ($v2ActionFrozen ?? false); @endphp
            @if ($v2FormFrozen)
                <p class="action-section-note mb-2">Formulaire figé pendant le visa du chef et le contrôle final. Il se rouvre automatiquement en cas de demande de correction.</p>
            @endif
            <form class="mt-2 rounded-2xl border border-[#3996d3]/25 bg-white p-4 shadow-sm @if ($v2FormFrozen) opacity-70 @endif" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.execution.update', $action) }}">
                @csrf
                @error('general') <p class="field-error mb-2">{{ $message }}</p> @enderror
                <fieldset @disabled($v2FormFrozen)>
                <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                    @if ($action->isQuantitative())
                        <div>
                            <label for="qr">Quantité réalisée (totale à ce jour) — quantité à réaliser {{ number_format((float) ($action->quantite_cible ?? 0), 0, ',', ' ') }} {{ $action->unite_cible }}</label>
                            <input id="qr" name="quantite_realisee" type="number" step="1" min="0" value="{{ old('quantite_realisee', $action->quantite_realisee !== null ? (int) $action->quantite_realisee : '') }}">
                        </div>
                    @endif
                    @if ($action->allows_difficulty)
                        <div>
                            <label for="diff">Difficulté rencontrée <span class="text-xs text-slate-400">(optionnel)</span></label>
                            <textarea id="diff" name="difficulte" rows="2">{{ old('difficulte') }}</textarea>
                        </div>
                    @endif
                    <div>
                        <label for="jf">Pièce justificative <span class="text-xs font-semibold text-red-600">*</span> <span class="text-xs text-slate-500">(obligatoire à la soumission)</span></label>
                        <input id="jf" name="justificatif" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}">
                        @if ($action->justificatifs->whereIn('categorie', ['execution_quantitative','execution_non_quantitative','final'])->count() > 0)
                            <p class="mt-1 text-xs text-emerald-600">✓ Pièce déjà déposée — vous pouvez soumettre sans en rajouter.</p>
                        @endif
                    </div>
                </div>
                <div class="mt-3">
                    <label for="cmt" class="font-semibold">Commentaire @if ($action->requires_comment)<span class="text-xs font-semibold text-red-600">*</span>@else<span class="text-xs font-normal text-slate-400">(optionnel)</span>@endif</label>
                    <textarea id="cmt" name="commentaire" rows="3" class="w-full" placeholder="Décrivez l'avancement.">{{ old('commentaire') }}</textarea>
                </div>
                </fieldset>
                @unless ($v2FormFrozen)
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button class="btn btn-secondary" type="submit" name="tracking_action" value="save" formnovalidate>Enregistrer</button>
                        <button class="btn btn-primary" type="submit" name="tracking_action" value="submit">Soumettre au chef</button>
                    </div>
                @endunless
            </form>
        @endif

        {{-- FORMULAIRES AGENT — sous-actions (action composée) --}}
        @if ($action->isComposee())
            <span id="action-weeks" class="block scroll-mt-24" aria-hidden="true"></span>
            <div class="mt-2 space-y-3">
                @forelse ($action->sousActions as $sa)
                    @php
                        $saPerf = app(\App\Services\Workflow\ActionPerformanceCalculator::class)->subActionPerformance($sa);
                        $saValStatus = (string) ($sa->validation_status ?? 'non_soumise');
                        // Éditable uniquement si non soumise ou rejetée (gel après soumission).
                        $canEditSubActionAsResponsible = auth()->check() && $action->isResponsible(auth()->user());
                        $saEditable = ($canTrackSubActionsV2 ?? false)
                            && in_array($saValStatus, ['non_soumise', 'rejetee'], true)
                            && ((int) $sa->agent_id === (int) auth()->id() || $canEditSubActionAsResponsible);
                        $saFrozen = $saValStatus === 'soumise';
                    @endphp
                    <article class="rounded-2xl border border-[#3996d3]/20 bg-white p-4 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <strong>{{ $sa->libelle }}</strong>
                                <span class="ml-2 anbg-badge anbg-badge-info px-2 py-0.5 text-[11px]">{{ $sa->isQuantitative() ? 'Quantitative' : 'Non quantitative' }}</span>
                                @unless ($hideExecutorMetrics)
                                    @if ($sa->weight !== null)<span class="ml-1 text-xs text-slate-500">poids {{ number_format((float) $sa->weight, 0, ',', ' ') }}%</span>@endif
                                @endunless
                                @if ($hideExecutorMetrics)
                                    <p class="text-sm text-slate-600">Réalisé · Statut : <strong>{{ str_replace('_', ' ', $saValStatus) }}</strong></p>
                                @else
                                    <p class="text-sm text-slate-600">Perf : <strong>{{ number_format($saPerf, 0, ',', ' ') }}%</strong> · Statut : <strong>{{ str_replace('_', ' ', $saValStatus) }}</strong></p>
                                @endif
                            </div>
                        </div>

                        @if ($saFrozen && ((int) $sa->agent_id === (int) auth()->id() || $canEditSubActionAsResponsible))
                            <p class="action-section-note mt-2">🔒 Sous-action soumise — figée jusqu'à la décision du chef.</p>
                        @endif

                        @if ($saEditable)
                            <form class="mt-3 border-t border-slate-100 pt-3" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.sub-actions.update', [$action, $sa]) }}">
                                @csrf
                                <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
                                    @if ($sa->isQuantitative())
                                        <div>
                                            <label>Quantité réalisée — quantité à réaliser {{ number_format((float) ($sa->cible_prevue ?? 0), 0, ',', ' ') }} {{ $sa->unite }}</label>
                                            <input name="quantite_realisee" type="number" step="1" min="0" value="{{ $sa->quantite_realisee !== null ? (int) $sa->quantite_realisee : '' }}">
                                        </div>
                                    @endif
                                    @if ($sa->allows_difficulty)
                                        <div><label>Difficulté <span class="text-xs text-slate-400">(opt.)</span></label><textarea name="difficulte" rows="2"></textarea></div>
                                    @endif
                                    <div>
                                        <label>Pièce justificative @if ($sa->requires_proof)<span class="text-xs font-semibold text-red-600">*</span>@endif</label>
                                        <input name="justificatif" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}">
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <label class="font-semibold">Commentaire @if ($sa->requires_comment)<span class="text-xs font-semibold text-red-600">*</span>@else<span class="text-xs text-slate-400">(opt.)</span>@endif</label>
                                    <textarea name="commentaire" rows="2" class="w-full">{{ $sa->commentaire }}</textarea>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button class="btn btn-secondary btn-sm" type="submit" name="tracking_action" value="save" formnovalidate>Enregistrer</button>
                                    <button class="btn btn-primary btn-sm" type="submit" name="tracking_action" value="submit">Soumettre</button>
                                </div>
                            </form>
                        @endif

                        {{-- VALIDATION CHEF par sous-action --}}
                        @if (($canReviewByChefV2 ?? false) && $saValStatus === 'soumise')
                            <div class="mt-3 border-t border-slate-100 pt-3">
                                <form method="POST" action="{{ route('workspace.actions.review', $action) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="sous_action_id" value="{{ $sa->id }}">
                                    <input type="hidden" name="decision" value="valider">
                                    <button class="btn btn-primary btn-sm" type="submit">Valider la sous-action</button>
                                </form>
                                <form method="POST" action="{{ route('workspace.actions.review', $action) }}" class="mt-2 flex flex-wrap items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="sous_action_id" value="{{ $sa->id }}">
                                    <input type="hidden" name="decision" value="rejeter">
                                    <input name="motif" type="text" placeholder="Motif (obligatoire)" required class="flex-1">
                                    <button class="btn btn-secondary btn-sm" type="submit">Renvoyer</button>
                                </form>
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="action-section-note">Aucune sous-action planifiée pour cette action composée.</p>
                @endforelse
            </div>
        @endif

        {{-- VALIDATION CHEF — action soumise --}}
        @if (($canReviewByChefV2 ?? false) && $v2IsSubmitted)
            <div class="mt-3 rounded-lg border border-[#3996d3]/25 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <span class="action-tracking-kicker">Etape 2 sur 3</span>
                        <strong class="block text-sm text-[#17324a]">Visa du chef de service</strong>
                        <p class="mt-1 text-xs text-slate-500">Le taux calculé est proposé automatiquement. Tout ajustement doit être justifié.</p>
                    </div>
                    <span class="anbg-badge anbg-badge-info">Calcul automatique : {{ number_format((float) $v2ProvisionalPerf, 0, ',', ' ') }}%</span>
                </div>
                <form method="POST" action="{{ route('workspace.actions.review', $action) }}" class="mt-3 grid gap-3 md:grid-cols-[160px_1fr_auto] md:items-end">
                    @csrf
                    <input type="hidden" name="decision" value="valider">
                    <div>
                        <label for="chef-progress">Taux proposé (%)</label>
                        <input id="chef-progress" name="progress_percent" type="number" min="0" max="100" step="0.01" value="{{ old('progress_percent', number_format((float) $v2ProvisionalPerf, 2, '.', '')) }}" required>
                    </div>
                    <div>
                        <label for="chef-reason">Observation / justification d'ajustement</label>
                        <input id="chef-reason" name="motif" type="text" value="{{ old('motif') }}" placeholder="Obligatoire si le taux est modifié">
                    </div>
                    <button class="btn btn-primary" type="submit">Viser et transmettre</button>
                </form>
                <form method="POST" action="{{ route('workspace.actions.review', $action) }}" class="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3">
                    @csrf
                    <input type="hidden" name="decision" value="rejeter">
                    <input name="motif" type="text" placeholder="Motif de renvoi (obligatoire)" required class="flex-1">
                    <button class="btn btn-secondary" type="submit">Renvoyer pour correction</button>
                </form>
            </div>
        @endif

        @if (($canReviewByControllerV2 ?? false) && $v2IsAwaitingControl)
            <div class="mt-3 rounded-lg border border-emerald-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <span class="action-tracking-kicker text-emerald-700">Etape 3 sur 3</span>
                        <strong class="block text-sm text-[#17324a]">Décision du contrôleur</strong>
                        <p class="mt-1 text-xs text-slate-500">SCIQ / Planification valide le taux proposé par le chef ou demande une correction.</p>
                    </div>
                    <div class="text-right">
                        <span class="block text-xs font-bold uppercase text-slate-500">Taux proposé par le chef</span>
                        <strong class="text-2xl text-[#17324a]">{{ number_format((float) ($action->chef_progress_percent ?? $v2ProvisionalPerf), 0, ',', ' ') }}%</strong>
                    </div>
                </div>
                @if ($action->chef_adjustment_reason)
                    <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900"><strong>Justification du chef :</strong> {{ $action->chef_adjustment_reason }}</p>
                @endif
                <div class="mt-3 grid gap-3 lg:grid-cols-2">
                    <form method="POST" action="{{ route('workspace.actions.control.review', $action) }}" class="rounded-md border border-emerald-200 bg-emerald-50/50 p-3">
                        @csrf
                        <input type="hidden" name="decision" value="valider">
                        <label for="control-comment">Observation finale <span class="text-xs text-slate-400">(optionnel)</span></label>
                        <textarea id="control-comment" name="motif" rows="2">{{ old('motif') }}</textarea>
                        <button class="btn btn-primary mt-2" type="submit">Viser et transmettre à la planification</button>
                    </form>
                    <form method="POST" action="{{ route('workspace.actions.control.review', $action) }}" class="rounded-md border border-amber-200 bg-amber-50/50 p-3">
                        @csrf
                        <input type="hidden" name="decision" value="rejeter">
                        <label for="control-reason">Motif de correction</label>
                        <textarea id="control-reason" name="motif" rows="2" required>{{ old('motif') }}</textarea>
                        <button class="btn btn-secondary mt-2" type="submit">Demander une correction</button>
                    </form>
                </div>
            </div>
        @endif

        {{-- 3e visa du circuit : validation finale (cloture) par la planification. --}}
        @php
            $isAwaitingPlanification = $validationStatus === 'soumise_planification';
            $canValidatePlanification = auth()->user()?->hasRole(
                \App\Models\User::ROLE_PLANIFICATION,
                \App\Models\User::ROLE_CHEF_PLANIFICATION,
                \App\Models\User::ROLE_ADMIN_FONCTIONNEL,
                \App\Models\User::ROLE_SUPER_ADMIN
            );
        @endphp
        @if ($isAwaitingPlanification && $canValidatePlanification)
            <div class="mt-4 rounded-lg border border-[#3996d3]/30 bg-[#eef6fc]/60 p-4">
                <h3 class="text-base font-black text-[#17324a]">Validation finale — Planification</h3>
                <p class="mt-1 text-sm text-[#667085]">
                    L'action a été visée par le chef de service puis par le contrôle. Votre validation clôture officiellement l'action.
                </p>
                <div class="mt-3 grid gap-3 lg:grid-cols-2">
                    <form method="POST" action="{{ route('workspace.actions.planification.review', $action) }}" class="rounded-md border border-emerald-200 bg-emerald-50/50 p-3">
                        @csrf
                        <input type="hidden" name="decision" value="valider">
                        <label for="planif-comment">Observation finale <span class="text-xs text-slate-400">(optionnel)</span></label>
                        <textarea id="planif-comment" name="motif" rows="2">{{ old('motif') }}</textarea>
                        <button class="btn btn-primary mt-2" type="submit">Valider et clôturer</button>
                    </form>
                    <form method="POST" action="{{ route('workspace.actions.planification.review', $action) }}" class="rounded-md border border-amber-200 bg-amber-50/50 p-3">
                        @csrf
                        <input type="hidden" name="decision" value="rejeter">
                        <label for="planif-reason">Motif de correction</label>
                        <textarea id="planif-reason" name="motif" rows="2" required>{{ old('motif') }}</textarea>
                        <button class="btn btn-secondary mt-2" type="submit">Renvoyer en correction</button>
                    </form>
                </div>
            </div>
        @elseif ($isAwaitingPlanification)
            <p class="mt-4 rounded-lg border border-[#3996d3]/30 bg-[#eef6fc]/60 p-3 text-sm font-semibold text-[#17324a]">
                Action visée par le contrôle — en attente de la validation finale de la planification.
            </p>
        @endif
    </section>

    <section
        id="action-fiche"
        class="showcase-panel action-detail-tab-panel mb-4"
        role="tabpanel"
        aria-labelledby="action-fiche-tab"
        tabindex="0"
        data-action-tab-panel
        hidden
    >
        <h2 class="showcase-panel-title">Fiche complète de l'action</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]">

            {{-- Planification --}}
            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Planification</h3>
                <dl class="action-fiche-dl mt-2">
                    <dt>PAS</dt><dd>{{ $pas?->titre ?? '-' }}</dd>
                    <dt>Période PAS</dt><dd>{{ $pas?->periode_debut ?? '-' }} — {{ $pas?->periode_fin ?? '-' }}</dd>
                    <dt>PAO</dt><dd>{{ $pao?->titre ?? '-' }}{{ $pao?->annee ? ' ('.$pao->annee.')' : '' }}</dd>
                    <dt>Objectif</dt><dd>{{ $objectifOperationnel?->description ?: ($objectifOperationnel?->libelle ?? '-') }}</dd>
                    <dt>PTA</dt><dd>{{ $pta?->titre ?? '-' }}</dd>
                    <dt>Direction</dt><dd>{{ $pta?->direction?->code ?? '-' }} — {{ $pta?->direction?->libelle ?? '-' }}</dd>
                    <dt>Service</dt><dd>{{ $pta?->service?->code ?? '-' }} — {{ $pta?->service?->libelle ?? '-' }}</dd>
                </dl>
            </article>

            {{-- Identification --}}
            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Identification</h3>
                <dl class="action-fiche-dl mt-2">
                    <dt>ID</dt><dd>{{ $action->id }}</dd>
                    <dt>Libellé</dt><dd>{{ $action->libelle }}</dd>
                    <dt>Description</dt><dd>{{ $action->description ?: '-' }}</dd>
                    <dt>Statut métier</dt><dd>{{ $actionStatusLabel($action->statut ?: '-') }}</dd>
                    <dt>Statut</dt>
                    <dd class="dd-badges">
                        <span class="{{ $statusStyles[$action->statut_dynamique ?: 'non_demarre'] ?? 'anbg-badge anbg-badge-neutral' }}">{{ $actionStatusLabel($status) }}</span>
                    </dd>
                    <dt>Validation</dt>
                    <dd class="dd-badges">
                        <span class="{{ $validationStyles[$action->statut_validation ?: 'non_soumise'] ?? 'anbg-badge anbg-badge-neutral' }}">{{ $validationStatusLabel($action->statut_validation ?: 'non_soumise') }}</span>
                    </dd>
                </dl>
            </article>

            {{-- Responsable & échéances --}}
            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Responsable & échéances</h3>
                <dl class="action-fiche-dl mt-2">
                    <dt>Responsable</dt><dd>{{ $action->responsable?->name ?? '-' }}</dd>
                    <dt>RMO</dt><dd>{{ $rmoNames !== [] ? implode(', ', $rmoNames) : '-' }}</dd>
                    <dt>E-mail</dt><dd>{{ $action->responsable?->email ?? '-' }}</dd>
                    <dt>Matricule</dt><dd>{{ $action->responsable?->agent_matricule ?? '-' }}</dd>
                    <dt>Fonction</dt><dd>{{ $action->responsable?->agent_fonction ?? '-' }}</dd>
                    <dt>Téléphone</dt><dd>{{ $action->responsable?->agent_telephone ?? '-' }}</dd>
                    <dt>Début</dt><dd>{{ optional($action->date_debut)->format('d/m/Y') ?: '-' }}</dd>
                    <dt>Fin prévue</dt><dd>{{ optional($action->date_fin)->format('d/m/Y') ?: '-' }}</dd>
                    <dt>Échéance</dt><dd>{{ optional($action->date_echeance)->format('d/m/Y') ?: '-' }}</dd>
                    <dt>Fin réelle</dt><dd>{{ optional($action->date_fin_reelle)->format('d/m/Y') ?: '-' }}</dd>
                </dl>
            </article>

            {{-- Progression --}}
            @if ($hideExecutorMetrics)
            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Execution</h3>
                <dl class="action-fiche-dl mt-2">
                    <dt>Etat</dt><dd>Réalisé</dd>
                    <dt>Validation</dt><dd>{{ $validationLabel }}</dd>
                </dl>
            </article>
            @else
            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Progression</h3>
                <dl class="action-fiche-dl mt-2">
                    <dt>Mode évaluation</dt><dd>{{ $modeEvaluationLabel }}</dd>
                    @if ($usesQuantitativeProgress)
                        <dt>Quantité à réaliser</dt><dd>{{ $action->quantite_cible !== null ? number_format((float) $action->quantite_cible, 0, ',', ' ') : '-' }} {{ $action->unite_cible ?: '' }}</dd>
                        <dt>Unité</dt><dd>{{ $action->unite_cible ?: '-' }}</dd>
                        <dt>Réalisé</dt><dd>{{ $action->quantite_realisee !== null ? number_format((float) $action->quantite_realisee, 0, ',', ' ') : '0' }} {{ $action->unite_cible ?: '' }}</dd>
                        <dt>Reste</dt><dd>{{ number_format((float) ($action->reste_a_realiser ?? $remainingValue), 0, ',', ' ') }} {{ $action->unite_cible ?: '' }}</dd>
                        <dt>Taux de réalisation</dt><dd>{{ number_format((float) ($action->taux_atteinte_cible ?? 0), 0, ',', ' ') }}%</dd>
                        <dt>Dépassement</dt><dd>{{ $overachievementRate > 0 ? '+'.number_format($overachievementRate, 0, ',', ' ').'%' : '-' }}</dd>
                        <dt>Seuil minimum</dt><dd>{{ number_format((float) ($action->seuil_minimum ?? 80), 0, ',', ' ') }}%</dd>
                        <dt>Statut perf.</dt><dd>{{ $performanceLabels[$action->statut_performance ?? 'non_evaluee'] ?? ($action->statut_performance ?: '-') }}</dd>
                    @else
                        <dt>Résultat attendu</dt><dd>{{ $action->resultat_attendu ?: '-' }}</dd>
                        <dt>Critères</dt><dd>{{ $action->criteres_validation ?: '-' }}</dd>
                        <dt>Livrable</dt><dd>{{ $action->livrable_attendu ?: '-' }}</dd>
                        <dt>Avancement sous-act.</dt><dd>{{ number_format((float) ($action->avancement_operationnel ?? $action->progression_reelle ?? 0), 0, ',', ' ') }}%</dd>
                    @endif
                    <dt>Seuil alerte</dt><dd>{{ number_format((float) ($action->seuil_alerte_progression ?? 0), 0, ',', ' ') }}%</dd>
                    <dt>Avancement réel</dt><dd>{{ number_format((float) ($action->progression_reelle ?? 0), 0, ',', ' ') }}%</dd>
                    <dt>Progression théor.</dt><dd>{{ number_format((float) ($action->progression_theorique ?? 0), 0, ',', ' ') }}%</dd>
                </dl>
            </article>
            @endif

            {{-- Ressources --}}
            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Ressources</h3>
                <dl class="action-fiche-dl mt-2">
                    <dt>Mobilisées</dt><dd>{{ $ressources !== [] ? implode(', ', $ressources) : '-' }}</dd>
                    <dt>Détails</dt><dd>{{ $action->ressources_details ?: '-' }}</dd>
                </dl>
            </article>

            {{-- Financement sommaire --}}
            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Financement</h3>
                <dl class="action-fiche-dl mt-2">
                    <dt>Requis</dt><dd>{{ $action->financement_requis ? 'Oui' : 'Non' }}</dd>
                    <dt>Montant estimé</dt><dd>{{ $action->montant_estime !== null ? number_format((float) $action->montant_estime, 0, ',', ' ') : '-' }}</dd>
                    <dt>Nature</dt><dd>{{ $action->nature_financement ?: $action->description_financement ?: '-' }}</dd>
                    <dt>Source</dt><dd>{{ $action->source_financement ?: '-' }}</dd>
                    <dt>Statut</dt>
                    <dd class="dd-badges">
                        <span class="{{ $financingStyles[$financingStatus] ?? 'anbg-badge anbg-badge-neutral' }}">{{ $financingLabel }}</span>
                    </dd>
                    <dt>Commentaire DAF</dt><dd>{{ $action->financement_daf_commentaire ?: '-' }}</dd>
                    <dt>Montant validé DAF</dt><dd>{{ $action->financement_montant_valide !== null ? number_format((float) $action->financement_montant_valide, 0, ',', ' ') : '-' }}</dd>
                </dl>
            </article>

            {{-- Clôture --}}
            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Clôture et évaluation</h3>
                <dl class="action-fiche-dl mt-2">
                    <dt>Rapport final</dt><dd>{{ $action->rapport_final ?: '-' }}</dd>
                    <dt>Motif validation chef</dt><dd>{{ $action->motif_validation_chef ?: '-' }}</dd>
                    <dt>Validation hiérarchique</dt><dd>{{ $action->validation_hierarchique ? 'Oui' : 'Non' }}</dd>
                </dl>
            </article>

        </div>
    </section>

    <section
        id="action-echeances"
        class="showcase-panel action-detail-tab-panel mb-4"
        role="tabpanel"
        aria-labelledby="action-echeances-tab"
        tabindex="0"
        data-action-tab-panel
        data-has-errors="{{ $errors->hasAny(['sous_action_id', 'change_fields', 'requested_deadline', 'requested_libelle', 'requested_responsable_ids', 'requested_date_debut', 'motif', 'justification', 'piece_justificative']) ? 'true' : 'false' }}"
        hidden
    >
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="showcase-panel-title">Demandes de modification</h2>
                <p class="text-sm text-slate-500">Le RMO choisit les paramètres. Circuit obligatoire : Chef de service, Directeur, puis accord final DG et application automatique.</p>
            </div>
            @if ($activeDeadlineExtensionRequest)
                <span class="{{ $deadlineExtensionStatusStyles[$activeDeadlineExtensionRequest->status] ?? 'anbg-badge anbg-badge-neutral' }}">
                    {{ $deadlineExtensionStatusLabels[$activeDeadlineExtensionRequest->status] ?? $activeDeadlineExtensionRequest->status }}
                </span>
            @endif
        </div>

        <div class="mt-4 grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(280px,1fr))]">
            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Demande</h3>
                @if (($canRequestDeadlineExtension ?? false) && ! $activeDeadlineExtensionRequest)
                    <form class="mt-3 space-y-3" method="POST" action="{{ route('workspace.actions.deadline-extension.store', $action) }}" enctype="multipart/form-data">
                        @csrf
                        <x-deadline-change-fields
                            prefix="new_deadline_change"
                            :action="$action"
                            :sub-actions="$deadlineRequestTargets"
                            :selected-sub-action-id="request('report_sous_action_id')"
                            :responsable-options="$deadlineResponsableOptions"
                            :show-target="true"
                        />
                        <div>
                            <label for="deadline_motif">Motif</label>
                            <input id="deadline_motif" name="motif" type="text" value="{{ old('motif') }}" maxlength="255" required>
                        </div>
                        <div>
                            <label for="deadline_justification">Justification detaillee</label>
                            <textarea id="deadline_justification" name="justification" rows="4" required>{{ old('justification') }}</textarea>
                        </div>
                        <div>
                            <label for="piece_justificative">Piece justificative</label>
                            <input id="piece_justificative" name="piece_justificative" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}" required>
                        </div>
                        <button class="btn btn-primary" type="submit">Soumettre la demande</button>
                    </form>
                @elseif ($activeDeadlineExtensionRequest)
                    <p class="mt-3 text-sm text-slate-600">Une demande est deja en cours pour cette action ou l'une de ses sous-actions.</p>
                @else
                    <p class="mt-3 text-sm text-slate-600">Vous n'avez pas les droits pour demander un report sur cette action.</p>
                @endif
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Historique</h3>
                <div class="mt-3 space-y-3">
                    @forelse ($deadlineExtensionRequests as $deadlineRequest)
                        @php
                            $statusLabel = $deadlineExtensionStatusLabels[$deadlineRequest->status] ?? $deadlineRequest->status;
                            $statusStyle = $deadlineExtensionStatusStyles[$deadlineRequest->status] ?? 'anbg-badge anbg-badge-neutral';
                            $canChefReviewThisRequest = ($canReviewDeadlineExtensionByChef ?? false)
                                && in_array((string) $deadlineRequest->status, [
                                    \App\Models\DeadlineExtensionRequest::STATUS_SOUMISE,
                                    \App\Models\DeadlineExtensionRequest::STATUS_EN_ANALYSE,
                                ], true);
                            $canDirectorReviewThisRequest = ($canReviewDeadlineExtensionByDirector ?? false)
                                && (string) $deadlineRequest->status === \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION;
                            $canFinalReviewThisRequest = ($canReviewDeadlineExtensionFinal ?? false)
                                && (string) $deadlineRequest->status === \App\Models\DeadlineExtensionRequest::STATUS_TRANSMISE_DG;
                            $canApplyThisRequest = ($canApplyDeadlineExtension ?? false)
                                && (string) $deadlineRequest->status === \App\Models\DeadlineExtensionRequest::STATUS_APPROUVEE;
                            $canResubmitThisRequest = $currentUser instanceof \App\Models\User
                                && (int) $deadlineRequest->requested_by === (int) $currentUser->id
                                && (string) $deadlineRequest->status === \App\Models\DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE;
                            $deadlineMetadata = is_array($deadlineRequest->metadata) ? $deadlineRequest->metadata : [];
                            $deadlineRequestedChanges = is_array($deadlineRequest->requested_changes) ? $deadlineRequest->requested_changes : [];
                            $revisionCount = (int) ($deadlineMetadata['revision_count'] ?? 0);
                            $revisionHistory = is_array($deadlineMetadata['revision_history'] ?? null)
                                ? $deadlineMetadata['revision_history']
                                : [];
                            $complementComment = $deadlineRequest->final_decision === \App\Models\DeadlineExtensionRequest::DECISION_COMPLEMENT
                                ? $deadlineRequest->final_comment
                                : ($deadlineRequest->director_decision === \App\Models\DeadlineExtensionRequest::AVIS_COMPLEMENT
                                    ? $deadlineRequest->director_comment
                                    : $deadlineRequest->chef_comment);
                        @endphp
                        <div class="rounded border border-slate-200 p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-slate-800">{{ $deadlineRequest->sousAction?->libelle ?? 'Action principale' }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ optional($deadlineRequest->old_deadline)->format('d/m/Y') ?: '-' }}
                                        vers
                                        {{ optional($deadlineRequest->requested_deadline)->format('d/m/Y') ?: '-' }}
                                    </p>
                                </div>
                                <span class="{{ $statusStyle }}">{{ $statusLabel }}</span>
                            </div>
                            <p class="mt-2 text-sm text-slate-600">{{ $deadlineRequest->motif }}</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @forelse (array_keys($deadlineRequestedChanges) as $changeField)
                                    <span class="anbg-badge anbg-badge-neutral">{{ $deadlineChangeFieldLabels[$changeField] ?? $changeField }}</span>
                                @empty
                                    <span class="anbg-badge anbg-badge-neutral">Échéance · ancien dossier</span>
                                @endforelse
                            </div>
                            <a class="btn btn-secondary btn-sm mt-3" href="{{ route('workspace.deadline-extension.show', $deadlineRequest) }}">Ouvrir le dossier</a>
                            <dl class="action-fiche-dl mt-2">
                                <dt>Demandeur</dt><dd>{{ $deadlineRequest->requestedBy?->name ?? '-' }}</dd>
                                <dt>Pièce justificative</dt><dd><a class="font-semibold text-[#1e5fa8]" href="{{ route('workspace.deadline-extension.attachment', $deadlineRequest) }}">{{ $deadlineRequest->attachment_name ?: 'Télécharger' }}</a></dd>
                                @if ($revisionHistory !== [])
                                    <dt>Pièces antérieures</dt>
                                    <dd class="space-y-1">
                                        @foreach ($revisionHistory as $revisionIndex => $revision)
                                            <a class="block font-semibold text-[#1e5fa8]" href="{{ route('workspace.deadline-extension.attachment.revision', [$deadlineRequest, $revisionIndex]) }}">
                                                Version {{ $revisionIndex + 1 }} · {{ $revision['previous_attachment_name'] ?? 'Pièce justificative' }}
                                            </a>
                                        @endforeach
                                    </dd>
                                @endif
                                <dt>Révisions</dt><dd>{{ $revisionCount }}</dd>
                                <dt>Avis chef</dt><dd>{{ $deadlineRequest->chef_avis ?: '-' }}{{ $deadlineRequest->chefReviewedBy ? ' · '.$deadlineRequest->chefReviewedBy->name : '' }}</dd>
                                <dt>Accord directeur</dt><dd>{{ $deadlineRequest->director_decision ?: '-' }}{{ $deadlineRequest->directorReviewedBy ? ' · '.$deadlineRequest->directorReviewedBy->name : '' }}</dd>
                                <dt>Accord final DG</dt><dd>{{ $deadlineRequest->final_decision ?: $deadlineRequest->dg_decision ?: '-' }}{{ $deadlineRequest->finalDecidedBy ? ' · '.$deadlineRequest->finalDecidedBy->name : '' }}</dd>
                                <dt>Échéance approuvee</dt><dd>{{ optional($deadlineRequest->approved_deadline)->format('d/m/Y') ?: '-' }}</dd>
                                <dt>Application</dt><dd>{{ $deadlineRequest->appliedBy?->name ?? '-' }}{{ $deadlineRequest->applied_at ? ' · '.$deadlineRequest->applied_at->format('d/m/Y H:i') : '' }}</dd>
                            </dl>

                            @if ($canResubmitThisRequest)
                                <form class="mt-3 space-y-3 rounded-md border border-amber-300 bg-amber-50 p-3" method="POST" action="{{ route('workspace.deadline-extension.resubmit', $deadlineRequest) }}" enctype="multipart/form-data">
                                    @csrf
                                    <div>
                                        <p class="text-sm font-semibold text-amber-950">Complément demandé</p>
                                        <p class="mt-1 text-sm text-amber-900">{{ $complementComment ?: 'Veuillez compléter le dossier et joindre une nouvelle pièce justificative.' }}</p>
                                    </div>
                                    <x-deadline-change-fields
                                        prefix="resubmit_{{ $deadlineRequest->id }}"
                                        :action="$action"
                                        :selected-sub-action-id="$deadlineRequest->sous_action_id"
                                        :changes="$deadlineRequestedChanges"
                                        :responsable-options="$deadlineResponsableOptions"
                                    />
                                    <div>
                                        <label for="resubmit_motif_{{ $deadlineRequest->id }}">Motif actualisé</label>
                                        <input id="resubmit_motif_{{ $deadlineRequest->id }}" name="motif" type="text" value="{{ old('motif', $deadlineRequest->motif) }}" maxlength="255" required>
                                    </div>
                                    <div>
                                        <label for="resubmit_justification_{{ $deadlineRequest->id }}">Justification complétée</label>
                                        <textarea id="resubmit_justification_{{ $deadlineRequest->id }}" name="justification" rows="4" required>{{ old('justification', $deadlineRequest->justification) }}</textarea>
                                    </div>
                                    <div>
                                        <label for="resubmit_piece_{{ $deadlineRequest->id }}">Nouvelle pièce justificative</label>
                                        <input id="resubmit_piece_{{ $deadlineRequest->id }}" name="piece_justificative" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}" required>
                                    </div>
                                    <button class="btn btn-primary" type="submit">Compléter et retransmettre</button>
                                </form>
                            @endif

                            @if ($canChefReviewThisRequest)
                                <form class="mt-3 space-y-2 rounded-md border border-slate-200 bg-slate-50 p-3" method="POST" action="{{ route('workspace.deadline-extension.chef', $deadlineRequest) }}">
                                    @csrf
                                    <label for="deadline_chef_decision_{{ $deadlineRequest->id }}">Avis du chef de service</label>
                                    <select id="deadline_chef_decision_{{ $deadlineRequest->id }}" name="decision" required>
                                        <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_FAVORABLE }}">Avis favorable</option>
                                        <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_DEFAVORABLE }}">Avis defavorable</option>
                                        <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_COMPLEMENT }}">Demander complement</option>
                                    </select>
                                    <textarea name="comment" rows="2" placeholder="Commentaire"></textarea>
                                    <button class="btn btn-secondary" type="submit">Enregistrer l'avis</button>
                                </form>
                            @endif

                            @if ($canDirectorReviewThisRequest)
                                <form class="mt-3 space-y-2 rounded-md border border-blue-200 bg-blue-50/50 p-3" method="POST" action="{{ route('workspace.deadline-extension.direction', $deadlineRequest) }}">
                                    @csrf
                                    <label for="deadline_controller_decision_{{ $deadlineRequest->id }}">Décision du directeur</label>
                                    <select id="deadline_controller_decision_{{ $deadlineRequest->id }}" name="decision" required>
                                        <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_FAVORABLE }}">Avis favorable</option>
                                        <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_DEFAVORABLE }}">Avis défavorable</option>
                                        <option value="{{ \App\Models\DeadlineExtensionRequest::AVIS_COMPLEMENT }}">Demander un complément</option>
                                    </select>
                                    <textarea name="comment" rows="2" placeholder="Commentaire"></textarea>
                                    <button class="btn btn-secondary" type="submit">Transmettre à la DG</button>
                                </form>
                            @endif

                            @if ($canFinalReviewThisRequest)
                                <form class="mt-3 space-y-2 rounded-md border border-amber-200 bg-amber-50/50 p-3" method="POST" action="{{ route('workspace.deadline-extension.final', $deadlineRequest) }}">
                                    @csrf
                                    <label for="deadline_final_decision_{{ $deadlineRequest->id }}">Décision finale DG</label>
                                    <select id="deadline_final_decision_{{ $deadlineRequest->id }}" name="decision" required>
                                        <option value="{{ \App\Models\DeadlineExtensionRequest::DECISION_APPROUVER }}">Approuver</option>
                                        <option value="{{ \App\Models\DeadlineExtensionRequest::DECISION_REJETER }}">Rejeter</option>
                                        <option value="{{ \App\Models\DeadlineExtensionRequest::DECISION_COMPLEMENT }}">Demander complement</option>
                                    </select>
                                    <textarea name="comment" rows="2" placeholder="Commentaire"></textarea>
                                    <button class="btn btn-primary" type="submit">Décider et appliquer</button>
                                </form>
                            @endif

                            @if ($canApplyThisRequest)
                                <form class="mt-3 space-y-2 rounded-md border border-emerald-200 bg-emerald-50/50 p-3" method="POST" action="{{ route('workspace.deadline-extension.apply', $deadlineRequest) }}">
                                    @csrf
                                    <p class="text-sm font-semibold text-emerald-900">Ancienne décision approuvée en attente d’application par la DG.</p>
                                    <button class="btn btn-primary" type="submit">Appliquer la modification</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-600">Aucune demande de report n'a encore ete soumise.</p>
                    @endforelse
                </div>
            </article>
        </div>
    </section>

    <section
        id="action-financement"
        class="showcase-panel action-detail-tab-panel mb-4"
        role="tabpanel"
        aria-labelledby="action-financement-tab"
        tabindex="0"
        data-action-tab-panel
        data-has-errors="{{ $errors->hasAny(['source_financement', 'justificatif_financement', 'commentaire_financement', 'decision_financement', 'montant_valide', 'reference_financement', 'justificatif_financement_daf', 'justificatif_financement_dg']) ? 'true' : 'false' }}"
        hidden
    >
        <h2 class="showcase-panel-title">Financement et validation budgétaire</h2>
        @if ($action->financement_requis)
            <div class="mb-4 grid min-h-20 grid-cols-2 overflow-hidden border-y border-slate-200 md:grid-cols-4 dark:border-slate-700" aria-label="Circuit de validation du financement">
                @foreach ([
                    1 => ['RMO', 'Dossier et pieces'],
                    2 => ['DAF', 'Instruction financiere'],
                    3 => ['DG', 'Decision finale'],
                    4 => ['Resultat', 'Accorde ou refuse'],
                ] as $stepNumber => [$stepRole, $stepLabel])
                    <div class="flex min-w-0 items-center gap-3 border-b border-r border-slate-200 px-3 py-3 last:border-r-0 md:border-b-0 dark:border-slate-700 {{ $financingStage >= $stepNumber ? 'bg-emerald-50/80 dark:bg-emerald-950/25' : 'bg-slate-50/70 dark:bg-slate-900/60' }}">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-bold {{ $financingStage >= $stepNumber ? 'bg-emerald-700 text-white' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-200' }}">{{ $stepNumber }}</span>
                        <span class="min-w-0">
                            <strong class="block truncate text-sm text-slate-950 dark:text-white">{{ $stepRole }}</strong>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $stepLabel }}</span>
                        </span>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
                <article class="min-w-0 border-l-2 border-sky-500 pl-4">
                    <h3 class="form-section-title">Besoin déclaré</h3>
                    <p class="mt-2 text-slate-600">Statut financement : <strong>{{ $financingLabel }}</strong></p>
                    <p class="text-slate-600">Montant estimé : <strong>{{ $action->montant_estime !== null ? number_format((float) $action->montant_estime, 0) : '-' }}</strong></p>
                    <p class="text-slate-600">Nature : <strong>{{ $action->nature_financement ?: $action->description_financement ?: '-' }}</strong></p>
                    <p class="text-slate-600">Source : <strong>{{ $action->source_financement ?: '-' }}</strong></p>
                    <p class="text-slate-600">Commentaire : <strong>{{ $action->commentaire_financement ?: '-' }}</strong></p>
                    <p class="text-slate-600">Pièce justificative : <strong>
                        @if ($financingJustificatif)
                            <button
                                class="text-[#3996d3] font-semibold"
                                type="button"
                                data-preview-file
                                data-preview-title="{{ $financingJustificatif->nom_original }}"
                                data-preview-subtitle="{{ $financingJustificatif->mime_type ?: 'Document justificatif' }}"
                                data-preview-mime="{{ $financingJustificatif->mime_type }}"
                                data-preview-url="{{ route('workspace.actions.justificatifs.preview', [$action, $financingJustificatif]) }}"
                                data-download-url="{{ route('workspace.actions.justificatifs.download', [$action, $financingJustificatif]) }}"
                            >Visualiser</button>
                        @else
                            -
                        @endif
                    </strong></p>
                    <p class="text-slate-600">Soumis au circuit : <strong>{{ optional($action->financement_soumis_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
                    <p class="text-slate-600">Notification DAF : <strong>{{ optional($action->financement_notifie_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
                </article>
                <article class="min-w-0 border-l-2 border-emerald-500 pl-4">
                    <h3 class="form-section-title">Décision DAF</h3>
                    <p class="mt-2 text-slate-600">Responsable DAF : <strong>{{ $action->financementDafPar?->name ?? '-' }}</strong></p>
                    <p class="text-slate-600">Date de décision : <strong>{{ optional($action->financement_daf_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
                    <p class="text-slate-600">Décision : <strong>{{ $action->financement_daf_decision ?: '-' }}</strong></p>
                    <p class="text-slate-600">Montant validé : <strong>{{ $action->financement_montant_valide !== null ? number_format((float) $action->financement_montant_valide, 0) : '-' }}</strong></p>
                    <p class="text-slate-600">Référence : <strong>{{ $action->financement_reference ?: '-' }}</strong></p>
                    <p class="text-slate-600">Commentaire : <strong>{{ $action->financement_daf_commentaire ?: '-' }}</strong></p>
                    @if ($financingDafJustificatif)
                        <button
                            class="mt-2 font-semibold text-[#3996d3]"
                            type="button"
                            data-preview-file
                            data-preview-title="{{ $financingDafJustificatif->nom_original }}"
                            data-preview-subtitle="Avis DAF"
                            data-preview-mime="{{ $financingDafJustificatif->mime_type }}"
                            data-preview-url="{{ route('workspace.actions.justificatifs.preview', [$action, $financingDafJustificatif]) }}"
                            data-download-url="{{ route('workspace.actions.justificatifs.download', [$action, $financingDafJustificatif]) }}"
                        >Voir la pièce DAF</button>
                    @endif
                </article>
                <article class="min-w-0 border-l-2 border-violet-500 pl-4">
                    <h3 class="form-section-title">Accord DG</h3>
                    <p class="mt-2 text-slate-600">Décideur DG : <strong>{{ $action->financementDgPar?->name ?? '-' }}</strong></p>
                    <p class="text-slate-600">Date accord / refus : <strong>{{ optional($action->financement_dg_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
                    <p class="text-slate-600">Décision : <strong>{{ $action->financement_dg_decision ?: '-' }}</strong></p>
                    <p class="text-slate-600">Commentaire DG : <strong>{{ $action->financement_dg_commentaire ?: '-' }}</strong></p>
                    @if ($financingDgJustificatif)
                        <button
                            class="mt-2 font-semibold text-[#3996d3]"
                            type="button"
                            data-preview-file
                            data-preview-title="{{ $financingDgJustificatif->nom_original }}"
                            data-preview-subtitle="Décision DG"
                            data-preview-mime="{{ $financingDgJustificatif->mime_type }}"
                            data-preview-url="{{ route('workspace.actions.justificatifs.preview', [$action, $financingDgJustificatif]) }}"
                            data-download-url="{{ route('workspace.actions.justificatifs.download', [$action, $financingDgJustificatif]) }}"
                        >Voir la pièce DG</button>
                    @endif
                </article>
            </div>

            @if ($canSubmitFinancing ?? false)
                @php
                    $requiresFinancingCorrectionProof = in_array($financingStatus, [
                        \App\Models\Action::FINANCEMENT_COMPLEMENT_DEMANDE,
                        \App\Models\Action::FINANCEMENT_REJETE_DAF,
                    ], true);
                @endphp
                <form class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-700" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.financement.submit', $action) }}">
                    @csrf
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                            {{ $requiresFinancingCorrectionProof ? 'Correction du dossier financier' : 'Soumission du dossier financier' }}
                        </h3>
                        <span class="anbg-badge {{ $requiresFinancingCorrectionProof ? 'anbg-badge-warning' : 'anbg-badge-info' }} px-2 py-1 text-xs">
                            Action attendue du RMO
                        </span>
                    </div>
                    @if ($requiresFinancingCorrectionProof && $action->financement_daf_commentaire)
                        <div class="mb-3 border-l-4 border-amber-500 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
                            <strong>Dernier avis DAF :</strong> {{ $action->financement_daf_commentaire }}
                        </div>
                    @endif
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label for="source_financement_rmo">Source de financement</label>
                            <input id="source_financement_rmo" name="source_financement" type="text" maxlength="255" value="{{ old('source_financement', $action->source_financement) }}" required>
                        </div>
                        <div>
                            <label for="justificatif_financement_rmo">
                                {{ $requiresFinancingCorrectionProof ? 'Nouvelle pièce corrective' : 'Pièce complémentaire' }}
                            </label>
                            <input id="justificatif_financement_rmo" name="justificatif_financement" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}" @if($requiresFinancingCorrectionProof) required @endif>
                        </div>
                        <div class="md:col-span-2">
                            <label for="commentaire_financement_rmo">Note de transmission du RMO</label>
                            <textarea id="commentaire_financement_rmo" name="commentaire_financement" maxlength="3000" required>{{ old('commentaire_financement', $action->commentaire_financement) }}</textarea>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit">
                        {{ $requiresFinancingCorrectionProof ? 'Resoumettre à la DAF' : 'Soumettre à la DAF' }}
                    </button>
                </form>
            @endif

            @if ($canReviewFinancingByDaf)
                <form class="mt-4" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.financement.daf', $action) }}">
                    @csrf
                    <h3 class="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Instruction et avis DAF</h3>
                    <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                        <div>
                            <label for="decision_financement_daf">Décision DAF</label>
                            <select id="decision_financement_daf" name="decision_financement" required>
                                <option value="valider" @selected(old('decision_financement') === 'valider')>Valider et transmettre à la DG</option>
                                <option value="demander_complement" @selected(old('decision_financement') === 'demander_complement')>Demander un complément</option>
                                <option value="rejeter" @selected(old('decision_financement') === 'rejeter')>Rejeter</option>
                            </select>
                        </div>
                        <div>
                            <label for="montant_valide">Montant validé</label>
                            <input id="montant_valide" name="montant_valide" type="number" step="0.01" min="0.01" value="{{ old('montant_valide', $action->montant_estime) }}">
                        </div>
                        <div>
                            <label for="reference_financement">Référence financement</label>
                            <input id="reference_financement" name="reference_financement" type="text" value="{{ old('reference_financement', $action->financement_reference) }}">
                        </div>
                        <div>
                            <label for="justificatif_financement_daf">Pièce DAF (optionnel)</label>
                            <input id="justificatif_financement_daf" name="justificatif_financement_daf" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}">
                        </div>
                    </div>
                    <div>
                        <label for="commentaire_financement_daf">Motivation de l'avis DAF</label>
                        <textarea id="commentaire_financement_daf" name="commentaire_financement" maxlength="3000" required>{{ old('commentaire_financement') }}</textarea>
                    </div>
                    <button class="btn btn-primary mt-2.5" type="submit">Enregistrer la décision DAF</button>
                </form>
            @endif

            @if ($canReviewFinancingByDg)
                <form class="mt-4" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.financement.dg', $action) }}">
                    @csrf
                    <h3 class="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Décision finale de la Direction Générale</h3>
                    <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                        <div>
                            <label for="decision_financement_dg">Décision DG</label>
                            <select id="decision_financement_dg" name="decision_financement" required>
                                <option value="accorder" @selected(old('decision_financement') === 'accorder')>Accorder</option>
                                <option value="refuser" @selected(old('decision_financement') === 'refuser')>Refuser</option>
                            </select>
                        </div>
                        <div>
                            <label for="justificatif_financement_dg">Pièce DG (optionnel)</label>
                            <input id="justificatif_financement_dg" name="justificatif_financement_dg" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}">
                        </div>
                    </div>
                    <div>
                        <label for="commentaire_financement_dg">Motivation de la décision DG</label>
                        <textarea id="commentaire_financement_dg" name="commentaire_financement" maxlength="3000" required>{{ old('commentaire_financement') }}</textarea>
                    </div>
                    <button class="btn btn-primary mt-2.5" type="submit">Enregistrer la décision DG</button>
                </form>
            @endif
        @else
            <p class="text-slate-600">Cette action ne nécessite pas de financement spécifique.</p>
        @endif
    </section>

    <section
        id="action-discussion"
        class="showcase-panel action-detail-tab-panel mb-4"
        role="tabpanel"
        aria-labelledby="action-discussion-tab"
        tabindex="0"
        data-action-tab-panel
        data-has-errors="{{ $errors->has('message') ? 'true' : 'false' }}"
        hidden
    >
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="showcase-panel-title">Discussion et retours de validation</h2>
            <span class="discussion-live-badge" title="Les nouveaux commentaires s'affichent automatiquement">
                <span class="discussion-live-dot" aria-hidden="true"></span>
                En direct
            </span>
        </div>
        <form method="POST" action="{{ route('workspace.actions.comment', $action) }}" class="mb-5">
            @csrf
            <label for="discussion_message">Ajouter un commentaire</label>
            <textarea id="discussion_message" name="message" rows="3" placeholder="Votre commentaire ou retour…" required>{{ old('message') }}</textarea>
            <div class="mt-2.5 flex items-center gap-3">
                <button class="btn btn-primary" type="submit">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-1px;margin-right:4px;" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Publier
                </button>
            </div>
        </form>

        <div id="discussion-feed" class="space-y-3">
            @forelse ($discussionEntries as $entry)
                <article class="showcase-thread-item" data-log-id="{{ $entry->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="font-semibold">{{ $entry->utilisateur?->name ?? 'Système' }}</p>
                            <p class="text-xs text-slate-500">{{ optional($entry->created_at)->format('d/m/Y H:i') ?: '-' }}</p>
                        </div>
                        <span class="anbg-badge anbg-badge-neutral px-3">{{ \App\Support\UiLabel::eventType($entry->type_evenement) }}</span>
                    </div>
                    <p class="mt-3 whitespace-pre-line text-slate-700">{{ $entry->message }}</p>
                </article>
            @empty
                <div id="discussion-empty">
                    <x-ui.empty-state
                        title="Aucun commentaire"
                        message="Aucun commentaire ni retour de validation pour le moment."
                        icon="inbox"
                        tone="neutral"
                    />
                </div>
            @endforelse
        </div>
    </section>
    <script>
    (function () {
        var logsUrl = @json(route('v1.actions.logs', $action));
        var feed = document.getElementById('discussion-feed');
        var lastLogId = {{ $discussionEntries->last()?->id ?? 0 }};
        var validTypes = ['commentaire','action_soumise_validation','action_validee_chef','action_rejetee_chef','action_correction_demandee',
                          'action_transmise_controle','action_validee_controle','action_rejetee_controle',
                          'action_validee_direction','action_rejetee_direction','financement_demande','financement_prepare',
                          'financement_soumis_daf','financement_resoumis_daf','financement_valide_daf',
                          'financement_complement_demande','financement_rejete_daf','financement_accord_dg','financement_refus_dg'];

        function textElement(tagName, className, value) {
            var element = document.createElement(tagName);
            element.className = className;
            element.textContent = value || '';

            return element;
        }

        function renderEntry(entry) {
            var badge = (entry.type_evenement || '').replace(/_/g, ' ');
            var author = (entry.utilisateur && entry.utilisateur.name) ? entry.utilisateur.name : 'Système';
            var date = entry.created_at ? new Date(entry.created_at).toLocaleString('fr-FR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
            var el = document.createElement('article');
            var header = document.createElement('div');
            var authorBlock = document.createElement('div');

            el.className = 'showcase-thread-item discussion-new-entry';
            el.setAttribute('data-log-id', entry.id);
            header.className = 'flex flex-wrap items-start justify-between gap-2';
            authorBlock.appendChild(textElement('p', 'font-semibold', author));
            authorBlock.appendChild(textElement('p', 'text-xs text-slate-500', date));
            header.appendChild(authorBlock);
            header.appendChild(textElement('span', 'anbg-badge anbg-badge-neutral px-3', badge));
            el.appendChild(header);
            el.appendChild(textElement('p', 'mt-3 whitespace-pre-line text-slate-700', entry.message || ''));

            return el;
        }

        function pollDiscussion() {
            fetch(logsUrl + '?per_page=100', {
                headers: {'Accept': 'application/json'},
            }).then(function (res) { return res.ok ? res.json() : null; })
            .then(function (json) {
                if (!json) return;
                var entries = (json.data && json.data.data ? json.data.data : [])
                    .filter(function (e) { return validTypes.indexOf(e.type_evenement) !== -1 && e.id > lastLogId; })
                    .sort(function (a, b) { return a.id - b.id; });
                if (!entries.length) return;
                var empty = document.getElementById('discussion-empty');
                if (empty) empty.remove();
                entries.forEach(function (entry) {
                    if (!feed.querySelector('[data-log-id="' + entry.id + '"]')) {
                        feed.appendChild(renderEntry(entry));
                        lastLogId = Math.max(lastLogId, entry.id);
                        var author = (entry.utilisateur && entry.utilisateur.name) ? entry.utilisateur.name : 'Système';
                        if (window.anbgNotify) window.anbgNotify(
                            'Nouveau commentaire — ' + author,
                            entry.message ? entry.message.slice(0, 120) : '',
                            'discussion-' + entry.id,
                            null
                        );
                    }
                });
            }).catch(function () {});
        }
        setInterval(pollDiscussion, 30000);
    })();
    </script>

    <section
        id="action-justificatifs"
        class="showcase-panel action-detail-tab-panel mb-4"
        role="tabpanel"
        aria-labelledby="action-justificatifs-tab"
        tabindex="0"
        data-action-tab-panel
        hidden
    >
        <h2 class="showcase-panel-title">Justificatifs action</h2>
        @php
            $fileTypeIcon = static function (string $name): string {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                return match (true) {
                    in_array($ext, ['pdf'], true)                          => 'PDF',
                    in_array($ext, ['doc', 'docx'], true)                 => 'DOC',
                    in_array($ext, ['xls', 'xlsx', 'csv'], true)          => 'XLS',
                    in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) => 'IMG',
                    in_array($ext, ['zip', 'rar', '7z'], true)            => 'ZIP',
                    default                                                => 'FILE',
                };
            };
            $isImage = static fn (string $name): bool => in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
        @endphp
        @forelse ($action->justificatifs as $doc)
            @php
                $docContext = $doc->sousAction?->libelle
                    ?: ($doc->actionWeek?->libelle_sous_action
                        ?: ($doc->actionWeek ? 'Période ' . $doc->actionWeek->numero_semaine : null));
                $docCategory = $justificatifCategoryLabels[$doc->categorie] ?? $doc->categorie;
                $previewUrl   = route('workspace.actions.justificatifs.preview', [$action, $doc]);
                $downloadUrl  = route('workspace.actions.justificatifs.download', [$action, $doc]);
            @endphp
            <div class="justificatif-card">
                <div class="justificatif-card-icon">
                    <span aria-hidden="true">{{ $fileTypeIcon($doc->nom_original ?? '') }}</span>
                </div>
                <div class="justificatif-card-body">
                    <p class="justificatif-card-name">{{ $doc->nom_original }}</p>
                    <p class="justificatif-card-meta">
                        <span class="anbg-badge anbg-badge-info px-2 py-0.5 text-[10px]">{{ $docCategory }}</span>
                        @if ($docContext)
                            <span class="text-[#667085]">{{ $docContext }}</span>
                        @endif
                    </p>
                    <p class="justificatif-card-author">
                        {{ $doc->ajoutePar?->name ?? '-' }}
                        <span class="text-[#667085]">·</span>
                        {{ optional($doc->created_at)->format('d/m/Y H:i') }}
                    </p>
                </div>
                <div class="justificatif-card-actions">
                    <button
                        class="btn btn-primary btn-sm rounded-xl"
                        type="button"
                        data-preview-file
                        data-preview-title="{{ $doc->nom_original }}"
                        data-preview-subtitle="{{ $docCategory }}"
                        data-preview-mime="{{ $doc->mime_type }}"
                        data-preview-url="{{ $previewUrl }}"
                        data-download-url="{{ $downloadUrl }}"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        Visualiser
                    </button>
                    <a class="rounded-xl border border-[#3996d3]/30 px-3 py-1.5 text-xs font-bold text-[#3996d3] hover:bg-[#e8f3fb] flex items-center gap-1" href="{{ $downloadUrl }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Télécharger
                    </a>
                </div>
            </div>
        @empty
            <x-ui.empty-state
                title="Aucun justificatif"
                message="Aucun justificatif n'a encore été importé sur cette action."
                icon="file"
                tone="neutral"
            />
        @endforelse
    </section>

    <section
        id="action-logs"
        class="showcase-panel action-detail-tab-panel mb-4"
        role="tabpanel"
        aria-labelledby="action-logs-tab"
        tabindex="0"
        data-action-tab-panel
        hidden
    >
        <h2 class="showcase-panel-title">Journal d'alertes et événements</h2>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Niveau</th>
                        <th>Type</th>
                        <th>Message</th>
                        <th>Destinataire</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($action->actionLogs as $log)
                        <tr>
                            <td>{{ optional($log->created_at)->format('d/m/Y H:i') }}</td>
                            <td>{{ $alertLevelLabels[$log->niveau] ?? \App\Support\UiLabel::alertLevel($log->niveau) }}</td>
                            <td>{{ \App\Support\UiLabel::eventType($log->type_evenement) }}</td>
                            <td>{{ $log->message }}</td>
                            <td>{{ $log->cible_role ? \App\Support\UiLabel::roleAudience($log->cible_role) : '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state
                                    title="Aucun événement"
                                    message="Les alertes et événements de suivi apparaîtront ici."
                                    icon="clock"
                                    tone="info"
                                    class="my-4"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
