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
    @endphp

    <section id="action-header" class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div class="max-w-4xl">
                <span class="showcase-eyebrow">Action {{ $action->code ?? $action->id }}</span>
                <h1 class="showcase-title">{{ $action->libelle }}</h1>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        Fréquence : {{ $frequenceLabel }}
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#3996d3]"></span>
                        Période : {{ optional($action->date_debut)->format('d/m/Y') ?: '-' }} → {{ optional($action->date_fin)->format('d/m/Y') ?: '-' }}
                    </span>
                    <span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold {{ $statusClass }}">
                        {{ $actionStatusLabel($status) }}
                    </span>
                    <span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold {{ $validationClass }}">
                        {{ $validationLabel }}
                    </span>
                    @if ($action->financement_requis)
                        <span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold {{ $financingClass }}">
                            Financement: {{ $financingLabel }}
                        </span>
                    @endif
                </div>
                <div class="showcase-nav-pills">
                    @foreach ($detailSections as $anchor => $label)
                        <a class="showcase-nav-pill" href="#{{ $anchor }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            <div class="showcase-action-row">
                @if ($canManageAction)
                    @php
                        $lockService = app(\App\Services\PlanningModificationLockService::class);
                        $isModificationLocked = $lockService->isLocked($action);
                        $canRequestUnlock = auth()->check() && $lockService->canRequestUnlock(auth()->user(), $action);
                    @endphp
                    @if (! $isModificationLocked)
                        <a class="btn btn-warning rounded-2xl px-4 py-2.5" href="{{ $action->pta_id ? route('workspace.pta.edit', $action->pta_id).'#action-'.$action->id : route('workspace.actions.edit', $action) }}">Modifier action</a>
                    @elseif ($canRequestUnlock)
                        @include('workspace.planning-unlocks._request-inline', [
                            'target' => $action,
                            'route' => route('workspace.actions.unlock-requests.store', $action),
                            'context' => 'Modification action demandee depuis la page de suivi',
                        ])
                    @endif
                @endif
                <button type="button" onclick="window.print()" class="btn btn-secondary rounded-2xl px-4 py-2.5 flex items-center gap-2 no-print" title="Imprimer la fiche action">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Imprimer
                </button>
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5 no-print" href="{{ route('workspace.actions.index') }}">Retour liste</a>
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Avancement déclaré</p>
            <p class="showcase-kpi-number">{{ number_format($progressionDeclaree, 1) }}%</p>
            <div class="mt-3 showcase-progress-track">
                <span class="showcase-progress-bar {{ $progressionDeclaree >= 80 ? 'bg-[#8fc043]' : ($progressionDeclaree >= 50 ? 'bg-blue-500' : 'bg-[#f0e509]') }}" style="width: {{ $progressionDeclaree }}%"></span>
            </div>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Progression théorique</p>
            <p class="showcase-kpi-number">{{ number_format($progressionTheorique, 1) }}%</p>
            <div class="mt-3 showcase-progress-track">
                <span class="showcase-progress-bar bg-slate-400" style="width: {{ $progressionTheorique }}%"></span>
            </div>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Performance d'exécution</p>
            <p class="showcase-kpi-number">{{ number_format((float) ($kpi?->kpi_performance ?? 0), 1) }}%</p>
            <p class="showcase-kpi-meta">
                Délai {{ number_format((float) ($kpi?->kpi_delai ?? 0), 1) }} |
                Global {{ number_format((float) ($kpi?->kpi_global ?? 0), 1) }}
            </p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Sous-actions suivies</p>
            <p class="showcase-kpi-number">{{ $sousActionsDone }}/{{ $sousActionsTotal }}</p>
            <p class="showcase-kpi-meta">Sous-actions planifiées</p>
        </article>
    </section>

    <section id="action-validation" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Circuit de validation</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
            <article class="showcase-inline-stat action-detail-card">
                <h3 class="form-section-title">Étape 1 — Soumission agent</h3>
                <p class="mt-2 text-slate-600 flex flex-wrap items-center gap-2">Statut :
                    @if (in_array($validationStatus, ['non_soumise', 'correction_demandee', 'rejetee_chef', 'rejetee_direction'], true))
                        <span class="anbg-badge anbg-badge-warning px-3">À corriger</span>
                    @else
                        <span class="anbg-badge anbg-badge-success px-3">Effectuée</span>
                    @endif
                </p>
                <p class="text-slate-600">Soumis par : <strong>{{ $action->soumisPar?->name ?? '-' }}</strong></p>
                <p class="text-slate-600">Date de soumission : <strong>{{ optional($action->soumise_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
            </article>
            @if ($workflow['service_enabled'])
                <article class="showcase-inline-stat action-detail-card">
                    <h3 class="form-section-title">Étape 2 — Vérification chef de service</h3>
                    <p class="mt-2 text-slate-600">Statut : <strong>{{ in_array($validationStatus, ['validee_chef', 'rejetee_direction', 'validee_direction'], true) ? 'Effectuée' : ($isAwaitingChef ? 'En attente' : '-') }}</strong></p>
                    <p class="text-slate-600">Vérificateur : <strong>{{ $action->evaluePar?->name ?? '-' }}</strong></p>
                    <p class="text-slate-600">Date : <strong>{{ optional($action->evalue_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
                </article>
            @endif
        </div>
        @if ($validationStatus === 'rejetee_chef')
            <p class="mt-2 text-sm text-[#f9b13c]">Motif rejet chef : <strong>{{ $action->motif_validation_chef ?: '-' }}</strong></p>
        @endif

    </section>

    <section id="reports-echeance" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Reports d'échéance</h2>

        @if ($canRequestDeadlineExtension ?? false)
            <form class="mt-3 grid gap-3 rounded-2xl border border-[#3996d3]/20 bg-white p-4 shadow-sm" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.deadline-extension.store', $action) }}">
                @csrf
                <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                    <div>
                        <label for="report_target">Élément concerné</label>
                        <select id="report_target" name="sous_action_id">
                            <option value="">Action principale - {{ optional($action->date_fin)->format('d/m/Y') ?: '-' }}</option>
                            @foreach ($action->sousActions as $sousAction)
                                <option value="{{ $sousAction->id }}">Sous-action : {{ $sousAction->libelle }} - {{ optional($sousAction->date_fin)->format('d/m/Y') ?: '-' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="requested_deadline">Nouvelle échéance demandée</label>
                        <input id="requested_deadline" name="requested_deadline" type="date" required>
                    </div>
                    <div>
                        <label for="report_attachment">Pièce justificative</label>
                        <input id="report_attachment" name="report_attachment" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}" required>
                    </div>
                </div>
                <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]">
                    <div>
                        <label for="report_motif">Motif</label>
                        <textarea id="report_motif" name="motif" rows="3" required>{{ old('motif') }}</textarea>
                    </div>
                    <div>
                        <label for="report_justification">Justification détaillée</label>
                        <textarea id="report_justification" name="justification" rows="3" required>{{ old('justification') }}</textarea>
                    </div>
                </div>
                <button class="btn btn-primary justify-self-start" type="submit">Demander report d'échéance</button>
            </form>
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2 pr-3">Élément</th>
                        <th class="py-2 pr-3">Ancienne date</th>
                        <th class="py-2 pr-3">Date demandée</th>
                        <th class="py-2 pr-3">Statut</th>
                        <th class="py-2 pr-3">Circuit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($action->deadlineExtensionRequests as $deadlineRequest)
                        <tr class="border-b align-top">
                            <td class="py-3 pr-3">
                                {{ $deadlineRequest->sousAction?->libelle ?: $action->libelle }}
                                @if ($deadlineRequest->is_critical)
                                    <span class="anbg-badge anbg-badge-warning ml-1 px-2 py-0.5 text-[11px]">Critique</span>
                                @endif
                            </td>
                            <td class="py-3 pr-3">{{ optional($deadlineRequest->old_deadline)->format('d/m/Y') }}</td>
                            <td class="py-3 pr-3">{{ optional($deadlineRequest->requested_deadline)->format('d/m/Y') }}</td>
                            <td class="py-3 pr-3">{{ str_replace('_', ' ', (string) $deadlineRequest->status) }}</td>
                            <td class="py-3 pr-3">
                                @if (($canReviewDeadlineExtensionBySciq ?? false) && in_array((string) $deadlineRequest->status, ['soumise', 'en_analyse', 'complement_demande'], true))
                                    <form method="POST" action="{{ route('workspace.deadline-extension.sciq', $deadlineRequest) }}" class="mb-2 flex flex-wrap gap-2">
                                        @csrf
                                        <select name="sciq_avis" required>
                                            <option value="avis_favorable">Avis favorable</option>
                                            <option value="avis_defavorable">Avis défavorable</option>
                                            <option value="demande_complement">Demander complément</option>
                                        </select>
                                        <input name="sciq_comment" type="text" placeholder="Commentaire SCIQ / Planification">
                                        <button class="btn btn-secondary" type="submit">Enregistrer avis</button>
                                    </form>
                                @endif
                                @if (($canReviewDeadlineExtensionByDg ?? false) && (string) $deadlineRequest->status === 'transmise_dg')
                                    <form method="POST" action="{{ route('workspace.deadline-extension.dg', $deadlineRequest) }}" class="flex flex-wrap gap-2">
                                        @csrf
                                        <select name="dg_decision" required>
                                            <option value="approuver">Approuver</option>
                                            <option value="rejeter">Rejeter</option>
                                            <option value="demander_complement">Demander complément</option>
                                        </select>
                                        <input name="approved_deadline" type="date" value="{{ optional($deadlineRequest->requested_deadline)->toDateString() }}">
                                        <input name="dg_comment" type="text" placeholder="Commentaire DG">
                                        <button class="btn btn-primary" type="submit">Décider</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-4 text-slate-500">Aucune demande de report enregistrée.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
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
                        <dt>Cible attendue</dt><dd>{{ $action->quantite_cible !== null ? number_format((float) $action->quantite_cible, 1, ',', ' ') : '-' }} {{ $action->unite_cible ?: '' }}</dd>
                        <dt>Unité</dt><dd>{{ $action->unite_cible ?: '-' }}</dd>
                        <dt>Réalisé</dt><dd>{{ $action->quantite_realisee !== null ? number_format((float) $action->quantite_realisee, 1, ',', ' ') : '0,0' }} {{ $action->unite_cible ?: '' }}</dd>
                        <dt>Reste</dt><dd>{{ number_format((float) ($action->reste_a_realiser ?? $remainingValue), 1, ',', ' ') }} {{ $action->unite_cible ?: '' }}</dd>
                        <dt>Taux cible</dt><dd>{{ number_format((float) ($action->taux_atteinte_cible ?? 0), 1, ',', ' ') }}%</dd>
                        <dt>Dépassement</dt><dd>{{ $overachievementRate > 0 ? '+'.number_format($overachievementRate, 1, ',', ' ').'%' : '-' }}</dd>
                        <dt>Seuil minimum</dt><dd>{{ number_format((float) ($action->seuil_minimum ?? 80), 1, ',', ' ') }}%</dd>
                        <dt>Statut perf.</dt><dd>{{ $performanceLabels[$action->statut_performance ?? 'non_evaluee'] ?? ($action->statut_performance ?: '-') }}</dd>
                    @else
                        <dt>Résultat attendu</dt><dd>{{ $action->resultat_attendu ?: '-' }}</dd>
                        <dt>Critères</dt><dd>{{ $action->criteres_validation ?: '-' }}</dd>
                        <dt>Livrable</dt><dd>{{ $action->livrable_attendu ?: '-' }}</dd>
                        <dt>Avancement sous-act.</dt><dd>{{ number_format((float) ($action->avancement_operationnel ?? $action->progression_reelle ?? 0), 1, ',', ' ') }}%</dd>
                    @endif
                    <dt>Seuil alerte</dt><dd>{{ number_format((float) ($action->seuil_alerte_progression ?? 0), 1, ',', ' ') }}%</dd>
                    <dt>Avancement réel</dt><dd>{{ number_format((float) ($action->progression_reelle ?? 0), 1, ',', ' ') }}%</dd>
                    <dt>Progression théor.</dt><dd>{{ number_format((float) ($action->progression_theorique ?? 0), 1, ',', ' ') }}%</dd>
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
                    <dt>Montant estimé</dt><dd>{{ $action->montant_estime !== null ? number_format((float) $action->montant_estime, 2, ',', ' ') : '-' }}</dd>
                    <dt>Nature</dt><dd>{{ $action->nature_financement ?: $action->description_financement ?: '-' }}</dd>
                    <dt>Source</dt><dd>{{ $action->source_financement ?: '-' }}</dd>
                    <dt>Statut</dt>
                    <dd class="dd-badges">
                        <span class="{{ $financingStyles[$financingStatus] ?? 'anbg-badge anbg-badge-neutral' }}">{{ $financingLabel }}</span>
                    </dd>
                    <dt>Commentaire DAF</dt><dd>{{ $action->financement_daf_commentaire ?: '-' }}</dd>
                    <dt>Montant validé DAF</dt><dd>{{ $action->financement_montant_valide !== null ? number_format((float) $action->financement_montant_valide, 2, ',', ' ') : '-' }}</dd>
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
                    <p class="text-slate-600">Montant estimé : <strong>{{ $action->montant_estime !== null ? number_format((float) $action->montant_estime, 2) : '-' }}</strong></p>
                    <p class="text-slate-600">Nature : <strong>{{ $action->nature_financement ?: $action->description_financement ?: '-' }}</strong></p>
                    <p class="text-slate-600">Source : <strong>{{ $action->source_financement ?: '-' }}</strong></p>
                    <p class="text-slate-600">Commentaire : <strong>{{ $action->commentaire_financement ?: '-' }}</strong></p>
                    <p class="text-slate-600">Pièce justificative : <strong>
                        @if ($financingJustificatif)
                            <a class="text-[#3996d3]" target="_blank" rel="noopener" href="{{ route('workspace.actions.justificatifs.preview', [$action, $financingJustificatif]) }}">Visualiser</a>
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
                    <p class="text-slate-600">Montant validé : <strong>{{ $action->financement_montant_valide !== null ? number_format((float) $action->financement_montant_valide, 2) : '-' }}</strong></p>
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
                            <input id="montant_valide" name="montant_valide" type="number" step="0.01" min="0" value="{{ old('montant_valide', $action->montant_estime) }}">
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
    <section id="action-status" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">État d'avancement</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            <article class="showcase-inline-stat">
                <strong>Avancement réel</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($action->progression_reelle ?? 0), 1, ',', ' ') }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>Progression théorique</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($action->progression_theorique ?? 0), 1, ',', ' ') }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>{{ $metricLabel('delai') }}</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_delai ?? 0), 1, ',', ' ') }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>{{ $metricLabel('performance') }}</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_performance ?? 0), 1, ',', ' ') }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>Validation</strong>
                <p class="mt-2 dd-badges"><span class="{{ $validationClass }}">{{ $validationLabel }}</span></p>
            </article>
            <article class="showcase-inline-stat">
                <strong>Justificatif</strong>
                <p class="mt-1 text-lg">{{ $action->justificatifs->count() }} piece(s)</p>
            </article>
        </div>
        @if ($showActionExecutionForm)
            <form class="mt-4 rounded-2xl border border-[#3996d3]/25 bg-white p-4 shadow-sm" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.execution.update', $action) }}">
                @csrf
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-base font-bold text-[#1c203d]">{{ $isActionQuantifiable ? 'Saisie quantitative' : 'Soumission d execution' }}</h3>
                        <p class="text-sm text-slate-600">Mode : {{ $modeEvaluationLabel }}. La cible est définie dans le PTA.</p>
                    </div>
                    @if ($isActionQuantifiable)
                        <span class="rounded-full bg-[#3996d3]/10 px-3 py-1 text-xs font-semibold text-[#3996d3]">
                            Cible {{ $action->quantite_cible !== null ? number_format((float) $action->quantite_cible, 1, ',', ' ') : '-' }} {{ $action->unite_cible }}
                        </span>
                    @endif
                </div>
                <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                    @if ($actionSubmissionRequirements['quantity'])
                    <div>
                        <label for="quantite_realisee_action">Quantité réalisée</label>
                        <input id="quantite_realisee_action" name="quantite_realisee" type="number" step="0.0001" min="0" value="{{ old('quantite_realisee') }}">
                    </div>
                    @endif
                    <div>
                        <label for="commentaire_quantitatif">Commentaire d'avancement</label>
                        <textarea id="commentaire_quantitatif" name="commentaire_quantitatif">{{ old('commentaire_quantitatif') }}</textarea>
                    </div>
                    <div>
                        <label for="difficultes_quantitatives">Difficultes rencontrees</label>
                        <textarea id="difficultes_quantitatives" name="difficultes_quantitatives" @if($actionSubmissionRequirements['difficulties']) required @endif>{{ old('difficultes_quantitatives') }}</textarea>
                    </div>
                    <div>
                        <label for="justificatif_quantitatif">Pièce justificative</label>
                        <input id="justificatif_quantitatif" name="justificatif_quantitatif" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}">
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button class="btn btn-secondary" type="submit" name="tracking_action" value="save" formnovalidate>Enregistrer</button>
                    <button class="btn btn-primary" type="submit" name="tracking_action" value="submit">Soumettre au chef</button>
                </div>
            </form>
        @elseif ($usesStructuredProgress && ($usesQuantitativeProgress || $usesNoQuantityProgress || ($usesSubTasksProgress && $sousActionsTotal === 0)) && $agentLocked)
            <p class="action-section-note mt-4">Saisie gelée : action soumise. Le formulaire de suivi sera de nouveau disponible après rejet motivé.</p>
        @endif
    </section>

    @if ($showSubActionsPanel)
    <section id="action-weeks" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">{{ $usesHistoricalProgress ? "Suivi périodique de l'action" : 'Sous-actions de traitement' }}</h2>
        @if ($agentLocked)
            <p class="action-section-note mb-3">Saisie gelée : action soumise. Modifications possibles uniquement après rejet motivé.</p>
        @endif
        @if ($canTrackWeekly && $usesStructuredProgress && $action->sousActions->isEmpty())
            <p class="action-section-note mb-3">Aucune sous-action planifiée. Les sous-actions doivent être ajoutées depuis le PTA ou la fiche action par un responsable habilité.</p>
        @endif
        @if (false)
            <p class="action-section-note mb-3">Cette action est suivie en mode quantitatif. La saisie se fait dans le bloc « Quantité réalisée ».</p>
        @endif
        @if ($usesStructuredProgress)
        @php
            $viewerId = (int) auth()->id();
            $viewerIsAgent = auth()->user()?->isAgent() ?? false;
        @endphp
        <div class="mb-4 space-y-3">
            @forelse ($action->sousActions as $sousAction)
                @php
                    $subActionRules = app(\App\Services\Actions\ActionBusinessRules::class);
                    $subActionRequirements = $subActionRules->subActionSubmissionRequirements($sousAction);
                    $isOtherRmo = $viewerIsAgent && (int) $sousAction->agent_id !== $viewerId;
                    $sousActionStatusLabel = match ((string) ($sousAction->statut ?? '')) {
                        'en_attente_validation_chef' => 'En attente validation chef',
                        'validee_chef' => 'Validee chef',
                        'rejetee_a_corriger' => 'A corriger',
                        'en_cours' => 'En cours',
                        default => $sousAction->est_effectuee ? 'Realisee' : 'Non demarree',
                    };
                @endphp
                <article class="action-week-card {{ $isOtherRmo ? 'is-other-rmo opacity-60' : '' }}" @if ($isOtherRmo) aria-label="Sous-action d'un autre RMO" @endif>
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <strong>{{ $sousAction->libelle }}</strong>
                            <span class="ml-2 rounded-full bg-[#3996d3]/10 px-2 py-0.5 text-[11px] font-semibold text-[#3996d3]">Sous-action planifiée</span>
                            @if ($isOtherRmo)
                                <span class="ml-2 rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-600" title="Cette sous-action est rattachée à un autre RMO. Lecture seule.">RMO différent</span>
                            @endif
                            <p class="text-slate-600">{{ optional($sousAction->date_debut)->format('d/m/Y') }} → {{ optional($sousAction->date_fin)->format('d/m/Y') }}</p>
                            <p class="text-slate-600">Agent : <strong>{{ $sousAction->agent?->name ?? '-' }}</strong></p>
                            <p class="text-slate-600">Statut : <strong>{{ $sousActionStatusLabel }}</strong> | Exécution : <strong>{{ number_format((float) ($sousAction->taux_execution ?? 0), 1, ',', ' ') }}%</strong></p>
                            @if ($usesQuantitativeProgress || ($sousAction->cible_prevue !== null && (float) $sousAction->cible_prevue > 0))
                                <p class="text-slate-600">Cible prévue : <strong>{{ $sousAction->cible_prevue !== null ? number_format((float) $sousAction->cible_prevue, 1, ',', ' ') : '-' }} {{ $sousAction->unite ?: $action->unite_cible }}</strong></p>
                                <p class="text-slate-600">Quantité réalisée : <strong>{{ number_format((float) ($sousAction->quantite_realisee ?? 0), 1, ',', ' ') }} {{ $sousAction->unite ?: $action->unite_cible }}</strong> | Taux : <strong>{{ number_format((float) ($sousAction->taux_realisation ?? 0), 1, ',', ' ') }}%</strong></p>
                                @if ($sousAction->resultat_obtenu)
                                    <p class="text-slate-600">Résultat obtenu : <strong>{{ $sousAction->resultat_obtenu }}</strong></p>
                                @endif
                            @endif
                            @if ($sousAction->resultat_attendu)
                                <p class="text-slate-600">Résultat attendu : <strong>{{ $sousAction->resultat_attendu }}</strong></p>
                            @endif
                            @if ($sousAction->commentaire)
                                <p class="text-slate-600">Commentaire : <strong>{{ $sousAction->commentaire }}</strong></p>
                            @endif
                        </div>
                        <div class="text-right text-sm text-slate-500">
                            <p>{{ $sousAction->justificatifs->count() }} justificatif(s)</p>
                            @if ($sousAction->date_realisation)
                                <p>Réalisée le {{ optional($sousAction->date_realisation)->format('d/m/Y H:i') }}</p>
                            @endif
                        </div>
                    </div>
                    @php
                        $canEditSousAction = ($canSubmitAssignedSubActions ?? false)
                            && $usesStructuredProgress
                            && ! $sousAction->est_effectuee
                            && (int) $sousAction->agent_id === (int) auth()->id();
                    @endphp
                    @if ($canEditSousAction)
                        <form class="tracking-entry-form {{ $sousAction->cible_prevue !== null && (float) $sousAction->cible_prevue > 0 ? 'has-target' : 'no-target' }} mt-3 rounded-2xl border border-[#3996d3]/25 bg-white p-4 shadow-sm" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.sub-actions.update', [$action, $sousAction]) }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="execution_only" value="1">
                            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                                @if ($subActionRequirements['quantity'])
                                    <div>
                                        <label for="quantite_realisee_sous_action_{{ $sousAction->id }}">Quantité effectuée</label>
                                        <input id="quantite_realisee_sous_action_{{ $sousAction->id }}" name="quantite_realisee" type="number" step="0.0001" min="0" value="{{ old('quantite_realisee', $sousAction->quantite_realisee) }}">
                                    </div>
                                    <div>
                                        <label for="resultat_obtenu_sous_action_{{ $sousAction->id }}">Résultat obtenu</label>
                                        <textarea id="resultat_obtenu_sous_action_{{ $sousAction->id }}" name="resultat_obtenu">{{ old('resultat_obtenu', $sousAction->resultat_obtenu) }}</textarea>
                                    </div>
                                @endif
                                <div>
                                    <label for="commentaire_sous_action_{{ $sousAction->id }}">Commentaire de réalisation</label>
                                    <textarea id="commentaire_sous_action_{{ $sousAction->id }}" name="commentaire">{{ old('commentaire', $sousAction->commentaire) }}</textarea>
                                </div>
                                <div>
                                    <label for="difficultes_sous_action_{{ $sousAction->id }}">Difficultes rencontrees</label>
                                    <textarea id="difficultes_sous_action_{{ $sousAction->id }}" name="difficultes">{{ old('difficultes') }}</textarea>
                                </div>
                                <div>
                                    <label for="justificatif_sous_action_{{ $sousAction->id }}">Pièce justificative</label>
                                    <input id="justificatif_sous_action_{{ $sousAction->id }}" name="justificatif" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}">
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button class="btn btn-secondary" type="submit" name="tracking_action" value="save" formnovalidate>Enregistrer</button>
                                <button class="btn btn-primary" type="submit" name="tracking_action" value="submit">Soumettre la sous-action</button>
                            </div>
                        </form>
                    @endif
                    @if (($canReviewClosure ?? false) && (string) ($sousAction->statut ?? '') === 'en_attente_validation_chef')
                        <div class="mt-3 rounded-2xl border border-[#3996d3]/20 bg-white p-4 shadow-sm">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <strong class="text-sm text-[#17324a]">Controle chef de service</strong>
                                <span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-xs">En attente</span>
                            </div>
                            <form method="POST" action="{{ route('workspace.actions.sub-actions.review', [$action, $sousAction]) }}" class="grid gap-3 [grid-template-columns:minmax(0,1fr)_auto]">
                                @csrf
                                <input type="hidden" name="decision_sous_action" value="valider">
                                <label class="sr-only" for="commentaire_validation_sous_action_{{ $sousAction->id }}">Observation validation</label>
                                <input id="commentaire_validation_sous_action_{{ $sousAction->id }}" name="commentaire_sous_action" type="text" maxlength="5000" placeholder="Observation facultative">
                                <button class="btn btn-primary rounded-2xl px-4 py-2" type="submit">Valider</button>
                            </form>
                            <form method="POST" action="{{ route('workspace.actions.sub-actions.review', [$action, $sousAction]) }}" class="mt-3 grid gap-3 [grid-template-columns:minmax(0,1fr)_auto]">
                                @csrf
                                <input type="hidden" name="decision_sous_action" value="demander_correction">
                                <label class="sr-only" for="commentaire_rejet_sous_action_{{ $sousAction->id }}">Motif correction</label>
                                <input id="commentaire_rejet_sous_action_{{ $sousAction->id }}" name="commentaire_sous_action" type="text" maxlength="5000" placeholder="Motif obligatoire" required>
                                <button class="btn btn-secondary rounded-2xl px-4 py-2" type="submit">Demander correction</button>
                            </form>
                        </div>
                    @endif
                </article>
            @empty
                <x-ui.empty-state
                    title="Aucune sous-action planifiée"
                    message="Aucune sous-action n'est planifiée pour cette action. Ajoutez-en depuis la fiche action ou le PTA."
                    icon="filter"
                    tone="neutral"
                    class="mb-3"
                />
            @endforelse
        </div>

        @endif

        {{-- Suivi periodique (semaines) supprime. Le suivi se fait desormais
             via les sous-actions et la saisie quantitative globale. --}}
    </section>
    @endif

    @if ($canReviewClosure)
        <section id="action-review-chef" class="showcase-panel mb-4">
            <h2 class="showcase-panel-title">Vérification chef de service</h2>
            @if ($isAwaitingChef)
                <form method="POST" action="{{ route('workspace.actions.review', $action) }}">
                    @csrf
                    <div class="mb-2">
                        <div>
                            <label for="decision_validation">Decision</label>
                            <select id="decision_validation" name="decision_validation" required>
                                <option value="valider" @selected(old('decision_validation') === 'valider')>Valider</option>
                                <option value="demander_correction" @selected(old('decision_validation') === 'demander_correction')>Demander correction</option>
                                <option value="rejeter" @selected(old('decision_validation') === 'rejeter')>Rejeter</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        {{-- Spec v2 : le chef ne note plus. Seul le motif (obligatoire si rejet/correction) est conserve. --}}
                        <label for="motif_validation_chef">Motif{{ $workflow['rejection_comment_required'] ? ' (obligatoire au rejet ou correction)' : '' }}</label>
                        <textarea id="motif_validation_chef" name="motif_validation_chef" rows="4">{{ old('motif_validation_chef') }}</textarea>
                    </div>
                    <button class="btn btn-primary mt-2.5" type="submit">
                        {{ $workflow['service_review_button_label'] }}
                    </button>
                </form>
            @else
                <x-ui.empty-state
                    title="Aucune action en attente"
                    message="Aucune action n'est en attente de revue chef pour le moment."
                    icon="check"
                    tone="success"
                />
            @endif
        </section>
    @endif

    @if (($canSignalControlAnomaly ?? false) || $activeAnomalyLogs->isNotEmpty())
        <section id="action-controle" class="showcase-panel mb-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="showcase-panel-title">Controle et anomalies</h2>
                @if ($activeAnomalyLogs->isNotEmpty())
                    <span class="anbg-badge anbg-badge-warning px-3">{{ $activeAnomalyLogs->count() }} ouverte(s)</span>
                @endif
            </div>

            @if ($activeAnomalyLogs->isNotEmpty())
                <div class="mb-4 space-y-3">
                    @foreach ($activeAnomalyLogs as $log)
                        @php
                            $details = is_array($log->details) ? $log->details : [];
                            $levelClass = match ((string) $log->niveau) {
                                'critical', 'urgence' => 'anbg-badge anbg-badge-danger',
                                default => 'anbg-badge anbg-badge-warning',
                            };
                        @endphp
                        <article class="rounded-2xl border border-[#f9b13c]/35 bg-[#fff8d6]/80 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <strong class="text-[#17324a]">{{ str_replace('_', ' ', (string) $log->type_evenement) }}</strong>
                                        <span class="{{ $levelClass }} px-2 py-0.5 text-xs">{{ $log->niveau }}</span>
                                        <span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-xs">{{ $log->cible_role ?: 'controle' }}</span>
                                    </div>
                                    <p class="mt-2 text-sm text-slate-700">{{ $log->message }}</p>
                                    @if (!empty($details['correction_attendue']))
                                        <p class="mt-2 text-xs font-semibold text-[#17324a]">Correction attendue : {{ $details['correction_attendue'] }}</p>
                                    @endif
                                    @if (!empty($details['blocked_scope']))
                                        <p class="mt-1 text-xs text-[#667085]">Blocage : {{ $details['blocked_scope'] }}</p>
                                    @endif
                                </div>

                                @if ($canResolveControlAnomaly ?? false)
                                    <form method="POST" action="{{ route('workspace.actions.anomalies.resolve', [$action, $log]) }}" class="min-w-[220px]">
                                        @csrf
                                        <label for="commentaire_resolution_{{ $log->id }}" class="sr-only">Commentaire resolution</label>
                                        <input id="commentaire_resolution_{{ $log->id }}" name="commentaire_resolution" type="text" maxlength="2000" placeholder="Commentaire">
                                        <button class="btn btn-secondary mt-2 w-full rounded-2xl px-3 py-2 text-xs" type="submit">Cloturer</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            @if ($canSignalControlAnomaly ?? false)
                <form method="POST" action="{{ route('workspace.actions.anomalies.signal', $action) }}" class="rounded-2xl border border-slate-200/85 bg-white/95 p-4">
                    @csrf
                    <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                        <div>
                            <label for="type_anomalie">Type</label>
                            <select id="type_anomalie" name="type_anomalie" required>
                                <option value="justificatif_manquant">Justificatif manquant</option>
                                <option value="commentaire_absent">Commentaire absent</option>
                                <option value="date_incoherente">Date incoherente</option>
                                <option value="financement_incomplet">Financement incomplet</option>
                                <option value="kpi_incoherent">KPI incoherent</option>
                                <option value="autre">Autre anomalie</option>
                            </select>
                        </div>
                        <div>
                            <label for="niveau_anomalie">Niveau</label>
                            <select id="niveau_anomalie" name="niveau" required>
                                <option value="warning">Avertissement</option>
                                <option value="critical">Critique</option>
                                <option value="info">Info</option>
                            </select>
                        </div>
                        <div>
                            <label for="cible_role_anomalie">Responsable du traitement</label>
                            <select id="cible_role_anomalie" name="cible_role" required>
                                <option value="responsable">Agent / RMO</option>
                                <option value="chef_service">Chef service</option>
                                <option value="direction">Direction</option>
                                <option value="planification">SCIQ / Planification</option>
                                <option value="dg">DG</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]">
                        <div>
                            <label for="message_anomalie">Cause</label>
                            <textarea id="message_anomalie" name="message" rows="3" required>{{ old('message') }}</textarea>
                        </div>
                        <div>
                            <label for="correction_attendue">Correction attendue</label>
                            <textarea id="correction_attendue" name="correction_attendue" rows="3">{{ old('correction_attendue') }}</textarea>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit">Signaler</button>
                </form>
            @endif
        </section>
    @endif

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
                    <a class="btn btn-primary btn-sm rounded-xl" target="_blank" rel="noopener" href="{{ $previewUrl }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        Visualiser
                    </a>
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
