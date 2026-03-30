@extends('layouts.workspace')

@section('content')
    @php
        $kpi = $action->actionKpi;
        $status = $action->statut_dynamique ?: 'non_demarre';
        $pta = $action->pta;
        $pao = $pta?->pao;
        $pas = $pao?->pas;
        $frequenceExecution = (string) ($action->frequence_execution ?: 'hebdomadaire');
        $frequenceLabels = [
            'instantanee' => 'Instantanee',
            'journaliere' => 'Journaliere',
            'hebdomadaire' => 'Hebdomadaire',
            'mensuelle' => 'Mensuelle',
            'annuelle' => 'Annuelle',
        ];
        $frequenceLabel = $frequenceLabels[$frequenceExecution] ?? $frequenceExecution;
        $periodeLabelSingulier = match ($frequenceExecution) {
            'instantanee' => 'Etape',
            'journaliere' => 'Jour',
            'mensuelle' => 'Mois',
            'annuelle' => 'Annee',
            default => 'Semaine',
        };
        $periodeLabelPluriel = match ($frequenceExecution) {
            'instantanee' => 'etapes',
            'journaliere' => 'jours',
            'mensuelle' => 'mois',
            'annuelle' => 'annees',
            default => 'semaines',
        };
        $validationStatus = (string) ($action->statut_validation ?: 'non_soumise');
        $validationLabels = [
            'non_soumise' => 'Non soumise',
            'soumise_chef' => 'Soumise au chef de service',
            'rejetee_chef' => 'Rejetee par le chef de service',
            'validee_chef' => 'Validee par le chef de service (en attente direction)',
            'rejetee_direction' => 'Rejetee par la direction',
            'validee_direction' => 'Validee par la direction',
        ];
        $validationLabel = $validationLabels[$validationStatus] ?? $validationStatus;
        $agentLocked = auth()->check()
            && (int) auth()->id() === (int) $action->responsable_id
            && !in_array($validationStatus, ['non_soumise', 'rejetee_chef', 'rejetee_direction'], true);
        $isAwaitingChef = $validationStatus === 'soumise_chef';
        $isAwaitingDirection = $validationStatus === 'validee_chef';
        $isValidatedDirection = $validationStatus === 'validee_direction';
        $ressources = [];
        if ($action->ressource_main_oeuvre) {
            $ressources[] = 'Main d oeuvre';
        }
        if ($action->ressource_equipement) {
            $ressources[] = 'Equipement specialise';
        }
        if ($action->ressource_partenariat) {
            $ressources[] = 'Partenariat';
        }
        if ($action->ressource_autres) {
            $ressources[] = 'Autres ressources';
        }
        $discussionEntries = $action->actionLogs
            ->filter(fn ($log) => in_array($log->type_evenement, [
                'commentaire',
                'action_soumise_validation',
                'action_validee_chef',
                'action_rejetee_chef',
                'action_validee_direction',
                'action_rejetee_direction',
            ], true))
            ->sortBy('created_at')
            ->values();
        $statusStyles = [
            'non_demarre' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
            'en_cours' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
            'a_risque' => 'bg-[#fff8d6] text-[#b98a00] dark:bg-[#f0e509]/15 dark:text-[#f8e932]',
            'en_avance' => 'bg-[#eef6e1] text-[#1c203d] dark:bg-[#8fc043]/15 dark:text-[#f8e932]',
            'en_retard' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-[#f8e932]',
            'suspendu' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-200',
            'annule' => 'bg-slate-200 text-slate-700 dark:bg-slate-700/50 dark:text-slate-200',
            'acheve_dans_delai' => 'bg-[#eef6e1] text-[#1c203d] dark:bg-[#8fc043]/15 dark:text-[#f8e932]',
            'acheve_hors_delai' => 'bg-[#fff8d6] text-[#f9b13c] dark:bg-[#f0e509]/15 dark:text-[#f8e932]',
        ];
        $validationStyles = [
            'non_soumise' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
            'soumise_chef' => 'bg-[#fff8d6] text-[#f9b13c] dark:bg-[#f0e509]/15 dark:text-[#f8e932]',
            'rejetee_chef' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-[#f8e932]',
            'validee_chef' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
            'rejetee_direction' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-[#f8e932]',
            'validee_direction' => 'bg-[#eef6e1] text-[#1c203d] dark:bg-[#8fc043]/15 dark:text-[#f8e932]',
        ];
        $detailSections = [
            'action-validation' => 'Validation',
            'action-fiche' => 'Fiche',
            'action-status' => 'KPI',
            'action-weeks' => 'Suivi',
            'action-discussion' => 'Discussion',
            'action-justificatifs' => 'Justificatifs',
            'action-logs' => 'Journal',
        ];
        $progressionReelle = max(0, min(100, (float) ($action->progression_reelle ?? 0)));
        $progressionTheorique = max(0, min(100, (float) ($action->progression_theorique ?? 0)));
        $statusClass = $statusStyles[$status] ?? $statusStyles['non_demarre'];
        $validationClass = $validationStyles[$validationStatus] ?? $validationStyles['non_soumise'];
    @endphp

    <section id="action-header" class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div class="max-w-4xl">
                <span class="showcase-eyebrow">Action #{{ $action->id }}</span>
                <h1 class="showcase-title">{{ $action->libelle }}</h1>
                <p class="showcase-subtitle">
                    {{ $pta?->direction?->libelle ?? 'Direction non definie' }} /
                    {{ $pta?->service?->libelle ?? 'Service non defini' }} /
                    {{ $pta?->titre ?? 'PTA non defini' }}.
                    Responsable principal: {{ $action->responsable?->name ?? '-' }}.
                </p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        Frequence {{ $frequenceLabel }}
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#3996d3]"></span>
                        Periode {{ optional($action->date_debut)->format('d/m/Y') ?: '-' }} -> {{ optional($action->date_fin)->format('d/m/Y') ?: '-' }}
                    </span>
                    <span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold {{ $statusClass }}">
                        {{ $status }}
                    </span>
                    <span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold {{ $validationClass }}">
                        {{ $validationLabel }}
                    </span>
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
            <p class="showcase-kpi-label">Progression reelle</p>
            <p class="showcase-kpi-number">{{ number_format($progressionReelle, 1) }}%</p>
            <div class="mt-3 showcase-progress-track">
                <span class="showcase-progress-bar {{ $progressionReelle >= 80 ? 'bg-[#8fc043]' : ($progressionReelle >= 50 ? 'bg-blue-500' : 'bg-[#f0e509]') }}" style="width: {{ $progressionReelle }}%"></span>
            </div>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Progression theorique</p>
            <p class="showcase-kpi-number">{{ number_format($progressionTheorique, 1) }}%</p>
            <div class="mt-3 showcase-progress-track">
                <span class="showcase-progress-bar bg-slate-400" style="width: {{ $progressionTheorique }}%"></span>
            </div>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">KPI global</p>
            <p class="showcase-kpi-number">{{ number_format((float) ($kpi?->kpi_global ?? 0), 1) }}%</p>
            <p class="showcase-kpi-meta">Delai {{ number_format((float) ($kpi?->kpi_delai ?? 0), 1) }} | Performance {{ number_format((float) ($kpi?->kpi_performance ?? 0), 1) }} | Qualite {{ number_format((float) ($kpi?->kpi_qualite ?? 0), 1) }} | Risque {{ number_format((float) ($kpi?->kpi_risque ?? 0), 1) }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Periodes suivies</p>
            <p class="showcase-kpi-number">{{ $action->weeks->where('est_renseignee', true)->count() }}/{{ $action->weeks->count() }}</p>
            <p class="showcase-kpi-meta">{{ $periodeLabelPluriel }} renseignees dans la frequence choisie</p>
        </article>
    </section>

    <section id="action-validation" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Circuit de validation</h2>
        <p class="showcase-panel-subtitle">Chaine de validation agent -> chef de service -> direction avec gel automatique des saisies apres soumission.</p>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
            <article class="showcase-inline-stat action-detail-card">
                <strong>Etape 1 - Soumission agent</strong>
                <p class="mt-2 text-slate-600">Statut: <strong>{{ in_array($validationStatus, ['non_soumise', 'rejetee_chef', 'rejetee_direction'], true) ? 'A faire / a corriger' : 'Effectuee' }}</strong></p>
                <p class="text-slate-600">Soumis par: <strong>{{ $action->soumisPar?->name ?? '-' }}</strong></p>
                <p class="text-slate-600">Date soumission: <strong>{{ optional($action->soumise_le)->format('Y-m-d H:i') ?: '-' }}</strong></p>
            </article>
            <article class="showcase-inline-stat action-detail-card">
                <strong>Etape 2 - Evaluation chef de service</strong>
                <p class="mt-2 text-slate-600">Statut: <strong>{{ in_array($validationStatus, ['validee_chef', 'rejetee_direction', 'validee_direction'], true) ? 'Effectuee' : ($isAwaitingChef ? 'En attente' : '-') }}</strong></p>
                <p class="text-slate-600">Evaluateur: <strong>{{ $action->evaluePar?->name ?? '-' }}</strong></p>
                <p class="text-slate-600">Note: <strong>{{ $action->evaluation_note !== null ? number_format((float) $action->evaluation_note, 2) . '/100' : '-' }}</strong></p>
                <p class="text-slate-600">Date: <strong>{{ optional($action->evalue_le)->format('Y-m-d H:i') ?: '-' }}</strong></p>
            </article>
            <article class="showcase-inline-stat action-detail-card">
                <strong>Etape 3 - Validation direction</strong>
                <p class="mt-2 text-slate-600">Statut: <strong>{{ $isValidatedDirection ? 'Validee' : ($isAwaitingDirection ? 'En attente' : '-') }}</strong></p>
                <p class="text-slate-600">Evaluateur: <strong>{{ $action->directionValidePar?->name ?? '-' }}</strong></p>
                <p class="text-slate-600">Note: <strong>{{ $action->direction_evaluation_note !== null ? number_format((float) $action->direction_evaluation_note, 2) . '/100' : '-' }}</strong></p>
                <p class="text-slate-600">Date: <strong>{{ optional($action->direction_valide_le)->format('Y-m-d H:i') ?: '-' }}</strong></p>
            </article>
        </div>
        <p class="mt-3 text-sm text-slate-600">
            Prise en compte statistique:
            <strong>{{ $isValidatedDirection ? 'Oui (action comptabilisee)' : 'Non (en attente validation direction)' }}</strong>
        </p>
        @if ($validationStatus === 'rejetee_chef')
            <p class="mt-2 text-sm text-[#f9b13c]">Motif rejet chef: <strong>{{ $action->evaluation_commentaire ?: '-' }}</strong></p>
        @elseif ($validationStatus === 'rejetee_direction')
            <p class="mt-2 text-sm text-[#f9b13c]">Motif rejet direction: <strong>{{ $action->direction_evaluation_commentaire ?: '-' }}</strong></p>
        @endif
    </section>

    <section id="action-fiche" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Fiche complete de l'action</h2>
        <p class="showcase-panel-subtitle">Lecture consolidee des informations strategiques, des ressources et des criteres de performance.</p>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]">
            <article class="showcase-inline-stat action-detail-card">
                <strong>Contexte planification</strong>
                <p class="mt-2 text-slate-600">PAS: <strong>{{ $pas?->titre ?? '-' }}</strong></p>
                <p class="text-slate-600">Periode PAS: <strong>{{ $pas?->periode_debut ?? '-' }} - {{ $pas?->periode_fin ?? '-' }}</strong></p>
                <p class="text-slate-600">PAO: <strong>{{ $pao?->titre ?? '-' }}</strong> ({{ $pao?->annee ?? '-' }})</p>
                <p class="text-slate-600">PTA: <strong>{{ $pta?->titre ?? '-' }}</strong></p>
                <p class="text-slate-600">Direction: <strong>{{ $pta?->direction?->code ?? '-' }} - {{ $pta?->direction?->libelle ?? '-' }}</strong></p>
                <p class="text-slate-600">Service: <strong>{{ $pta?->service?->code ?? '-' }} - {{ $pta?->service?->libelle ?? '-' }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Identification</strong>
                <p class="mt-2 text-slate-600">ID action: <strong>#{{ $action->id }}</strong></p>
                <p class="text-slate-600">Titre: <strong>{{ $action->libelle }}</strong></p>
                <p class="text-slate-600">Description: <strong>{{ $action->description ?: '-' }}</strong></p>
                <p class="text-slate-600">Type cible: <strong>{{ $action->type_cible }}</strong></p>
                <p class="text-slate-600">Statut metier: <strong>{{ $action->statut ?: '-' }}</strong></p>
                <p class="text-slate-600">Statut dynamique: <strong>{{ $status }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Responsable et echeances</strong>
                <p class="mt-2 text-slate-600">Responsable: <strong>{{ $action->responsable?->name ?? '-' }}</strong></p>
                <p class="text-slate-600">Email: <strong>{{ $action->responsable?->email ?? '-' }}</strong></p>
                <p class="text-slate-600">Matricule: <strong>{{ $action->responsable?->agent_matricule ?? '-' }}</strong></p>
                <p class="text-slate-600">Fonction: <strong>{{ $action->responsable?->agent_fonction ?? '-' }}</strong></p>
                <p class="text-slate-600">Telephone: <strong>{{ $action->responsable?->agent_telephone ?? '-' }}</strong></p>
                <p class="text-slate-600">Date debut: <strong>{{ optional($action->date_debut)->format('Y-m-d') ?: '-' }}</strong></p>
                <p class="text-slate-600">Date fin prevue: <strong>{{ optional($action->date_fin)->format('Y-m-d') ?: '-' }}</strong></p>
                <p class="text-slate-600">Date echeance: <strong>{{ optional($action->date_echeance)->format('Y-m-d') ?: '-' }}</strong></p>
                <p class="text-slate-600">Date fin reelle: <strong>{{ optional($action->date_fin_reelle)->format('Y-m-d') ?: '-' }}</strong></p>
                <p class="text-slate-600">Frequence execution: <strong>{{ $frequenceLabel }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Cible et performance</strong>
                @if ($action->type_cible === 'quantitative')
                    <p class="mt-2 text-slate-600">Unite cible: <strong>{{ $action->unite_cible ?: '-' }}</strong></p>
                    <p class="text-slate-600">Quantite cible: <strong>{{ $action->quantite_cible !== null ? number_format((float) $action->quantite_cible, 4) : '-' }}</strong></p>
                @else
                    <p class="mt-2 text-slate-600">Resultat attendu: <strong>{{ $action->resultat_attendu ?: '-' }}</strong></p>
                    <p class="text-slate-600">Criteres validation: <strong>{{ $action->criteres_validation ?: '-' }}</strong></p>
                    <p class="text-slate-600">Livrable attendu: <strong>{{ $action->livrable_attendu ?: '-' }}</strong></p>
                @endif
                <p class="text-slate-600">Seuil alerte progression: <strong>{{ number_format((float) ($action->seuil_alerte_progression ?? 0), 2) }}%</strong></p>
                <p class="text-slate-600">Progression reelle: <strong>{{ number_format((float) ($action->progression_reelle ?? 0), 2) }}%</strong></p>
                <p class="text-slate-600">Progression theorique: <strong>{{ number_format((float) ($action->progression_theorique ?? 0), 2) }}%</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Ressources mobilisees</strong>
                <p class="mt-2 text-slate-600">Ressources: <strong>{{ $ressources !== [] ? implode(', ', $ressources) : '-' }}</strong></p>
                <p class="text-slate-600">Autres details: <strong>{{ $action->ressource_autres_details ?: '-' }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Financement</strong>
                <p class="mt-2 text-slate-600">Financement requis: <strong>{{ $action->financement_requis ? 'Oui' : 'Non' }}</strong></p>
                <p class="text-slate-600">Description besoin: <strong>{{ $action->description_financement ?: '-' }}</strong></p>
                <p class="text-slate-600">Source financement: <strong>{{ $action->source_financement ?: '-' }}</strong></p>
                <p class="text-slate-600">Montant estime: <strong>{{ $action->montant_estime !== null ? number_format((float) $action->montant_estime, 2) : '-' }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Risques et mesures</strong>
                <p class="mt-2 text-slate-600">Risques potentiels: <strong>{{ $action->risques ?: '-' }}</strong></p>
                <p class="text-slate-600">Mesures preventives: <strong>{{ $action->mesures_preventives ?: '-' }}</strong></p>
            </article>

            <article class="showcase-inline-stat action-detail-card">
                <strong>Cloture et evaluation</strong>
                <p class="mt-2 text-slate-600">Rapport final: <strong>{{ $action->rapport_final ?: '-' }}</strong></p>
                <p class="text-slate-600">Commentaire chef: <strong>{{ $action->evaluation_commentaire ?: '-' }}</strong></p>
                <p class="text-slate-600">Commentaire direction: <strong>{{ $action->direction_evaluation_commentaire ?: '-' }}</strong></p>
                <p class="text-slate-600">Validation hierarchique finale: <strong>{{ $action->validation_hierarchique ? 'Oui' : 'Non' }}</strong></p>
            </article>
        </div>
    </section>

    <section id="action-status" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Etat d'avancement</h2>
        <p class="showcase-panel-subtitle">Synthese du niveau de progression reel, theorique et des KPI utilises pour la decision.</p>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            <article class="showcase-inline-stat">
                <strong>Progression reelle</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($action->progression_reelle ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>Progression theorique</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($action->progression_theorique ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>KPI delai</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_delai ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>KPI performance</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_performance ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>KPI conformite</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_conformite ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>KPI qualite</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_qualite ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>KPI risque</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_risque ?? 0), 2) }}%</p>
            </article>
            <article class="showcase-inline-stat">
                <strong>KPI global</strong>
                <p class="mt-1 text-lg">{{ number_format((float) ($kpi?->kpi_global ?? 0), 2) }}%</p>
            </article>
        </div>
    </section>

    <section id="action-weeks" class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Suivi {{ $periodeLabelPluriel }}</h2>
        <p class="showcase-panel-subtitle">Execution periodique detaillee avec blocage automatique apres soumission pour validation.</p>
        @if ($agentLocked)
            <p class="action-section-note mb-3">Saisie gelee: action soumise. Modifications possibles uniquement apres rejet motive.</p>
        @endif
        @forelse ($action->weeks as $week)
            <article id="action-week-{{ $week->id }}" class="action-week-card mb-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <strong>{{ $periodeLabelSingulier }} {{ $week->numero_semaine }}</strong>
                        <p class="text-slate-600">{{ optional($week->date_debut)->format('Y-m-d') }} -> {{ optional($week->date_fin)->format('Y-m-d') }}</p>
                        <p class="text-slate-600">
                            Etat: <strong>{{ $week->est_renseignee ? 'Renseignee' : 'Non renseignee' }}</strong> |
                            Reelle: <strong>{{ number_format((float) ($week->progression_reelle ?? 0), 2) }}%</strong> |
                            Theo: <strong>{{ number_format((float) ($week->progression_theorique ?? 0), 2) }}%</strong>
                        </p>
                    </div>
                    @if ($week->saisiPar)
                        <p class="text-sm text-slate-500">Saisi par {{ $week->saisiPar->name }} le {{ optional($week->saisi_le)->format('Y-m-d H:i') }}</p>
                    @endif
                </div>

                @if ($canTrackWeekly)
                    <form class="mt-2.5" method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.weeks.submit', [$action, $week]) }}">
                        @csrf
                        @if ($action->type_cible === 'quantitative')
                            <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                                <div>
                                    <label for="quantite_realisee_{{ $week->id }}">Quantite realisee cette semaine</label>
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
                                    <label for="taches_realisees_{{ $week->id }}">Taches realisees</label>
                                    <textarea id="taches_realisees_{{ $week->id }}" name="taches_realisees">{{ old('taches_realisees', $week->taches_realisees) }}</textarea>
                                </div>
                                <div>
                                    <label for="avancement_estime_{{ $week->id }}">Niveau avancement estime (%)</label>
                                    <input id="avancement_estime_{{ $week->id }}" name="avancement_estime" type="number" step="0.01" min="0" max="100" value="{{ old('avancement_estime', $week->avancement_estime) }}">
                                </div>
                            </div>
                        @endif

                        <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                            <div>
                                <label for="difficultes_{{ $week->id }}">Difficultes rencontrees</label>
                                <textarea id="difficultes_{{ $week->id }}" name="difficultes">{{ old('difficultes', $week->difficultes) }}</textarea>
                            </div>
                            <div>
                                <label for="mesures_correctives_{{ $week->id }}">Mesures correctives</label>
                                <textarea id="mesures_correctives_{{ $week->id }}" name="mesures_correctives">{{ old('mesures_correctives', $week->mesures_correctives) }}</textarea>
                            </div>
                            <div>
                                <label for="justificatif_{{ $week->id }}">Justificatif</label>
                                <input id="justificatif_{{ $week->id }}" name="justificatif" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg" required>
                            </div>
                        </div>
                        <button class="btn btn-green" type="submit">
                            {{ $week->est_renseignee ? 'Mettre a jour semaine' : 'Valider semaine' }}
                        </button>
                    </form>
                @endif
            </article>
        @empty
            <p class="text-slate-600">Aucune periode generee pour cette action.</p>
        @endforelse
    </section>

    @if ($canSubmitClosure)
        <section id="action-cloture" class="showcase-panel mb-4">
            <h2 class="showcase-panel-title">Soumission de cloture (Agent)</h2>
            <p class="mb-2 text-sm text-slate-600">L'action sera envoyee au chef de service pour evaluation, puis a la direction apres validation chef. Aucun justificatif supplementaire n'est requis a cette etape.</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.actions.close', $action) }}">
                @csrf
                <div class="mb-2 grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                    <div>
                        <label for="date_fin_reelle">Date fin reelle</label>
                        <input id="date_fin_reelle" name="date_fin_reelle" type="date" value="{{ old('date_fin_reelle', optional($action->date_fin_reelle)->format('Y-m-d')) }}" required>
                    </div>
                </div>
                <div>
                    <label for="rapport_final">Rapport final</label>
                    <textarea id="rapport_final" name="rapport_final" required>{{ old('rapport_final', $action->rapport_final) }}</textarea>
                </div>
                <button class="btn btn-primary mt-2.5" type="submit">
                    Soumettre au chef de service
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
                            <input id="justificatif_evaluation" name="justificatif_evaluation" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg">
                        </div>
                    </div>
                    <div>
                        <label for="evaluation_commentaire">Commentaire d'evaluation</label>
                        <textarea id="evaluation_commentaire" name="evaluation_commentaire" required>{{ old('evaluation_commentaire') }}</textarea>
                    </div>
                    <button class="btn btn-blue mt-2.5" type="submit">
                        Valider la revue chef
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
                            <input id="justificatif_evaluation_direction" name="justificatif_evaluation_direction" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg">
                        </div>
                    </div>
                    <div>
                        <label for="direction_evaluation_commentaire">Commentaire d'evaluation direction</label>
                        <textarea id="direction_evaluation_commentaire" name="evaluation_commentaire" required>{{ old('evaluation_commentaire') }}</textarea>
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
                            <p class="font-semibold">{{ $entry->utilisateur?->name ?? 'Systeme' }}</p>
                            <p class="text-xs text-slate-500">{{ optional($entry->created_at)->format('Y-m-d H:i') ?: '-' }}</p>
                        </div>
                        <span class="anbg-badge anbg-badge-neutral px-3">{{ str_replace('_', ' ', $entry->type_evenement) }}</span>
                    </div>
                    <p class="mt-3 whitespace-pre-line text-slate-700 dark:text-slate-200">{{ $entry->message }}</p>
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
                        <th>Semaine</th>
                        <th>Fichier</th>
                        <th>Auteur</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($action->justificatifs as $doc)
                        <tr>
                            <td>{{ optional($doc->created_at)->format('Y-m-d H:i') }}</td>
                            <td>{{ $doc->categorie }}</td>
                            <td>{{ $doc->action_week_id ?: '-' }}</td>
                            <td>
                                <a class="action-download-link" href="{{ route('workspace.actions.justificatifs.download', [$action, $doc]) }}">
                                    {{ $doc->nom_original }}
                                </a>
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
        <h2 class="showcase-panel-title">Journal d'alertes et evenements</h2>
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
                            <td>{{ $log->niveau }}</td>
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
