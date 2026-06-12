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
        // Suivi hebdomadaire supprime : labels de frequence/periode retires.
        $frequenceLabel = '-';
        $periodeLabelSingulier = 'Periode';
        $periodeLabelPluriel = 'periodes';
        $validationStatus = (string) ($action->statut_validation ?: 'non_soumise');
        $validationStatusLabels = is_array($validationStatusLabels ?? null) ? $validationStatusLabels : [];
        $justificatifCategoryLabels = is_array($justificatifCategoryLabels ?? null) ? $justificatifCategoryLabels : [];
        $alertLevelLabels = is_array($alertLevelLabels ?? null) ? $alertLevelLabels : [];
        $validationLabel = $validationStatusLabels[$validationStatus] ?? $validationStatusLabel($validationStatus);
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
            'chain_label' => 'Agent -> Chef de service',
            'submission_help_text' => "L'action est revue par le chef de service. Le directeur est notifié et conserve la lecture du dossier.",
            'submission_button_label' => 'Soumettre',
            'service_review_button_label' => 'Valider la clôture',
            'service_review_success_text' => 'Action validée par le chef de service.',
            'final_statistics_hint' => 'Oui après validation finale du chef de service.',
            'rejection_comment_required' => true,
        ];
        $agentLocked = auth()->check()
            && (int) auth()->id() === (int) $action->responsable_id
            && !in_array($validationStatus, ['non_soumise', 'correction_demandee', 'rejetee_chef', 'rejetee_direction'], true);
        $isAwaitingChef = $workflow['service_enabled'] && $validationStatus === 'soumise_chef';
        // L'etape « validation direction » a ete supprimee du circuit metier.
        // Les statuts `validee_direction` / `rejetee_direction` ne sont
        // conserves qu'en lecture historique (actions cloturees avant la
        // suppression). Voir routes/web.php pour les stubs 410.
        $ressources = $action->resourceLabels();
        $financingJustificatif = $action->justificatifs->firstWhere('categorie', 'financement');
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
                'action_validee_direction',
                'action_rejetee_direction',
                'financement_demande',
                'financement_valide_daf',
                'financement_rejete_daf',
                'financement_accord_dg',
                'financement_refus_dg',
            ], true))
            ->sortBy('created_at')
            ->values();
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
            'action-financement' => 'Financement',
            'action-status' => 'Avancement',
            'action-controle' => 'Controle',
            'action-discussion' => 'Discussion',
            'action-justificatifs' => 'Justificatifs',
            'action-logs' => 'Journal',
        ];
        if ($showSubActionsPanel) {
            $detailSections = array_slice($detailSections, 0, 4, true)
                + ['action-weeks' => $usesHistoricalProgress ? 'Suivi historique' : 'Sous-actions']
                + array_slice($detailSections, 4, null, true);
        }
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
            'cible_depassee' => 'Cible depassee',
        ];
        $quantitativeStatusLabels = [
            'non_demarre' => 'Non demarree',
            'faible_avancement' => 'Faible avancement',
            'en_progression' => 'En progression',
            'presque_atteinte' => 'Presque atteinte',
            'cible_atteinte' => 'Cible atteinte',
            'cible_depassee' => 'Cible depassee',
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
    @endphp

    <section id="action-header" class="action-detail-hero mb-4">
        <div class="action-detail-hero-body">
            <div class="action-detail-copy">
                <span class="action-detail-eyebrow">Action {{ $action->code ?? $action->id }}</span>
                <h1 class="action-detail-title">{{ $action->libelle }}</h1>
                <div class="action-detail-meta-grid">
                    <span class="action-detail-meta">
                        <span class="action-detail-meta-label">Periode</span>
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
                <nav class="action-detail-tabs no-print" aria-label="Sections de l'action">
                    @foreach ($detailSections as $anchor => $label)
                        <a class="action-detail-tab" href="#{{ $anchor }}">{{ $label }}</a>
                    @endforeach
                </nav>
            </div>
            <div class="action-detail-actions no-print">
                @php
                    $isModificationLocked = (bool) ($isActionModificationLocked ?? false);
                    $canRequestUnlock = (bool) ($canRequestActionUnlock ?? false);
                    $canProcessUnlock = (bool) ($canProcessActionUnlock ?? false);
                @endphp
                @if (! $isModificationLocked && $canManageAction)
                    <a class="btn btn-warning" href="{{ $action->pta_id ? route('workspace.pta.edit', $action->pta_id).'#action-'.$action->id : route('workspace.actions.edit', $action) }}">Modifier action</a>
                @elseif ($isModificationLocked)
                    @if ($canProcessUnlock)
                        <a class="btn btn-warning" href="{{ route('workspace.planning-unlocks.index') }}">
                            Traiter le deverrouillage
                        </a>
                    @endif

                    @if ($canRequestUnlock)
                        @include('workspace.planning-unlocks._request-inline', [
                            'target' => $action,
                            'route' => route('workspace.actions.unlock-requests.store', $action),
                            'context' => 'Modification action demandee depuis la page de suivi',
                        ])
                    @endif
                @endif
                <button type="button" onclick="window.print()" class="btn btn-secondary flex items-center gap-2" title="Imprimer la fiche action">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Imprimer
                </button>
                <a class="btn btn-secondary" href="{{ route('workspace.actions.index') }}">Retour liste</a>
            </div>
        </div>
    </section>

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
            'cible_atteinte' => ['Cible atteinte', 'anbg-badge-success'],
            'cible_depassee' => ['Cible dépassée', 'anbg-badge-success'],
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
        $v2IsValidated = $v2ValidationStatus === 'validee_chef';
    @endphp

    <section id="action-suivi" class="action-tracking-panel mb-4">
        <div class="action-tracking-head">
            <div>
                <span class="action-tracking-kicker">Execution</span>
                <h2 class="action-tracking-title">Suivi de l'action</h2>
            </div>
            <div class="action-tracking-badges">
                <span class="anbg-badge {{ $perfClass }} px-3 py-1">{{ $perfLabel }}</span>
                <span class="anbg-badge {{ $tempClass }} px-3 py-1">{{ $tempLabel }}</span>
            </div>
        </div>

        {{-- Performances : officielle en avant, provisoire en complément --}}
        <div class="action-tracking-metrics">
            <article class="action-tracking-stat action-tracking-stat-main">
                <span class="action-tracking-stat-label">Performance officielle</span>
                <strong class="action-tracking-stat-value">{{ number_format((float) $v2OfficialPerf, 0, ',', ' ') }}%</strong>
                <span class="action-tracking-stat-note">{{ $v2IsValidated ? 'Validée par le chef' : 'En attente de validation' }}</span>
            </article>
            <article class="action-tracking-stat">
                <span class="action-tracking-stat-label">Performance provisoire</span>
                <strong class="action-tracking-stat-value">{{ number_format((float) $v2ProvisionalPerf, 0, ',', ' ') }}%</strong>
                <span class="action-tracking-stat-note">Calculée à chaque enregistrement</span>
            </article>
            <article class="action-tracking-stat">
                <span class="action-tracking-stat-label">Type d'action</span>
                <strong class="action-tracking-type">{{ $action->typeActionLabel() }}</strong>
                <span class="action-tracking-stat-note">{{ $action->isComposee() ? $sousActionsTotal.' sous-action(s)' : 'Action simple' }}</span>
            </article>
        </div>

        @if ($v2IsSubmitted)
            <p class="action-section-note mb-3">Action soumise au chef de service — saisie gelée en attente de sa décision.</p>
        @elseif ($v2IsValidated)
            <p class="action-section-note mb-3">Action validée officiellement par le chef de service.</p>
        @elseif ($v2ValidationStatus === 'correction_demandee')
            <p class="action-section-note action-section-note-warning mb-3">Renvoyée pour correction. Motif : <strong>{{ $action->motif_validation_chef ?: '—' }}</strong></p>
        @endif

        {{-- FORMULAIRE AGENT — action simple (quantitative ou non quantitative).
             Visible tant que l'utilisateur est responsable ; FIGÉ (fieldset disabled)
             dès la soumission, réouvert uniquement après rejet motivé du chef. --}}
        @if (($v2ActionResponsible ?? false))
            @php $v2FormFrozen = ($v2ActionFrozen ?? false); @endphp
            @if ($v2FormFrozen)
                <p class="action-section-note mb-2">🔒 Formulaire figé — l'action est soumise. Il se rouvrira uniquement si le chef de service la renvoie pour correction avec motif.</p>
            @endif
            <form class="mt-2 rounded-2xl border border-[#3996d3]/25 bg-white p-4 shadow-sm @if ($v2FormFrozen) opacity-70 @endif" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.execution.update', $action) }}">
                @csrf
                @error('general') <p class="field-error mb-2">{{ $message }}</p> @enderror
                <fieldset @disabled($v2FormFrozen)>
                <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                    @if ($action->isQuantitative())
                        <div>
                            <label for="qr">Quantité réalisée (totale à ce jour) — cible {{ number_format((float) ($action->quantite_cible ?? 0), 0, ',', ' ') }} {{ $action->unite_cible }}</label>
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
            <div class="mt-2 space-y-3">
                @forelse ($action->sousActions as $sa)
                    @php
                        $saPerf = app(\App\Services\Workflow\ActionPerformanceCalculator::class)->subActionPerformance($sa);
                        $saValStatus = (string) ($sa->validation_status ?? 'non_soumise');
                        // Éditable uniquement si non soumise ou rejetée (gel après soumission).
                        $saEditable = ($canTrackSubActionsV2 ?? false)
                            && in_array($saValStatus, ['non_soumise', 'rejetee'], true)
                            && (int) $sa->agent_id === (int) auth()->id();
                        $saFrozen = $saValStatus === 'soumise';
                    @endphp
                    <article class="rounded-2xl border border-[#3996d3]/20 bg-white p-4 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <strong>{{ $sa->libelle }}</strong>
                                <span class="ml-2 anbg-badge anbg-badge-info px-2 py-0.5 text-[11px]">{{ $sa->isQuantitative() ? 'Quantitative' : 'Non quantitative' }}</span>
                                @if ($sa->weight !== null)<span class="ml-1 text-xs text-slate-500">poids {{ number_format((float) $sa->weight, 0, ',', ' ') }}%</span>@endif
                                <p class="text-sm text-slate-600">Perf : <strong>{{ number_format($saPerf, 0, ',', ' ') }}%</strong> · Statut : <strong>{{ str_replace('_', ' ', $saValStatus) }}</strong></p>
                            </div>
                        </div>

                        @if ($saFrozen && (int) $sa->agent_id === (int) auth()->id())
                            <p class="action-section-note mt-2">🔒 Sous-action soumise — figée jusqu'à la décision du chef.</p>
                        @endif

                        @if ($saEditable)
                            <form class="mt-3 border-t border-slate-100 pt-3" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.sub-actions.update', [$action, $sa]) }}">
                                @csrf
                                <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
                                    @if ($sa->isQuantitative())
                                        <div>
                                            <label>Quantité réalisée — cible {{ number_format((float) ($sa->cible_prevue ?? 0), 0, ',', ' ') }} {{ $sa->unite }}</label>
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

        {{-- VALIDATION CHEF — action simple soumise --}}
        @if (($canReviewByChefV2 ?? false) && ! $action->isComposee() && $v2IsSubmitted)
            <div class="mt-3 rounded-2xl border border-[#3996d3]/20 bg-white p-4 shadow-sm">
                <strong class="text-sm text-[#17324a]">Décision du chef de service</strong>
                <form method="POST" action="{{ route('workspace.actions.review', $action) }}" class="mt-2 flex flex-wrap items-end gap-2">
                    @csrf
                    <input type="hidden" name="decision" value="valider">
                    <button class="btn btn-primary" type="submit">Valider l'action</button>
                </form>
                <form method="POST" action="{{ route('workspace.actions.review', $action) }}" class="mt-2 flex flex-wrap items-end gap-2">
                    @csrf
                    <input type="hidden" name="decision" value="rejeter">
                    <input name="motif" type="text" placeholder="Motif de renvoi (obligatoire)" required class="flex-1">
                    <button class="btn btn-secondary" type="submit">Renvoyer pour correction</button>
                </form>
            </div>
        @endif
    </section>

    <section id="action-fiche" class="showcase-panel mb-4">
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
                    <dt>Fréquence</dt><dd>{{ $frequenceLabel }}</dd>
                </dl>
            </article>

            {{-- Progression --}}
            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Progression</h3>
                <dl class="action-fiche-dl mt-2">
                    <dt>Mode évaluation</dt><dd>{{ $modeEvaluationLabel }}</dd>
                    @if ($usesQuantitativeProgress)
                        <dt>Cible attendue</dt><dd>{{ $action->quantite_cible !== null ? number_format((float) $action->quantite_cible, 0, ',', ' ') : '-' }} {{ $action->unite_cible ?: '' }}</dd>
                        <dt>Unité</dt><dd>{{ $action->unite_cible ?: '-' }}</dd>
                        <dt>Réalisé</dt><dd>{{ $action->quantite_realisee !== null ? number_format((float) $action->quantite_realisee, 0, ',', ' ') : '0' }} {{ $action->unite_cible ?: '' }}</dd>
                        <dt>Reste</dt><dd>{{ number_format((float) ($action->reste_a_realiser ?? $remainingValue), 0, ',', ' ') }} {{ $action->unite_cible ?: '' }}</dd>
                        <dt>Taux cible</dt><dd>{{ number_format((float) ($action->taux_atteinte_cible ?? 0), 0, ',', ' ') }}%</dd>
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

    <section id="action-financement" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Financement et validation budgétaire</h2>
        @if ($action->financement_requis)
            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
                <article class="showcase-inline-stat action-detail-card">
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
                <article class="showcase-inline-stat action-detail-card">
                    <h3 class="form-section-title">Décision DAF</h3>
                    <p class="mt-2 text-slate-600">Responsable DAF : <strong>{{ $action->financementDafPar?->name ?? '-' }}</strong></p>
                    <p class="text-slate-600">Date de décision : <strong>{{ optional($action->financement_daf_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
                    <p class="text-slate-600">Décision : <strong>{{ $action->financement_daf_decision ?: '-' }}</strong></p>
                    <p class="text-slate-600">Montant validé : <strong>{{ $action->financement_montant_valide !== null ? number_format((float) $action->financement_montant_valide, 0) : '-' }}</strong></p>
                    <p class="text-slate-600">Référence : <strong>{{ $action->financement_reference ?: '-' }}</strong></p>
                    <p class="text-slate-600">Commentaire : <strong>{{ $action->financement_daf_commentaire ?: '-' }}</strong></p>
                </article>
                <article class="showcase-inline-stat action-detail-card">
                    <h3 class="form-section-title">Accord DG</h3>
                    <p class="mt-2 text-slate-600">Décideur DG : <strong>{{ $action->financementDgPar?->name ?? '-' }}</strong></p>
                    <p class="text-slate-600">Date accord / refus : <strong>{{ optional($action->financement_dg_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
                    <p class="text-slate-600">Décision : <strong>{{ $action->financement_dg_decision ?: '-' }}</strong></p>
                    <p class="text-slate-600">Commentaire DG : <strong>{{ $action->financement_dg_commentaire ?: '-' }}</strong></p>
                </article>
            </div>

            @if ($canReviewFinancingByDaf)
                <form class="mt-4" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.financement.daf', $action) }}">
                    @csrf
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Traitement DAF</h3>
                    <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                        <div>
                            <label for="decision_financement_daf">Décision DAF</label>
                            <select id="decision_financement_daf" name="decision_financement" required>
                                <option value="valider" @selected(old('decision_financement') === 'valider')>Valider et transmettre à la DG</option>
                                <option value="demander_complement" @selected(old('decision_financement') === 'demander_complement')>Demander un complement</option>
                                <option value="rejeter" @selected(old('decision_financement') === 'rejeter')>Rejeter</option>
                            </select>
                        </div>
                        <div>
                            <label for="montant_valide">Montant validé</label>
                            <input id="montant_valide" name="montant_valide" type="number" step="1" min="0" value="{{ old('montant_valide', $action->montant_estime !== null ? (int) round((float) $action->montant_estime) : '') }}">
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
                        <label for="commentaire_financement_daf">Commentaire DAF (obligatoire au rejet ou complement)</label>
                        <textarea id="commentaire_financement_daf" name="commentaire_financement">{{ old('commentaire_financement') }}</textarea>
                    </div>
                    <button class="btn btn-primary mt-2.5" type="submit">Enregistrer la décision DAF</button>
                </form>
            @endif

            @if ($canReviewFinancingByDg)
                <form class="mt-4" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.financement.dg', $action) }}">
                    @csrf
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Accord DG</h3>
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
                        <label for="commentaire_financement_dg">Commentaire DG (obligatoire au refus)</label>
                        <textarea id="commentaire_financement_dg" name="commentaire_financement">{{ old('commentaire_financement') }}</textarea>
                    </div>
                    <button class="btn btn-primary mt-2.5" type="submit">Enregistrer l'accord DG</button>
                </form>
            @endif
        @else
            <p class="text-slate-600">Cette action ne nécessite pas de financement spécifique.</p>
        @endif
    </section>

    {{-- Sections workflow opérationnel SUPPRIMÉES le 2026-05-31 (refonte en cours) :
         - action-status (État d'avancement)
         - action-weeks (Sous-actions / suivi périodique)
         - action-review-chef (Vérification chef de service)
         - action-controle (Contrôle et anomalies)
         À reconstruire from scratch quand le nouveau workflow sera spécifié. --}}


    <section id="action-discussion" class="showcase-panel mb-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="showcase-panel-title">Discussion et retours de validation</h2>
            <span class="discussion-live-badge" title="Les nouveaux commentaires s'affichent automatiquement">
                <span class="discussion-live-dot" aria-hidden="true"></span>
                En direct
            </span>
        </div>
        <form id="discussion-form" method="POST" action="{{ route('workspace.actions.comment', $action) }}" class="mb-5">
            @csrf
            <label for="discussion_message">Ajouter un commentaire</label>
            <textarea id="discussion_message" name="message" rows="3" placeholder="Votre commentaire ou retour…" required>{{ old('message') }}</textarea>
            <div class="mt-2.5 flex items-center gap-3">
                <button id="discussion-submit" class="btn btn-primary" type="submit">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-1px;margin-right:4px;" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Publier
                </button>
                <span id="discussion-sending" class="text-xs text-slate-400" style="display:none;">Envoi en cours…</span>
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
                        <span class="anbg-badge anbg-badge-neutral px-3">{{ str_replace('_', ' ', $entry->type_evenement) }}</span>
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
        var actionId = {{ $action->id }};
        var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        var form = document.getElementById('discussion-form');
        var feed = document.getElementById('discussion-feed');
        var textarea = document.getElementById('discussion_message');
        var submitBtn = document.getElementById('discussion-submit');
        var sendingLabel = document.getElementById('discussion-sending');
        var lastLogId = {{ $discussionEntries->last()?->id ?? 0 }};
        var validTypes = ['commentaire','action_soumise_validation','action_validee_chef','action_rejetee_chef','action_correction_demandee',
                          'action_validee_direction','action_rejetee_direction','financement_demande',
                          'financement_valide_daf','financement_rejete_daf','financement_accord_dg','financement_refus_dg'];

        function renderEntry(entry) {
            var badge = (entry.type_evenement || '').replace(/_/g, ' ');
            var author = (entry.utilisateur && entry.utilisateur.name) ? entry.utilisateur.name : 'Système';
            var date = entry.created_at ? new Date(entry.created_at).toLocaleString('fr-FR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
            var el = document.createElement('article');
            el.className = 'showcase-thread-item discussion-new-entry';
            el.setAttribute('data-log-id', entry.id);
            el.innerHTML = '<div class="flex flex-wrap items-start justify-between gap-2">'
                + '<div><p class="font-semibold">' + author + '</p>'
                + '<p class="text-xs text-slate-500">' + date + '</p></div>'
                + '<span class="anbg-badge anbg-badge-neutral px-3">' + badge + '</span>'
                + '</div><p class="mt-3 whitespace-pre-line text-slate-700">' + (entry.message || '') + '</p>';
            return el;
        }

        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var message = textarea.value.trim();
                if (!message) return;
                submitBtn.disabled = true;
                sendingLabel.style.display = 'inline';
                fetch('/api/actions/' + actionId + '/comments', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json'},
                    body: JSON.stringify({message: message}),
                }).then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                }).then(function (json) {
                    var empty = document.getElementById('discussion-empty');
                    if (empty) empty.remove();
                    var el = renderEntry(json.data);
                    feed.appendChild(el);
                    lastLogId = Math.max(lastLogId, json.data.id || 0);
                    textarea.value = '';
                    if (window.anbgToast) window.anbgToast('Commentaire publié.', 'success', 3000);
                }).catch(function () {
                    if (window.anbgToast) window.anbgToast("Erreur lors de l'envoi. Réessayez.", 'error', 5000);
                }).finally(function () {
                    submitBtn.disabled = false;
                    sendingLabel.style.display = 'none';
                });
            });
        }

        function pollDiscussion() {
            fetch('/api/actions/' + actionId + '/logs?per_page=100', {
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken},
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

    <section id="action-justificatifs" class="showcase-panel mb-4">
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

    <section id="action-logs" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Journal d'alertes et événements</h2>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Niveau</th>
                        <th>Type</th>
                        <th>Message</th>
                        <th>Cible</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($action->actionLogs as $log)
                        <tr>
                            <td>{{ optional($log->created_at)->format('Y-m-d H:i') }}</td>
                            <td>{{ $alertLevelLabels[$log->niveau] ?? $log->niveau }}</td>
                            <td>{{ $log->type_evenement }}</td>
                            <td>{{ $log->message }}</td>
                            <td>{{ $log->cible_role ?: '-' }}</td>
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
