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
        $frequenceExecution = (string) ($action->frequence_execution ?: 'hebdomadaire');
        $frequenceLabels = [
            'instantanee' => 'Instantanée',
            'journaliere' => 'Journalière',
            'hebdomadaire' => 'Hebdomadaire',
            'mensuelle' => 'Mensuelle',
            'annuelle' => 'Annuelle',
        ];
        $frequenceLabel = $frequenceLabels[$frequenceExecution] ?? $frequenceExecution;
        $periodeLabelSingulier = match ($frequenceExecution) {
            'instantanee' => 'Étape',
            'journaliere' => 'Jour',
            'mensuelle' => 'Mois',
            'annuelle' => 'Année',
            default => 'Semaine',
        };
        $periodeLabelPluriel = match ($frequenceExecution) {
            'instantanee' => 'étapes',
            'journaliere' => 'jours',
            'mensuelle' => 'mois',
            'annuelle' => 'années',
            default => 'semaines',
        };
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
        $usesStructuredProgress = $action->usesStructuredProgressTracking();
        $usesHistoricalProgress = ! $usesStructuredProgress;
        $showSubActionsPanel = $usesStructuredProgress || $usesHistoricalProgress;
        $workflow = $workflowConfig ?? [
            'service_enabled' => true,
            'direction_enabled' => true,
            'submission_target' => 'service',
            'chain_label' => 'Agent -> Chef de service -> Direction',
            'submission_help_text' => 'L action est d abord revue par le chef de service, puis par la direction.',
            'submission_button_label' => 'Soumettre au chef de service',
            'service_review_button_label' => 'Valider la revue chef',
            'service_review_success_text' => 'Action validee par le chef de service et transmise a la direction.',
            'final_statistics_hint' => 'Oui apres validation direction.',
            'rejection_comment_required' => true,
        ];
        $agentLocked = auth()->check()
            && (int) auth()->id() === (int) $action->responsable_id
            && !in_array($validationStatus, ['non_soumise', 'rejetee_chef', 'rejetee_direction'], true);
        $isAwaitingChef = $workflow['service_enabled'] && $validationStatus === 'soumise_chef';
        $isAwaitingDirection = $workflow['direction_enabled'] && $validationStatus === 'validee_chef';
        $isValidatedDirection = $validationStatus === 'validee_direction';
        $ressources = $action->resourceLabels();
        $financingJustificatif = $action->justificatifs->firstWhere('categorie', 'financement');
        $rmoNames = $action->relationLoaded('responsables')
            ? $action->responsables->pluck('name')->filter()->values()->all()
            : [];
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
            'action-status' => 'Indicateur',
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
        $progressionTheorique = max(0, min(100, (float) ($action->progression_theorique ?? 0)));
        $sousActionsTotal = $action->relationLoaded('sousActions') ? $action->sousActions->count() : 0;
        $sousActionsDone = $action->relationLoaded('sousActions') ? $action->sousActions->where('est_effectuee', true)->count() : 0;
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
                <span class="showcase-eyebrow">Action #{{ $action->id }}</span>
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
                    <a class="btn btn-amber rounded-2xl px-4 py-2.5" href="{{ route('workspace.actions.edit', $action) }}">Modifier action</a>
                @endif
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.actions.index') }}">Retour liste</a>
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Progression réelle</p>
            <p class="showcase-kpi-number">{{ number_format($progressionReelle, 1) }}%</p>
            <div class="mt-3 showcase-progress-track">
                <span class="showcase-progress-bar {{ $progressionReelle >= 80 ? 'bg-[#8fc043]' : ($progressionReelle >= 50 ? 'bg-blue-500' : 'bg-[#f0e509]') }}" style="width: {{ $progressionReelle }}%"></span>
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
            <p class="showcase-kpi-label">{{ $metricLabel('global') }}</p>
            <p class="showcase-kpi-number">{{ number_format((float) ($kpi?->kpi_global ?? 0), 1) }}%</p>
            <p class="showcase-kpi-meta">Délai {{ number_format((float) ($kpi?->kpi_delai ?? 0), 1) }} | Performance {{ number_format((float) ($kpi?->kpi_performance ?? 0), 1) }} | Qualité {{ number_format((float) ($kpi?->kpi_qualite ?? 0), 1) }} | Risque {{ number_format((float) ($kpi?->kpi_risque ?? 0), 1) }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Sous-actions suivies</p>
            <p class="showcase-kpi-number">{{ $sousActionsDone }}/{{ $sousActionsTotal }}</p>
            <p class="showcase-kpi-meta">Sous-actions créées par l'agent</p>
        </article>
    </section>

    <section id="action-validation" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Circuit de validation</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
            <article class="showcase-inline-stat action-detail-card">
                <strong>Étape 1 — Soumission agent</strong>
                <p class="mt-2 text-slate-600">Statut : <strong>{{ in_array($validationStatus, ['non_soumise', 'rejetee_chef', 'rejetee_direction'], true) ? 'À faire / à corriger' : 'Effectuée' }}</strong></p>
                <p class="text-slate-600">Soumis par : <strong>{{ $action->soumisPar?->name ?? '-' }}</strong></p>
                <p class="text-slate-600">Date de soumission : <strong>{{ optional($action->soumise_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
            </article>
            @if ($workflow['service_enabled'])
                <article class="showcase-inline-stat action-detail-card">
                    <strong>Étape 2 — Évaluation chef de service</strong>
                    <p class="mt-2 text-slate-600">Statut : <strong>{{ in_array($validationStatus, ['validee_chef', 'rejetee_direction', 'validee_direction'], true) ? 'Effectuée' : ($isAwaitingChef ? 'En attente' : '-') }}</strong></p>
                    <p class="text-slate-600">Évaluateur : <strong>{{ $action->evaluePar?->name ?? '-' }}</strong></p>
                    <p class="text-slate-600">Note : <strong>{{ $action->evaluation_note !== null ? number_format((float) $action->evaluation_note, 2) . '/100' : '-' }}</strong></p>
                    <p class="text-slate-600">Date : <strong>{{ optional($action->evalue_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
                </article>
            @endif
            @if ($workflow['direction_enabled'])
                <article class="showcase-inline-stat action-detail-card">
                    <strong>Étape {{ $workflow['service_enabled'] ? '3' : '2' }} — Validation direction</strong>
                    <p class="mt-2 text-slate-600">Statut : <strong>{{ $isValidatedDirection ? 'Validée' : ($isAwaitingDirection ? 'En attente' : '-') }}</strong></p>
                    <p class="text-slate-600">Évaluateur : <strong>{{ $action->directionValidePar?->name ?? '-' }}</strong></p>
                    <p class="text-slate-600">Note : <strong>{{ $action->direction_evaluation_note !== null ? number_format((float) $action->direction_evaluation_note, 2) . '/100' : '-' }}</strong></p>
                    <p class="text-slate-600">Date : <strong>{{ optional($action->direction_valide_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
                </article>
            @endif
        </div>
        @if ($validationStatus === 'rejetee_chef')
            <p class="mt-2 text-sm text-[#f9b13c]">Motif rejet chef : <strong>{{ $action->evaluation_commentaire ?: '-' }}</strong></p>
        @elseif ($validationStatus === 'rejetee_direction')
            <p class="mt-2 text-sm text-[#f9b13c]">Motif rejet direction : <strong>{{ $action->direction_evaluation_commentaire ?: '-' }}</strong></p>
        @endif
    </section>

    <section id="action-fiche" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Fiche complète de l'action</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]">
            <article class="showcase-inline-stat action-detail-card">
                <strong>Contexte de planification</strong>
                <p class="mt-2 text-slate-600">PAS : <strong>{{ $pas?->titre ?? '-' }}</strong></p>
                <p class="text-slate-600">Période PAS : <strong>{{ $pas?->periode_debut ?? '-' }} – {{ $pas?->periode_fin ?? '-' }}</strong></p>
                <p class="text-slate-600">PAO : <strong>{{ $pao?->titre ?? '-' }}</strong> ({{ $pao?->annee ?? '-' }})</p>
                <p class="text-slate-600">Objectif opérationnel : <strong>{{ $objectifOperationnel?->description ?: ($objectifOperationnel?->libelle ?? '-') }}</strong></p>
                <p class="text-slate-600">PTA : <strong>{{ $pta?->titre ?? '-' }}</strong></p>
                <p class="text-slate-600">Direction : <strong>{{ $pta?->direction?->code ?? '-' }} – {{ $pta?->direction?->libelle ?? '-' }}</strong></p>
                <p class="text-slate-600">Service : <strong>{{ $pta?->service?->code ?? '-' }} – {{ $pta?->service?->libelle ?? '-' }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Identification</strong>
                <p class="mt-2 text-slate-600">ID action : <strong>#{{ $action->id }}</strong></p>
                <p class="text-slate-600">Libellé : <strong>{{ $action->libelle }}</strong></p>
                <p class="text-slate-600">Description : <strong>{{ $action->description ?: '-' }}</strong></p>
                <p class="text-slate-600">Statut métier : <strong>{{ $actionStatusLabel($action->statut ?: '-') }}</strong></p>
                <p class="text-slate-600">Statut dynamique : <strong>{{ $actionStatusLabel($status) }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Responsable et échéances</strong>
                <p class="mt-2 text-slate-600">RMO : <strong>{{ $rmoNames !== [] ? implode(', ', $rmoNames) : ($action->responsable?->name ?? '-') }}</strong></p>
                <p class="text-slate-600">Responsable principal : <strong>{{ $action->responsable?->name ?? '-' }}</strong></p>
                <p class="text-slate-600">E-mail : <strong>{{ $action->responsable?->email ?? '-' }}</strong></p>
                <p class="text-slate-600">Matricule : <strong>{{ $action->responsable?->agent_matricule ?? '-' }}</strong></p>
                <p class="text-slate-600">Fonction : <strong>{{ $action->responsable?->agent_fonction ?? '-' }}</strong></p>
                <p class="text-slate-600">Téléphone : <strong>{{ $action->responsable?->agent_telephone ?? '-' }}</strong></p>
                <p class="text-slate-600">Date de début : <strong>{{ optional($action->date_debut)->format('d/m/Y') ?: '-' }}</strong></p>
                <p class="text-slate-600">Date de fin prévue : <strong>{{ optional($action->date_fin)->format('d/m/Y') ?: '-' }}</strong></p>
                <p class="text-slate-600">Date d'échéance : <strong>{{ optional($action->date_echeance)->format('d/m/Y') ?: '-' }}</strong></p>
                <p class="text-slate-600">Date de fin réelle : <strong>{{ optional($action->date_fin_reelle)->format('d/m/Y') ?: '-' }}</strong></p>
                <p class="text-slate-600">Fréquence d'exécution : <strong>{{ $frequenceLabel }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Cible et performance</strong>
                <p class="mt-2 text-slate-600">Cible : <strong>{{ $modeEvaluationLabel }}</strong></p>
                @if ($usesQuantitativeProgress)
                    <p class="text-slate-600">Cible attendue : <strong>{{ $action->quantite_cible !== null ? number_format((float) $action->quantite_cible, 4) : '-' }} {{ $action->unite_cible ?: '' }}</strong></p>
                    <p class="text-slate-600">Unité de mesure : <strong>{{ $action->unite_cible ?: '-' }}</strong></p>
                    <p class="text-slate-600">Quantité réalisée : <strong>{{ $action->quantite_realisee !== null ? number_format((float) $action->quantite_realisee, 4) : '0.0000' }} {{ $action->unite_cible ?: '' }}</strong></p>
                    <p class="text-slate-600">Reste à réaliser : <strong>{{ number_format((float) ($action->reste_a_realiser ?? $remainingValue), 4) }} {{ $action->unite_cible ?: '' }}</strong></p>
                    <p class="text-slate-600">Taux d'atteinte de la cible : <strong>{{ number_format((float) ($action->taux_atteinte_cible ?? 0), 2) }}%</strong></p>
                    <p class="text-slate-600">Dépassement : <strong>{{ $overachievementRate > 0 ? '+'.number_format($overachievementRate, 2).'%' : '-' }}</strong></p>
                    <p class="text-slate-600">Seuil minimum : <strong>{{ number_format((float) ($action->seuil_minimum ?? 80), 2) }}%</strong></p>
                    <p class="text-slate-600">Performance : <strong>{{ $performanceLabels[$action->statut_performance ?? 'non_evaluee'] ?? ($action->statut_performance ?: '-') }}</strong></p>
                    <p class="text-slate-600">Statut cible : <strong>{{ $quantitativeStatusLabels[$action->statut_execution_quantitative ?? 'non_demarre'] ?? ($action->statut_execution_quantitative ?: '-') }}</strong></p>
                @else
                    <p class="text-slate-600">Résultat attendu : <strong>{{ $action->resultat_attendu ?: '-' }}</strong></p>
                    <p class="text-slate-600">Critères de validation : <strong>{{ $action->criteres_validation ?: '-' }}</strong></p>
                    <p class="text-slate-600">Livrable attendu : <strong>{{ $action->livrable_attendu ?: '-' }}</strong></p>
                    <p class="text-slate-600">Avancement par sous-actions : <strong>{{ number_format((float) ($action->avancement_operationnel ?? $action->progression_reelle ?? 0), 2) }}%</strong></p>
                @endif
                <p class="text-slate-600">Seuil d'alerte progression : <strong>{{ number_format((float) ($action->seuil_alerte_progression ?? 0), 2) }}%</strong></p>
                <p class="text-slate-600">Progression réelle : <strong>{{ number_format((float) ($action->progression_reelle ?? 0), 2) }}%</strong></p>
                <p class="text-slate-600">Progression théorique : <strong>{{ number_format((float) ($action->progression_theorique ?? 0), 2) }}%</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Ressources mobilisées</strong>
                <p class="mt-2 text-slate-600">Ressources nécessaires : <strong>{{ $ressources !== [] ? implode(', ', $ressources) : '-' }}</strong></p>
                <p class="text-slate-600">Détails complémentaires : <strong>{{ $action->ressources_details ?: '-' }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Financement</strong>
                <p class="mt-2 text-slate-600">Financement requis : <strong>{{ $action->financement_requis ? 'Oui' : 'Non' }}</strong></p>
                <p class="text-slate-600">Montant estimé : <strong>{{ $action->montant_estime !== null ? number_format((float) $action->montant_estime, 2) : '-' }}</strong></p>
                <p class="text-slate-600">Nature : <strong>{{ $action->nature_financement ?: $action->description_financement ?: '-' }}</strong></p>
                <p class="text-slate-600">Source de financement : <strong>{{ $action->source_financement ?: '-' }}</strong></p>
                <p class="text-slate-600">Statut financement : <strong>{{ $financingLabel }}</strong></p>
                <p class="text-slate-600">Commentaire DAF : <strong>{{ $action->financement_daf_commentaire ?: '-' }}</strong></p>
                <p class="text-slate-600">Montant validé DAF : <strong>{{ $action->financement_montant_valide !== null ? number_format((float) $action->financement_montant_valide, 2) : '-' }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Risques et mesures</strong>
                <p class="mt-2 text-slate-600">Risque potentiel : <strong>{{ $action->risque_potentiel ?: ($action->risques ?: '-') }}</strong></p>
                <p class="text-slate-600">Mesures préventives : <strong>{{ $action->mesures_preventives ?: '-' }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Clôture et évaluation</strong>
                <p class="mt-2 text-slate-600">Rapport final : <strong>{{ $action->rapport_final ?: '-' }}</strong></p>
                <p class="text-slate-600">Commentaire chef : <strong>{{ $action->evaluation_commentaire ?: '-' }}</strong></p>
                <p class="text-slate-600">Commentaire direction : <strong>{{ $action->direction_evaluation_commentaire ?: '-' }}</strong></p>
                <p class="text-slate-600">Validation hiérarchique finale : <strong>{{ $action->validation_hierarchique ? 'Oui' : 'Non' }}</strong></p>
            </article>
        </div>
    </section>

    <section id="action-financement" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Financement et validation budgétaire</h2>
        @if ($action->financement_requis)
            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
                <article class="showcase-inline-stat action-detail-card">
                    <strong>Besoin déclaré</strong>
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
                    <strong>Décision DAF</strong>
                    <p class="mt-2 text-slate-600">Responsable DAF : <strong>{{ $action->financementDafPar?->name ?? '-' }}</strong></p>
                    <p class="text-slate-600">Date de décision : <strong>{{ optional($action->financement_daf_le)->format('d/m/Y H:i') ?: '-' }}</strong></p>
                    <p class="text-slate-600">Décision : <strong>{{ $action->financement_daf_decision ?: '-' }}</strong></p>
                    <p class="text-slate-600">Montant validé : <strong>{{ $action->financement_montant_valide !== null ? number_format((float) $action->financement_montant_valide, 2) : '-' }}</strong></p>
                    <p class="text-slate-600">Référence : <strong>{{ $action->financement_reference ?: '-' }}</strong></p>
                    <p class="text-slate-600">Commentaire : <strong>{{ $action->financement_daf_commentaire ?: '-' }}</strong></p>
                </article>
                <article class="showcase-inline-stat action-detail-card">
                    <strong>Accord DG</strong>
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
                        <label for="commentaire_financement_daf">Commentaire DAF (obligatoire au rejet)</label>
                        <textarea id="commentaire_financement_daf" name="commentaire_financement">{{ old('commentaire_financement') }}</textarea>
                    </div>
                    <button class="btn btn-blue mt-2.5" type="submit">Enregistrer la décision DAF</button>
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
                    <button class="btn btn-blue mt-2.5" type="submit">Enregistrer l'accord DG</button>
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
                <strong>Progression réelle</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($action->progression_reelle ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>Progression théorique</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($action->progression_theorique ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>{{ $metricLabel('delai') }}</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_delai ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>{{ $metricLabel('performance') }}</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_performance ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>{{ $metricLabel('conformite') }}</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_conformite ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>{{ $metricLabel('qualite') }}</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_qualite ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>{{ $metricLabel('risque') }}</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_risque ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>{{ $metricLabel('global') }}</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_global ?? 0), 2) }}%</p>
            </article>
        </div>
        @if ($usesStructuredProgress && $usesQuantitativeProgress && $canTrackWeekly)
            <form class="mt-4 rounded-2xl border border-[#3996d3]/25 bg-white p-4 shadow-sm" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.execution.update', $action) }}">
                @csrf
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-base font-bold text-[#1c203d]">Saisie quantitative</h3>
                        <p class="text-sm text-slate-600">Mode : {{ $modeEvaluationLabel }}. La cible est définie dans le PTA.</p>
                    </div>
                    <span class="rounded-full bg-[#3996d3]/10 px-3 py-1 text-xs font-semibold text-[#3996d3]">
                        Cible {{ $action->quantite_cible !== null ? number_format((float) $action->quantite_cible, 4) : '-' }} {{ $action->unite_cible }}
                    </span>
                </div>
                <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                    <div>
                        <label for="quantite_realisee_action">Quantité réalisée</label>
                        <input id="quantite_realisee_action" name="quantite_realisee" type="number" step="0.0001" min="0" value="{{ old('quantite_realisee', $action->quantite_realisee ?? 0) }}" required>
                    </div>
                    <div>
                        <label for="commentaire_quantitatif">Commentaire d'avancement</label>
                        <textarea id="commentaire_quantitatif" name="commentaire_quantitatif">{{ old('commentaire_quantitatif') }}</textarea>
                    </div>
                    <div>
                        <label for="justificatif_quantitatif">Pièce justificative</label>
                        <input id="justificatif_quantitatif" name="justificatif_quantitatif" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}">
                    </div>
                </div>
                <button class="btn btn-blue mt-3" type="submit">Enregistrer la quantité réalisée</button>
            </form>
        @endif
    </section>

    @if ($showSubActionsPanel)
    <section id="action-weeks" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Sous-actions de traitement</h2>
        @if ($agentLocked)
            <p class="action-section-note mb-3">Saisie gelée : action soumise. Modifications possibles uniquement après rejet motivé.</p>
        @endif
        @if ($canTrackWeekly && $usesStructuredProgress)
            <form class="mb-4 rounded-2xl border border-[#3996d3]/25 bg-white p-4 shadow-sm" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.sub-actions.store', $action) }}">
                @csrf
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-base font-bold text-[#1c203d]">Ajouter une sous-action</h3>
                    </div>
                    <span class="rounded-full bg-[#3996d3]/10 px-3 py-1 text-xs font-semibold text-[#3996d3]">Agent</span>
                </div>
                <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                    <div>
                        <label for="libelle_sous_action">Libellé de la sous-action</label>
                        <input id="libelle_sous_action" name="libelle_sous_action" type="text" value="{{ old('libelle_sous_action') }}" required>
                    </div>
                    <div>
                        <label for="date_debut_sous_action">Date de début</label>
                        <input id="date_debut_sous_action" name="date_debut" type="date" value="{{ old('date_debut', optional($action->date_debut)->format('Y-m-d')) }}" required>
                    </div>
                    <div>
                        <label for="date_fin_sous_action">Date de fin</label>
                        <input id="date_fin_sous_action" name="date_fin" type="date" value="{{ old('date_fin', optional($action->date_fin)->format('Y-m-d')) }}" required>
                    </div>
                    <div>
                        <label for="resultat_attendu_sous_action">Résultat attendu</label>
                        <textarea id="resultat_attendu_sous_action" name="resultat_attendu">{{ old('resultat_attendu') }}</textarea>
                    </div>
                    @if ($usesQuantitativeProgress)
                        <div>
                            <label for="cible_prevue_sous_action">Cible prévue</label>
                            <input id="cible_prevue_sous_action" name="cible_prevue" type="number" step="0.0001" min="0" value="{{ old('cible_prevue') }}">
                        </div>
                        <div>
                            <label for="quantite_realisee_sous_action">Quantité réalisée</label>
                            <input id="quantite_realisee_sous_action" name="quantite_realisee" type="number" step="0.0001" min="0" value="{{ old('quantite_realisee') }}">
                        </div>
                        <div>
                            <label for="unite_sous_action">Unité</label>
                            <input id="unite_sous_action" name="unite" type="text" value="{{ old('unite', $action->unite_cible) }}">
                        </div>
                        <div>
                            <label for="resultat_obtenu_sous_action">Résultat obtenu</label>
                            <textarea id="resultat_obtenu_sous_action" name="resultat_obtenu">{{ old('resultat_obtenu') }}</textarea>
                        </div>
                    @endif
                    <div>
                        <label for="description_sous_action">Description</label>
                        <textarea id="description_sous_action" name="description_sous_action">{{ old('description_sous_action') }}</textarea>
                    </div>
                    <div>
                        <label for="commentaire_sous_action">Commentaire initial</label>
                        <textarea id="commentaire_sous_action" name="commentaire">{{ old('commentaire') }}</textarea>
                    </div>
                    <div>
                        <label for="justificatif_sous_action">Pièce justificative</label>
                        <input id="justificatif_sous_action" name="justificatif" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}">
                    </div>
                    <label class="checkbox-pill self-end">
                        <input type="hidden" name="est_effectuee" value="0">
                        <input id="est_effectuee_sous_action" name="est_effectuee" type="checkbox" value="1" @checked(old('est_effectuee'))>
                        Sous-action effectuée
                    </label>
                </div>
                <button class="btn btn-blue mt-3" type="submit">+ Ajouter une sous-action</button>
            </form>
        @endif
        @if (false)
            <p class="action-section-note mb-3">Cette action est suivie en mode quantitatif. La saisie se fait dans le bloc « Quantité réalisée ».</p>
        @endif
        @if ($usesStructuredProgress)
        <div class="mb-4 space-y-3">
            @forelse ($action->sousActions as $sousAction)
                <article class="action-week-card">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <strong>{{ $sousAction->libelle }}</strong>
                            <span class="ml-2 rounded-full bg-[#3996d3]/10 px-2 py-0.5 text-[11px] font-semibold text-[#3996d3]">Sous-action agent</span>
                            <p class="text-slate-600">{{ optional($sousAction->date_debut)->format('d/m/Y') }} → {{ optional($sousAction->date_fin)->format('d/m/Y') }}</p>
                            <p class="text-slate-600">Agent : <strong>{{ $sousAction->agent?->name ?? '-' }}</strong></p>
                            <p class="text-slate-600">Statut : <strong>{{ $sousAction->est_effectuee ? 'Effectuée' : 'À faire' }}</strong> | Exécution : <strong>{{ number_format((float) ($sousAction->taux_execution ?? 0), 2) }}%</strong></p>
                            @if ($usesQuantitativeProgress)
                                <p class="text-slate-600">Cible prévue : <strong>{{ $sousAction->cible_prevue !== null ? number_format((float) $sousAction->cible_prevue, 4) : '-' }} {{ $sousAction->unite ?: $action->unite_cible }}</strong></p>
                                <p class="text-slate-600">Quantité réalisée : <strong>{{ number_format((float) ($sousAction->quantite_realisee ?? 0), 4) }} {{ $sousAction->unite ?: $action->unite_cible }}</strong> | Taux : <strong>{{ number_format((float) ($sousAction->taux_realisation ?? 0), 2) }}%</strong></p>
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
                        $canEditSousAction = $canTrackWeekly
                            && $usesStructuredProgress
                            && ! $sousAction->est_effectuee
                            && (int) $sousAction->agent_id === (int) auth()->id();
                    @endphp
                    @if ($canEditSousAction)
                        <form class="mt-3 rounded-2xl border border-slate-200 bg-white p-3" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.sub-actions.update', [$action, $sousAction]) }}">
                            @csrf
                            @method('PUT')
                            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                                <div>
                                    <label for="libelle_sous_action_{{ $sousAction->id }}">Libellé</label>
                                    <input id="libelle_sous_action_{{ $sousAction->id }}" name="libelle_sous_action" type="text" value="{{ old('libelle_sous_action', $sousAction->libelle) }}" required>
                                </div>
                                <div>
                                    <label for="date_debut_sous_action_{{ $sousAction->id }}">Date de début</label>
                                    <input id="date_debut_sous_action_{{ $sousAction->id }}" name="date_debut" type="date" value="{{ old('date_debut', optional($sousAction->date_debut)->format('Y-m-d')) }}" required>
                                </div>
                                <div>
                                    <label for="date_fin_sous_action_{{ $sousAction->id }}">Date de fin</label>
                                    <input id="date_fin_sous_action_{{ $sousAction->id }}" name="date_fin" type="date" value="{{ old('date_fin', optional($sousAction->date_fin)->format('Y-m-d')) }}" required>
                                </div>
                                <div>
                                    <label for="resultat_attendu_sous_action_{{ $sousAction->id }}">Résultat attendu</label>
                                    <textarea id="resultat_attendu_sous_action_{{ $sousAction->id }}" name="resultat_attendu">{{ old('resultat_attendu', $sousAction->resultat_attendu) }}</textarea>
                                </div>
                                @if ($usesQuantitativeProgress)
                                    <div>
                                        <label for="cible_prevue_sous_action_{{ $sousAction->id }}">Cible prévue</label>
                                        <input id="cible_prevue_sous_action_{{ $sousAction->id }}" name="cible_prevue" type="number" step="0.0001" min="0" value="{{ old('cible_prevue', $sousAction->cible_prevue) }}">
                                    </div>
                                    <div>
                                        <label for="quantite_realisee_sous_action_{{ $sousAction->id }}">Quantité réalisée</label>
                                        <input id="quantite_realisee_sous_action_{{ $sousAction->id }}" name="quantite_realisee" type="number" step="0.0001" min="0" value="{{ old('quantite_realisee', $sousAction->quantite_realisee) }}">
                                    </div>
                                    <div>
                                        <label for="unite_sous_action_{{ $sousAction->id }}">Unité</label>
                                        <input id="unite_sous_action_{{ $sousAction->id }}" name="unite" type="text" value="{{ old('unite', $sousAction->unite ?: $action->unite_cible) }}">
                                    </div>
                                    <div>
                                        <label for="resultat_obtenu_sous_action_{{ $sousAction->id }}">Résultat obtenu</label>
                                        <textarea id="resultat_obtenu_sous_action_{{ $sousAction->id }}" name="resultat_obtenu">{{ old('resultat_obtenu', $sousAction->resultat_obtenu) }}</textarea>
                                    </div>
                                @endif
                                <div>
                                    <label for="description_sous_action_{{ $sousAction->id }}">Description</label>
                                    <textarea id="description_sous_action_{{ $sousAction->id }}" name="description_sous_action">{{ old('description_sous_action', $sousAction->description) }}</textarea>
                                </div>
                                <div>
                                    <label for="commentaire_sous_action_{{ $sousAction->id }}">Commentaire de réalisation</label>
                                    <textarea id="commentaire_sous_action_{{ $sousAction->id }}" name="commentaire">{{ old('commentaire', $sousAction->commentaire) }}</textarea>
                                </div>
                                <div>
                                    <label for="justificatif_sous_action_{{ $sousAction->id }}">Pièce justificative</label>
                                    <input id="justificatif_sous_action_{{ $sousAction->id }}" name="justificatif" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}">
                                </div>
                                <label class="checkbox-pill self-end">
                                    <input type="hidden" name="est_effectuee" value="0">
                                    <input id="est_effectuee_sous_action_{{ $sousAction->id }}" name="est_effectuee" type="checkbox" value="1" @checked(old('est_effectuee'))>
                                    Marquer comme réalisée
                                </label>
                            </div>
                            <button class="btn btn-blue mt-3" type="submit">Enregistrer la sous-action</button>
                        </form>
                    @endif
                </article>
            @empty
                <p class="mb-3 text-slate-600">Aucune sous-action créée par l'agent pour cette action.</p>
            @endforelse
        </div>

        @endif

        @if ($usesHistoricalProgress)
        <h3 class="mb-2 text-center text-lg font-bold text-[#1c203d]">Suivi périodique (historique)</h3>
        @forelse ($action->weeks as $week)
            <article id="action-week-{{ $week->id }}" class="action-week-card mb-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <strong>{{ $week->libelle_sous_action ?: $periodeLabelSingulier.' '.$week->numero_semaine }}</strong>
                        @if ($week->est_creee_par_agent)
                            <span class="ml-2 rounded-full bg-[#3996d3]/10 px-2 py-0.5 text-[11px] font-semibold text-[#3996d3]">Sous-action agent</span>
                        @endif
                        <p class="text-slate-600">{{ optional($week->date_debut)->format('d/m/Y') }} → {{ optional($week->date_fin)->format('d/m/Y') }}</p>
                        @if ($week->resultat_attendu)
                            <p class="text-slate-600">Résultat attendu : <strong>{{ $week->resultat_attendu }}</strong></p>
                        @endif
                        <p class="text-slate-600">
                            État : <strong>{{ $week->est_renseignee ? 'Renseignée' : 'Non renseignée' }}</strong> |
                            Réelle : <strong>{{ number_format((float) ($week->progression_reelle ?? 0), 2) }}%</strong> |
                            Théo : <strong>{{ number_format((float) ($week->progression_theorique ?? 0), 2) }}%</strong>
                        </p>
                    </div>
                    @if ($week->saisiPar)
                        <p class="text-sm text-slate-500">Saisi par {{ $week->saisiPar->name }} le {{ optional($week->saisi_le)->format('d/m/Y H:i') }}</p>
                    @endif
                </div>

                @if ($canTrackWeekly)
                    <form class="mt-2.5" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.weeks.submit', [$action, $week]) }}">
                        @csrf
                        @if ($action->type_cible === 'quantitative')
                            <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                                <div>
                                    <label for="quantite_realisee_{{ $week->id }}">Quantité réalisée</label>
                                    <input id="quantite_realisee_{{ $week->id }}" name="quantite_realisee" type="number" step="0.0001" min="0" value="{{ old('quantite_realisee', $week->quantite_realisee) }}">
                                </div>
                                <div>
                                    <label for="commentaire_{{ $week->id }}">Commentaire</label>
                                    <textarea id="commentaire_{{ $week->id }}" name="commentaire">{{ old('commentaire', $week->commentaire) }}</textarea>
                                </div>
                            </div>
                        @else
                            <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                                <div>
                                    <label for="taches_realisees_{{ $week->id }}">Exécution de la sous-action</label>
                                    <textarea id="taches_realisees_{{ $week->id }}" name="taches_realisees">{{ old('taches_realisees', $week->taches_realisees) }}</textarea>
                                </div>
                                <div>
                                    <label for="avancement_estime_{{ $week->id }}">Niveau d'avancement estimé (%)</label>
                                    <input id="avancement_estime_{{ $week->id }}" name="avancement_estime" type="number" step="0.01" min="0" max="100" value="{{ old('avancement_estime', $week->avancement_estime) }}">
                                </div>
                            </div>
                        @endif

                        <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                            <div>
                                <label for="difficultes_{{ $week->id }}">Difficultés rencontrées</label>
                                <textarea id="difficultes_{{ $week->id }}" name="difficultes">{{ old('difficultes', $week->difficultes) }}</textarea>
                            </div>
                            <div>
                                <label for="mesures_correctives_{{ $week->id }}">Mesures correctives</label>
                                <textarea id="mesures_correctives_{{ $week->id }}" name="mesures_correctives">{{ old('mesures_correctives', $week->mesures_correctives) }}</textarea>
                            </div>
                            <div>
                                <label for="justificatif_{{ $week->id }}">Pièce justificative</label>
                                <input id="justificatif_{{ $week->id }}" name="justificatif" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}" required>
                            </div>
                        </div>
                        <button class="btn btn-green" type="submit">
                            {{ $week->est_renseignee ? 'Mettre à jour' : 'Valider la sous-action' }}
                        </button>
                    </form>
                @endif
            </article>
        @empty
            <p class="text-slate-600">Aucune période générée pour cette action.</p>
        @endforelse
        @endif
    </section>
    @endif

    @if ($canSubmitClosure)
        <section id="action-cloture" class="showcase-panel mb-4">
            <h2 class="showcase-panel-title">Soumission de clôture (Agent)</h2>
            <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.close', $action) }}">
                @csrf
                <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                    <div>
                        <label for="date_fin_reelle">Date de fin réelle</label>
                        <input id="date_fin_reelle" name="date_fin_reelle" type="date" value="{{ old('date_fin_reelle', optional($action->date_fin_reelle)->format('Y-m-d')) }}" required>
                    </div>
                </div>
                <div>
                    <label for="rapport_final">Rapport final</label>
                    <textarea id="rapport_final" name="rapport_final" required>{{ old('rapport_final', $action->rapport_final) }}</textarea>
                </div>
                <button class="btn btn-primary mt-2.5" type="submit">
                    {{ $workflow['submission_button_label'] }}
                </button>
            </form>
        </section>
    @endif

    @if ($canReviewClosure)
        <section id="action-review-chef" class="showcase-panel mb-4">
            <h2 class="showcase-panel-title">Evaluation chef de service</h2>
            @if ($isAwaitingChef)
                <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.review', $action) }}">
                    @csrf
                    <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                        <div>
                            <label for="decision_validation">Decision</label>
                            <select id="decision_validation" name="decision_validation" required>
                                <option value="valider" @selected(old('decision_validation') === 'valider')>Valider</option>
                                <option value="rejeter" @selected(old('decision_validation') === 'rejeter')>Rejeter</option>
                            </select>
                        </div>
                        <div>
                            <label for="evaluation_note">Note sur 100</label>
                            <input id="evaluation_note" name="evaluation_note" type="number" step="0.01" min="0" max="100" value="{{ old('evaluation_note') }}" required>
                        </div>
                        <div>
                            <label for="validation_sans_correction">Validation sans correction (optionnel)</label>
                            <select id="validation_sans_correction" name="validation_sans_correction">
                                <option value="">Non defini</option>
                                <option value="1" @selected(old('validation_sans_correction') === '1')>Oui</option>
                                <option value="0" @selected(old('validation_sans_correction') === '0')>Non</option>
                            </select>
                        </div>
                        <div>
                        <label for="justificatif_evaluation">Justificatif evaluation chef (optionnel)</label>
                            <input id="justificatif_evaluation" name="justificatif_evaluation" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}">
                        </div>
                    </div>
                    <div>
                        <label for="evaluation_commentaire">Commentaire d'evaluation{{ $workflow['rejection_comment_required'] ? ' (obligatoire au rejet)' : '' }}</label>
                        <textarea id="evaluation_commentaire" name="evaluation_commentaire">{{ old('evaluation_commentaire') }}</textarea>
                    </div>
                    <button class="btn btn-blue mt-2.5" type="submit">
                        {{ $workflow['service_review_button_label'] }}
                    </button>
                </form>
            @else
                <p class="text-slate-600">Aucune action en attente de revue chef pour le moment.</p>
            @endif
        </section>
    @endif

    @if ($canReviewDirection)
        <section id="action-review-direction" class="showcase-panel mb-4">
            <h2 class="showcase-panel-title">Validation direction</h2>
            @if ($isAwaitingDirection)
                <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.review-direction', $action) }}">
                    @csrf
                    <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                        <div>
                            <label for="direction_decision_validation">Decision</label>
                            <select id="direction_decision_validation" name="decision_validation" required>
                                <option value="valider" @selected(old('decision_validation') === 'valider')>Valider</option>
                                <option value="rejeter" @selected(old('decision_validation') === 'rejeter')>Rejeter</option>
                            </select>
                        </div>
                        <div>
                            <label for="direction_evaluation_note">Note sur 100</label>
                            <input id="direction_evaluation_note" name="evaluation_note" type="number" step="0.01" min="0" max="100" value="{{ old('evaluation_note') }}" required>
                        </div>
                        <div>
                        <label for="justificatif_evaluation_direction">Justificatif evaluation direction (optionnel)</label>
                            <input id="justificatif_evaluation_direction" name="justificatif_evaluation_direction" type="file" accept="{{ $documentAccept ?? '.pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg' }}">
                        </div>
                    </div>
                    <div>
                        <label for="direction_evaluation_commentaire">Commentaire d'evaluation direction{{ $workflow['rejection_comment_required'] ? ' (obligatoire au rejet)' : '' }}</label>
                        <textarea id="direction_evaluation_commentaire" name="evaluation_commentaire">{{ old('evaluation_commentaire') }}</textarea>
                    </div>
                    <button class="btn btn-blue mt-2.5" type="submit">
                        Valider la revue direction
                    </button>
                </form>
            @else
                <p class="text-slate-600">Aucune action en attente de validation direction pour le moment.</p>
            @endif
        </section>
    @endif

    <section id="action-discussion" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Discussion et retours de validation</h2>
        <form method="POST" action="{{ route('workspace.actions.comment', $action) }}" class="mb-4">
            @csrf
            <label for="discussion_message">Ajouter un commentaire</label>
            <textarea id="discussion_message" name="message" required>{{ old('message') }}</textarea>
            <button class="btn btn-primary mt-2.5" type="submit">
                Publier
            </button>
        </form>

        <div class="space-y-3">
            @forelse ($discussionEntries as $entry)
                <article class="showcase-thread-item">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="font-semibold">{{ $entry->utilisateur?->name ?? 'Système' }}</p>
                            <p class="text-xs text-slate-500">{{ optional($entry->created_at)->format('Y-m-d H:i') ?: '-' }}</p>
                        </div>
                        <span class="anbg-badge anbg-badge-neutral px-3">{{ str_replace('_', ' ', $entry->type_evenement) }}</span>
                    </div>
                    <p class="mt-3 whitespace-pre-line text-slate-700">{{ $entry->message }}</p>
                </article>
            @empty
                <p class="text-slate-600">Aucun commentaire ou retour de validation pour le moment.</p>
            @endforelse
        </div>
    </section>

    <section id="action-justificatifs" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Justificatifs action</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Categorie</th>
                        <th>Sous-action</th>
                        <th>Fichier</th>
                        <th>Auteur</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($action->justificatifs as $doc)
                        <tr>
                            <td>{{ optional($doc->created_at)->format('Y-m-d H:i') }}</td>
                            <td>{{ $justificatifCategoryLabels[$doc->categorie] ?? $doc->categorie }}</td>
                            <td>{{ $doc->sousAction?->libelle ?: ($doc->actionWeek?->libelle_sous_action ?: ($doc->actionWeek ? 'Periode '.$doc->actionWeek->numero_semaine : '-')) }}</td>
                            <td>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-[#17324a]">{{ $doc->nom_original }}</span>
                                    <a class="btn btn-primary btn-sm rounded-xl" target="_blank" rel="noopener" href="{{ route('workspace.actions.justificatifs.preview', [$action, $doc]) }}">
                                        Visualiser
                                    </a>
                                    <a class="rounded-xl border border-[#3996d3]/30 px-3 py-1.5 text-xs font-bold text-[#3996d3] hover:bg-[#e8f3fb]" href="{{ route('workspace.actions.justificatifs.download', [$action, $doc]) }}">
                                        Telecharger
                                    </a>
                                </div>
                            </td>
                            <td>{{ $doc->ajoutePar?->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-slate-600">Aucun justificatif.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section id="action-logs" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Journal d'alertes et événements</h2>
        <div class="overflow-auto">
            <table>
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
                        <tr><td colspan="5" class="text-slate-600">Aucun log.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
