@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
        $targetType = old('type_cible', $row->type_cible ?: 'quantitative');
        $selectedPta = $ptaOptions->firstWhere('id', (int) old('pta_id', $row->pta_id));
        $selectedResponsable = $responsableOptions->firstWhere('id', (int) old('responsable_id', $row->responsable_id));
    @endphp

    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div class="max-w-3xl">
                <span class="showcase-eyebrow">{{ $isEdit ? 'Edition action' : 'Nouvelle action' }}</span>
                <h1 class="showcase-title">{{ $isEdit ? 'Modifier une action existante' : 'Enregistrer une nouvelle action' }}</h1>
                <p class="showcase-subtitle">
                    Creation d action avec generation automatique des periodes de suivi, calcul des KPI,
                    gestion des ressources mobilisees et circuit de validation agent -> chef de service -> direction.
                </p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        {{ $ptaOptions->count() }} PTA disponibles
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#8fc043]"></span>
                        {{ $responsableOptions->count() }} agents executants eligibles
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot {{ $targetType === 'quantitative' ? 'bg-[#3996d3]' : 'bg-[#f0e509]' }}"></span>
                        Cible {{ $targetType }}
                    </span>
                </div>
            </div>
            <div class="showcase-action-row">
                @if ($isEdit)
                    <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.actions.suivi', $row) }}">
                        Ouvrir le suivi
                    </a>
                @endif
                <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ route('workspace.actions.index') }}">
                    Retour a la liste
                </a>
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Mode</p>
            <p class="showcase-kpi-number">{{ $isEdit ? 'Edit.' : 'Nouv.' }}</p>
            <p class="showcase-kpi-meta">{{ $isEdit ? 'Mise a jour d une action existante' : 'Creation d une action structurelle' }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">PTA cible</p>
            <p class="showcase-kpi-number text-[1.35rem]">{{ $selectedPta?->id ? '#' . $selectedPta->id : '--' }}</p>
            <p class="showcase-kpi-meta">{{ $selectedPta?->titre ?? 'Aucun PTA selectionne pour le moment' }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Responsable</p>
            <p class="showcase-kpi-number text-[1.35rem]">{{ $selectedResponsable?->agent_matricule ?? '--' }}</p>
            <p class="showcase-kpi-meta">{{ $selectedResponsable?->name ?? 'Aucun agent selectionne' }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Statut courant</p>
            <p class="showcase-kpi-number text-[1.35rem]">{{ $isEdit ? strtoupper((string) ($row->statut_dynamique ?: 'non_demarre')) : 'BROUILLON' }}</p>
            <p class="showcase-kpi-meta">{{ $isEdit ? number_format((float) ($row->progression_reelle ?? 0), 2) . '% de progression' : 'Les periodes seront calculees apres enregistrement' }}</p>
        </article>
    </section>

    <section class="showcase-panel mb-4">
        <form
            method="POST"
            enctype="multipart/form-data"
            class="form-shell"
            data-is-edit="{{ $isEdit ? '1' : '0' }}"
            action="{{ $isEdit ? route('workspace.actions.update', $row) : route('workspace.actions.store') }}"
        >
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">1) Identification de l action</h2>
                <p class="form-section-subtitle">Informations de base et acteur responsable de l execution.</p>
                <div class="form-grid">
                    <div>
                        <label for="pta_id">PTA</label>
                        <select id="pta_id" name="pta_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($ptaOptions as $pta)
                                <option value="{{ $pta->id }}" @selected((int) old('pta_id', $row->pta_id) === $pta->id)>
                                    #{{ $pta->id }} - {{ $pta->titre }} (D#{{ $pta->direction_id }}, S#{{ $pta->service_id }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="responsable_id">Agent responsable (RMO)</label>
                        <select id="responsable_id" name="responsable_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($responsableOptions as $user)
                                <option value="{{ $user->id }}" @selected((int) old('responsable_id', $row->responsable_id) === $user->id)>
                                    {{ $user->name }}
                                    @if (!empty($user->agent_matricule))
                                        - [{{ $user->agent_matricule }}]
                                    @endif
                                    @if (!empty($user->agent_fonction))
                                        - {{ $user->agent_fonction }}
                                    @endif
                                    ({{ $user->email }})
                                </option>
                            @endforeach
                        </select>
                        @if ($responsableOptions->isEmpty())
                            <p class="field-hint text-[#f9b13c]">
                                Aucun agent executant disponible pour votre perimetre.
                            </p>
                        @endif
                    </div>
                    <div>
                        <label for="date_debut">Date debut</label>
                        <input id="date_debut" name="date_debut" type="date" value="{{ old('date_debut', optional($row->date_debut)->format('Y-m-d')) }}" required>
                    </div>
                    <div>
                        <label for="date_fin">Date fin prevue</label>
                        <input id="date_fin" name="date_fin" type="date" value="{{ old('date_fin', optional($row->date_fin)->format('Y-m-d')) }}" required>
                    </div>
                    <div>
                        <label for="frequence_execution">Frequence d execution / suivi</label>
                        <select id="frequence_execution" name="frequence_execution" required>
                            @php
                                $frequence = old('frequence_execution', $row->frequence_execution ?: 'hebdomadaire');
                            @endphp
                            <option value="instantanee" @selected($frequence === 'instantanee')>Instantanee</option>
                            <option value="journaliere" @selected($frequence === 'journaliere')>Journaliere</option>
                            <option value="hebdomadaire" @selected($frequence === 'hebdomadaire')>Hebdomadaire</option>
                            <option value="mensuelle" @selected($frequence === 'mensuelle')>Mensuelle</option>
                            <option value="annuelle" @selected($frequence === 'annuelle')>Annuelle</option>
                        </select>
                        <p class="field-hint">Le systeme genere automatiquement les periodes de suivi selon cette frequence.</p>
                    </div>
                    <div>
                        <label for="statut">Statut manuel</label>
                        @php
                            $manualStatus = old('statut', $row->statut ?: 'non_demarre');
                        @endphp
                        <select id="statut" name="statut">
                            <option value="non_demarre" @selected($manualStatus === 'non_demarre')>Pilotage automatique</option>
                            <option value="suspendu" @selected($manualStatus === 'suspendu')>Suspendu</option>
                            <option value="annule" @selected($manualStatus === 'annule')>Annule</option>
                        </select>
                        <p class="field-hint">Suspendu gele les KPI. Annule sort l action du pilotage automatique.</p>
                    </div>
                </div>
                <div class="mt-3">
                    <label for="libelle">Titre de l action</label>
                    <input id="libelle" name="libelle" type="text" value="{{ old('libelle', $row->libelle) }}" required>
                </div>
                <div class="mt-3">
                    <label for="description">Description generale</label>
                    <textarea id="description" name="description">{{ old('description', $row->description) }}</textarea>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">2) Definition de la cible</h2>
                <p class="form-section-subtitle">Les champs sont automatiquement figes selon le type de cible choisi.</p>
                <div class="form-grid">
                    <div>
                        <label for="type_cible">Type de cible</label>
                        <select id="type_cible" name="type_cible" required>
                            <option value="quantitative" @selected($targetType === 'quantitative')>Quantitative</option>
                            <option value="qualitative" @selected($targetType === 'qualitative')>Qualitative</option>
                        </select>
                    </div>
                    <div>
                        <label for="seuil_alerte_progression">Seuil alerte progression (%)</label>
                        <input id="seuil_alerte_progression" name="seuil_alerte_progression" type="number" step="0.01" min="0" max="100" value="{{ old('seuil_alerte_progression', $row->seuil_alerte_progression ?? 10) }}">
                    </div>
                </div>

                <div id="zone_quantitative" class="conditional-block mt-3 {{ $targetType === 'quantitative' ? '' : 'hidden' }}">
                    <p class="field-hint mt-0">Cible mesuree avec une unite et une quantite.</p>
                    <div class="form-grid">
                        <div>
                            <label for="unite_cible">Unite</label>
                            <input id="unite_cible" data-required-when="quant" name="unite_cible" type="text" value="{{ old('unite_cible', $row->unite_cible) }}" placeholder="dossiers, formations, inspections...">
                        </div>
                        <div>
                            <label for="quantite_cible">Quantite cible</label>
                            <input id="quantite_cible" data-required-when="quant" name="quantite_cible" type="number" step="0.0001" min="0" value="{{ old('quantite_cible', $row->quantite_cible) }}">
                        </div>
                    </div>
                </div>

                <div id="zone_qualitative" class="conditional-block mt-3 {{ $targetType === 'qualitative' ? '' : 'hidden' }}">
                    <p class="field-hint mt-0">Cible exprimee par resultat, criteres et livrable.</p>
                    <div class="mt-2">
                        <label for="resultat_attendu">Resultat attendu</label>
                        <textarea id="resultat_attendu" data-required-when="qual" name="resultat_attendu">{{ old('resultat_attendu', $row->resultat_attendu) }}</textarea>
                    </div>
                    <div class="mt-3">
                        <label for="criteres_validation">Criteres de validation</label>
                        <textarea id="criteres_validation" data-required-when="qual" name="criteres_validation">{{ old('criteres_validation', $row->criteres_validation) }}</textarea>
                    </div>
                    <div class="mt-3">
                        <label for="livrable_attendu">Livrable attendu</label>
                        <textarea id="livrable_attendu" data-required-when="qual" name="livrable_attendu">{{ old('livrable_attendu', $row->livrable_attendu) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">3) Ressources et financement</h2>
                <p class="form-section-subtitle">Les sous-champs se debloquent uniquement si necessaire.</p>

                <div class="form-grid-compact">
                    <label class="checkbox-pill">
                        <input type="hidden" name="ressource_main_oeuvre" value="0">
                        <input type="checkbox" name="ressource_main_oeuvre" value="1" @checked((bool) old('ressource_main_oeuvre', $row->ressource_main_oeuvre))>
                        Main d oeuvre
                    </label>
                    <label class="checkbox-pill">
                        <input type="hidden" name="ressource_equipement" value="0">
                        <input type="checkbox" name="ressource_equipement" value="1" @checked((bool) old('ressource_equipement', $row->ressource_equipement))>
                        Equipement specialise
                    </label>
                    <label class="checkbox-pill">
                        <input type="hidden" name="ressource_partenariat" value="0">
                        <input type="checkbox" name="ressource_partenariat" value="1" @checked((bool) old('ressource_partenariat', $row->ressource_partenariat))>
                        Partenariat
                    </label>
                    <label class="checkbox-pill">
                        <input type="hidden" name="ressource_autres" value="0">
                        <input type="checkbox" id="ressource_autres" name="ressource_autres" value="1" @checked((bool) old('ressource_autres', $row->ressource_autres))>
                        Autres ressources
                    </label>
                </div>

                <div id="autres_ressources_block" class="conditional-block mt-3">
                    <label for="ressource_autres_details">Details autres ressources</label>
                    <textarea id="ressource_autres_details" name="ressource_autres_details">{{ old('ressource_autres_details', $row->ressource_autres_details) }}</textarea>
                </div>

                <div class="form-grid mt-3">
                    <div>
                        <label for="financement_requis">Financement requis</label>
                        <select id="financement_requis" name="financement_requis" required>
                            <option value="0" @selected((int) old('financement_requis', $row->financement_requis ? 1 : 0) === 0)>Non</option>
                            <option value="1" @selected((int) old('financement_requis', $row->financement_requis ? 1 : 0) === 1)>Oui</option>
                        </select>
                    </div>
                </div>

                <div id="finance_fields" class="conditional-block mt-3">
                    <div class="form-grid">
                        <div>
                            <label for="montant_estime">Montant estimatif</label>
                            <input id="montant_estime" name="montant_estime" type="number" step="0.01" min="0" value="{{ old('montant_estime', $row->montant_estime) }}">
                        </div>
                        <div>
                            <label for="source_financement">Source financement</label>
                            <input id="source_financement" data-required-when="finance" name="source_financement" type="text" value="{{ old('source_financement', $row->source_financement) }}">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label for="description_financement">Description besoin financier</label>
                        <textarea id="description_financement" data-required-when="finance" name="description_financement">{{ old('description_financement', $row->description_financement) }}</textarea>
                    </div>
                    <div class="mt-3">
                        <label for="justificatif_financement">Justificatif financement</label>
                        <div class="showcase-upload-zone">
                            <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">Depot du justificatif financier</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">PDF, Office ou image. Le fichier est securise par le pipeline de stockage de l application.</p>
                            <input
                                id="justificatif_financement"
                                class="mt-4"
                                name="justificatif_financement"
                                type="file"
                                data-required-when="{{ $isEdit ? 'finance-optional' : 'finance-create' }}"
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg"
                            >
                        </div>
                        <p class="field-hint">Fichier requis a la creation si le financement est active.</p>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">4) Risques et pilotage</h2>
                <p class="form-section-subtitle">Anticiper les blocages et definir les mesures preventives.</p>
                <div class="form-grid">
                    <div>
                        <label for="risques">Risques potentiels</label>
                        <textarea id="risques" name="risques">{{ old('risques', $row->risques) }}</textarea>
                    </div>
                    <div>
                        <label for="mesures_preventives">Mesures preventives proposees</label>
                        <textarea id="mesures_preventives" name="mesures_preventives">{{ old('mesures_preventives', $row->mesures_preventives) }}</textarea>
                    </div>
                </div>
            </div>

            @if ($isEdit)
                <p class="mt-2.5 text-sm text-slate-600">
                    Statut dynamique actuel: <strong>{{ $row->statut_dynamique ?: 'non_demarre' }}</strong> |
                    Progression: <strong>{{ number_format((float) ($row->progression_reelle ?? 0), 2) }}%</strong>
                </p>
            @endif

            <div class="form-actions">
                <button class="btn btn-green" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                @if ($isEdit)
                    <a class="btn btn-blue" href="{{ route('workspace.actions.suivi', $row) }}">Voir suivi</a>
                @endif
                <a class="btn btn-blue" href="{{ route('workspace.actions.index') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection

@push('scripts')
    <script>
        (function () {
            var form = document.querySelector('form[data-is-edit]');
            var isEdit = form && form.getAttribute('data-is-edit') === '1';
            var typeSelect = document.getElementById('type_cible');
            var quantZone = document.getElementById('zone_quantitative');
            var qualZone = document.getElementById('zone_qualitative');
            var financementSelect = document.getElementById('financement_requis');
            var financeFields = document.getElementById('finance_fields');
            var autresRessourceCheckbox = document.getElementById('ressource_autres');
            var autresRessourceBlock = document.getElementById('autres_ressources_block');

            function setSectionState(section, enabled) {
                if (!section) {
                    return;
                }

                section.classList.toggle('is-frozen', !enabled);

                var fields = section.querySelectorAll('input, select, textarea');
                fields.forEach(function (field) {
                    if (field.type === 'hidden') {
                        return;
                    }

                    field.disabled = !enabled;

                    var condition = field.getAttribute('data-required-when');
                    if (!condition) {
                        return;
                    }

                    if (condition === 'quant') {
                        field.required = enabled && typeSelect && typeSelect.value === 'quantitative';
                        return;
                    }

                    if (condition === 'qual') {
                        field.required = enabled && typeSelect && typeSelect.value === 'qualitative';
                        return;
                    }

                    if (condition === 'finance') {
                        field.required = enabled;
                        return;
                    }

                    if (condition === 'finance-create') {
                        field.required = enabled && !isEdit;
                        return;
                    }

                    if (condition === 'finance-optional') {
                        field.required = false;
                    }
                });
            }

            function syncTargetZones() {
                if (!typeSelect || !quantZone || !qualZone) {
                    return;
                }

                var isQuant = typeSelect.value === 'quantitative';
                quantZone.classList.toggle('hidden', !isQuant);
                qualZone.classList.toggle('hidden', isQuant);
                setSectionState(quantZone, isQuant);
                setSectionState(qualZone, !isQuant);
            }

            function syncFinanceFields() {
                if (!financementSelect || !financeFields) {
                    return;
                }

                var financeRequired = financementSelect.value === '1';
                setSectionState(financeFields, financeRequired);
            }

            function syncAutresRessources() {
                if (!autresRessourceCheckbox || !autresRessourceBlock) {
                    return;
                }

                var enabled = autresRessourceCheckbox.checked;
                setSectionState(autresRessourceBlock, enabled);
            }

            if (typeSelect) {
                typeSelect.addEventListener('change', syncTargetZones);
            }

            if (financementSelect) {
                financementSelect.addEventListener('change', syncFinanceFields);
            }

            if (autresRessourceCheckbox) {
                autresRessourceCheckbox.addEventListener('change', syncAutresRessources);
            }

            syncTargetZones();
            syncFinanceFields();
            syncAutresRessources();
        })();
    </script>
@endpush
