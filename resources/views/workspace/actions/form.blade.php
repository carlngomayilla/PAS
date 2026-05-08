@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
        $objectifOptions = collect($objectifOptions ?? []);
        $actionOptions = collect($actionOptions ?? []);
        $selectedObjectifId = (int) old('objectif_operationnel_id', $row->objectif_operationnel_id ?: 0);
        if ($selectedObjectifId === 0 && $row->pao_id) {
            $selectedObjectifId = (int) $objectifOptions->firstWhere('pao_id', (int) $row->pao_id)?->id;
        }
        $selectedObjectif = $objectifOptions->firstWhere('id', $selectedObjectifId);
        $selectedPta = $ptaOptions->firstWhere('id', (int) old('pta_id', $row->pta_id))
            ?: $ptaOptions->firstWhere('objectif_operationnel_id', $selectedObjectifId);
        if (!$selectedObjectif && $selectedPta?->objectifOperationnel) {
            $selectedObjectif = $selectedPta->objectifOperationnel;
            $selectedObjectifId = (int) $selectedObjectif->id;
        }
        $selectedPaoId = (int) old('pao_id', $row->pao_id ?: $selectedObjectif?->pao_id ?: $selectedPta?->pao_id);
        $selectedPao = $selectedObjectif?->pao;
        $selectedLinkedActionId = (int) old('existing_action_id', $isEdit ? $row->id : 0);
        $selectedLinkedAction = $actionOptions->firstWhere('id', $selectedLinkedActionId);
        $storedRmoIds = $row->relationLoaded('responsables')
            ? $row->responsables->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
        $oldRmoIds = old('rmo_ids');
        $selectedRmoIds = collect(is_array($oldRmoIds) ? $oldRmoIds : ($storedRmoIds ?: [$row->responsable_id]))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $selectedResponsable = $responsableOptions->firstWhere('id', (int) old('responsable_id', $selectedRmoIds[0] ?? $row->responsable_id));
        $contextOptions = is_array($contextOptions ?? null) ? $contextOptions : \App\Models\Action::contextOptions();
        $originOptions  = is_array($originOptions ?? null)  ? $originOptions  : \App\Models\Action::originOptions();
        $selectedContext = old('contexte_action', $row->contexte_action ?: \App\Models\Action::CONTEXT_PILOTAGE);
        $selectedOrigin  = old('origine_action',  $row->origine_action  ?: (
            $selectedContext === \App\Models\Action::CONTEXT_OPERATIONNEL
                ? \App\Models\Action::ORIGIN_INTERNE
                : \App\Models\Action::ORIGIN_PTA
        ));
        $selectedDirectionLabel       = $selectedPta?->direction?->code
            ? $selectedPta->direction->code . ' — ' . $selectedPta->direction->libelle : '';
        $selectedServiceLabel         = $selectedPta?->service?->code
            ? $selectedPta->service->code . ' — ' . $selectedPta->service->libelle : '';
        $selectedPaoTitle             = $selectedPao?->titre ?? $selectedPta?->pao?->titre ?? '';
        $selectedStrategicObjective   = $selectedPao?->pasObjectif?->code
            ? $selectedPao->pasObjectif->code . ' — ' . $selectedPao->pasObjectif->libelle : '';
        $selectedOperationalObjective = $selectedObjectif?->libelle ?? '';
        if ($selectedPta?->direction?->code) {
            $selectedDirectionLabel = $selectedPta->direction->code . ' - ' . $selectedPta->direction->libelle;
        }
        if ($selectedPta?->service?->code) {
            $selectedServiceLabel = $selectedPta->service->code . ' - ' . $selectedPta->service->libelle;
        }
        if ($selectedPao?->pasObjectif?->code) {
            $selectedStrategicObjective = $selectedPao->pasObjectif->code . ' - ' . $selectedPao->pasObjectif->libelle;
        }
        if ($selectedObjectif?->pasObjectif?->code) {
            $selectedStrategicObjective = $selectedObjectif->pasObjectif->code . ' - ' . $selectedObjectif->pasObjectif->libelle;
        }
        $selectedDirectionLabel = preg_replace('/\s+—\s+/u', ' - ', $selectedDirectionLabel);
        $selectedServiceLabel = preg_replace('/\s+—\s+/u', ' - ', $selectedServiceLabel);
        $selectedStrategicObjective = preg_replace('/\s+—\s+/u', ' - ', $selectedStrategicObjective);
        $financementRequis     = (int) old('financement_requis', $row->financement_requis ? 1 : 0);
        $otherResourcesEnabled = (bool) old('ressource_autres', $row->ressource_autres);
        $selectedTargetType = old('type_cible', $row->type_cible ?: (($row->quantite_cible || $row->unite_cible) ? 'quantitative' : 'qualitative'));
        $selectedThresholdMode = in_array(old('seuil_mode', $row->seuil_mode ?: 'unique'), ['unique', 'trimestriel'], true)
            ? old('seuil_mode', $row->seuil_mode ?: 'unique')
            : 'unique';
        $storedSubActions = $row->relationLoaded('sousActions')
            ? $row->sousActions->map(fn ($subAction) => [
                'id' => $subAction->id,
                'libelle' => $subAction->libelle,
                'description' => $subAction->description,
                'resultat_attendu' => $subAction->resultat_attendu,
                'date_debut' => optional($subAction->date_debut)->format('Y-m-d'),
                'date_fin' => optional($subAction->date_fin)->format('Y-m-d'),
                'cible_prevue' => $subAction->cible_prevue,
                'unite' => $subAction->unite,
                'commentaire' => $subAction->commentaire,
            ])->all()
            : [];
        $oldSubActions = old('sous_actions');
        $subActionRows = collect(is_array($oldSubActions) ? $oldSubActions : $storedSubActions)
            ->filter(fn ($subAction) => is_array($subAction))
            ->values();
        if ($subActionRows->isEmpty()) {
            $subActionRows = collect([[]]);
        }
        $showSubActionForm = $selectedTargetType === 'qualitative';
    @endphp

    <div class="app-screen-flow">

        {{-- Hero --}}
        <section class="showcase-hero mb-4 app-screen-block">
            <div class="showcase-hero-body">
                <div class="max-w-3xl">
                    <span class="showcase-eyebrow">Action</span>
                    <h1 class="showcase-title">{{ $isEdit ? 'Modifier une action' : 'Enregistrer une nouvelle action' }}</h1>
                    <p class="showcase-subtitle">
                        {{ $isEdit ? 'Mettez à jour les informations de l\'action.' : 'Renseignez les informations de la nouvelle action.' }}
                    </p>
                </div>
                <div class="showcase-action-row">
                    @if ($isEdit)
                        <a class="btn btn-follow rounded-2xl px-4 py-2.5" href="{{ route('workspace.actions.suivi', $row) }}">
                            Ouvrir le suivi
                        </a>
                    @endif
                    <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ route('workspace.actions.index') }}">
                        Retour à la liste
                    </a>
                </div>
            </div>
        </section>

        {{-- Indicateur de performance résumé --}}
        <section class="showcase-summary-grid mb-4 app-screen-kpis">
            <article class="showcase-kpi-card">
                <p class="showcase-kpi-label">Contexte</p>
                <p class="showcase-kpi-number text-[1.35rem]">{{ $contextOptions[$selectedContext] ?? 'Pilotage' }}</p>
                <p class="showcase-kpi-meta">{{ $selectedContext === \App\Models\Action::CONTEXT_OPERATIONNEL ? 'Action propre ou assignée' : 'Rattachée au PTA' }}</p>
            </article>
            <article class="showcase-kpi-card">
                <p class="showcase-kpi-label">Objectif opérationnel</p>
                <p id="kpi-obj-op" class="showcase-kpi-number text-[1.1rem] leading-tight truncate">
                    {{ $selectedPao ? 'PAO #' . $selectedPao->id : '--' }}
                </p>
                <p class="showcase-kpi-meta">{{ $selectedPaoTitle ?: 'Aucun objectif sélectionné' }}</p>
            </article>
            <article class="showcase-kpi-card">
                <p class="showcase-kpi-label">PTA sélectionné</p>
                <p id="kpi-pta" class="showcase-kpi-number text-[1.35rem]">{{ $selectedPta ? '#' . $selectedPta->id : '--' }}</p>
                <p id="kpi-pta-titre" class="showcase-kpi-meta">{{ $selectedPta?->titre ?? 'Aucun PTA sélectionné' }}</p>
            </article>
            <article class="showcase-kpi-card">
                <p class="showcase-kpi-label">RMO affectés</p>
                <p id="kpi-rmo-count" class="showcase-kpi-number text-[1.35rem]">{{ count($selectedRmoIds) ?: '--' }}</p>
                <p id="kpi-rmo-label" class="showcase-kpi-meta">{{ $selectedResponsable?->name ?? 'Aucun agent sélectionné' }}</p>
            </article>
        </section>

        <section class="showcase-panel mb-4 app-screen-block">
            <div id="action-indicator-settings" class="hidden"></div>

            <form
                method="POST"
                enctype="multipart/form-data"
                class="form-shell"
                action="{{ $isEdit ? route('workspace.actions.update', $row) : route('workspace.actions.store') }}"
            >
                @csrf
                @if ($isEdit) @method('PUT') @endif

                <input type="hidden" name="contexte_action" value="{{ $selectedContext }}">
                <input type="hidden" name="origine_action" value="{{ $selectedOrigin }}">
                <input id="pao_id" type="hidden" name="pao_id" value="{{ $selectedPaoId ?: '' }}">

                {{-- ============================================================
                     ÉTAPE 1 — Objectif opérationnel (sélection du PAO)
                     ============================================================ --}}
                <div class="form-section">
                    <h2 class="form-section-title">1) Objectif opérationnel</h2>
                    <p class="form-section-subtitle mb-3">
                        Choisissez l'objectif opérationnel concerné. Le PTA, la direction et le service s'affichent automatiquement.
                    </p>

                    <div class="form-grid">
                        {{-- Sélecteur PAO (objectif opérationnel) --}}
                        <div class="md:col-span-2">
                            <label for="pao_id_selector">Objectif opérationnel (PAO)</label>
                            <select id="objectif_operationnel_id_selector" name="objectif_operationnel_id" required>
                                <option value="">— Sélectionner un objectif opérationnel —</option>
                                @foreach ($objectifOptions as $objectif)
                                    @php
                                        $pao = $objectif->pao;
                                        $ptaForObjectif = $ptaOptions->firstWhere('objectif_operationnel_id', $objectif->id);
                                    @endphp
                                    <option
                                        value="{{ $objectif->id }}"
                                        data-strategic-objective="{{ $objectif->pasObjectif?->code ? $objectif->pasObjectif->code . ' - ' . $objectif->pasObjectif->libelle : '' }}"
                                        data-pao-id="{{ $pao?->id }}"
                                        data-pta-id="{{ $ptaForObjectif?->id }}"
                                        data-operational-objective="{{ $objectif->libelle }}"
                                        data-titre="{{ $pao?->titre }}"
                                        data-direction-id="{{ $objectif->direction_id }}"
                                        data-service-id="{{ $objectif->service_id }}"
                                        @selected((int) $selectedObjectifId === (int) $objectif->id)
                                    >
                                        #{{ $objectif->id }} - {{ $objectif->libelle }} ({{ $objectif->service?->code }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Action liée à l'objectif opérationnel sélectionné --}}
                        <div class="md:col-span-2">
                            <label for="existing_action_id">Action liée à l'objectif opérationnel sélectionné</label>
                            <select id="existing_action_id" name="existing_action_id">
                                <option value="">Sélectionner une action déjà enregistrée dans le PTA</option>
                                @foreach ($actionOptions as $actionOption)
                                    <option
                                        value="{{ $actionOption->id }}"
                                        data-objectif-id="{{ $actionOption->objectif_operationnel_id }}"
                                        data-pta-id="{{ $actionOption->pta_id }}"
                                        data-libelle="{{ $actionOption->libelle }}"
                                        data-description="{{ $actionOption->description }}"
                                        data-date-debut="{{ optional($actionOption->date_debut)->format('Y-m-d') }}"
                                        data-date-fin="{{ optional($actionOption->date_fin)->format('Y-m-d') }}"
                                        @selected((int) $selectedLinkedActionId === (int) $actionOption->id)
                                    >
                                        #{{ $actionOption->id }} - {{ $actionOption->libelle }}
                                    </option>
                                @endforeach
                            </select>
                            @error('existing_action_id') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="direction_parent_label">Direction concernée</label>
                            <input id="direction_parent_label" type="text" value="{{ $selectedDirectionLabel }}" readonly>
                        </div>
                        <div>
                            <label for="service_parent_label">Service concerné</label>
                            <input id="service_parent_label" type="text" value="{{ $selectedServiceLabel }}" readonly>
                        </div>
                    </div>
                </div>

                <select id="pta_id" name="pta_id" class="hidden" aria-hidden="true" tabindex="-1">
                    <option value="">Sélectionner un PTA</option>
                    @foreach ($ptaOptions as $pta)
                        <option
                            value="{{ $pta->id }}"
                            data-pao-id="{{ $pta->pao_id }}"
                            data-objectif-id="{{ $pta->objectif_operationnel_id }}"
                            data-service-id="{{ $pta->service_id }}"
                            data-direction-id="{{ $pta->direction_id }}"
                            data-direction-label="{{ $pta->direction?->code }} - {{ $pta->direction?->libelle }}"
                            data-service-label="{{ $pta->service?->code }} - {{ $pta->service?->libelle }}"
                            data-pao-title="{{ $pta->pao?->titre }}"
                            data-pta-title="{{ $pta->titre }}"
                            @selected((int) old('pta_id', $row->pta_id) === $pta->id)
                        >
                            #{{ $pta->id }} - {{ $pta->titre }}
                        </option>
                    @endforeach
                </select>
                @error('pta_id')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                {{-- ============================================================
                     ÉTAPE 2 — Action et responsabilité
                     ============================================================ --}}
                <div id="action_section" class="form-section {{ $selectedPta ? '' : 'hidden' }}">
                    <h2 class="form-section-title">2) Action et responsabilité</h2>

                    <div class="form-grid">
                        {{-- RMO --}}
                        <div class="md:col-span-2">
                            <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <label class="mb-0" for="rmo_ids">RMO</label>
                                <button class="btn btn-secondary" type="button" id="add-action-rmo">+ Ajouter un autre RMO</button>
                            </div>
                            <input id="responsable_id" name="responsable_id" type="hidden" value="{{ old('responsable_id', $selectedRmoIds[0] ?? $row->responsable_id) }}">
                            <div id="action-rmo-list" class="space-y-2">
                                <div class="flex gap-2" data-action-rmo-row>
                            <select id="rmo_ids" name="rmo_ids[]" required>
                                <option value="">Sélectionner un RMO</option>
                                @foreach ($responsableOptions as $user)
                                    <option value="{{ $user->id }}" data-direction-id="{{ $user->direction_id }}" data-service-id="{{ $user->service_id }}" @selected(in_array((int) $user->id, $selectedRmoIds, true))>
                                        {{ $user->name }}
                                        @if (!empty($user->agent_matricule)) — [{{ $user->agent_matricule }}] @endif
                                        @if (!empty($user->agent_fonction)) — {{ $user->agent_fonction }} @endif
                                        ({{ $user->roleLabel() }})
                                    </option>
                                @endforeach
                            </select>
                                    <button class="btn btn-outline hidden" type="button" data-remove-action-rmo>Retirer</button>
                                </div>
                            </div>
                            @error('rmo_ids') <p class="field-error">{{ $message }}</p> @enderror
                            @error('responsable_id') <p class="field-error">{{ $message }}</p> @enderror
                            @if ($responsableOptions->isEmpty())
                                <p class="field-hint text-[#f9b13c]">Aucun utilisateur actif disponible pour votre périmètre.</p>
                            @endif
                        </div>

                        {{-- Libellé de l'action --}}
                        <div class="md:col-span-2">
                            <label for="libelle">Titre de l'action <span class="text-red-500">*</span></label>
                            <input id="libelle" name="libelle" type="text" value="{{ old('libelle', $row->libelle) }}" required>
                            @error('libelle') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        {{-- Description --}}
                        <div class="md:col-span-2">
                            <label for="description">Description</label>
                            <textarea id="description" name="description">{{ old('description', $row->description) }}</textarea>
                        </div>

                        {{-- Résultat attendu --}}
                        <div class="md:col-span-2">
                            <label for="resultat_attendu">Résultat attendu</label>
                            <textarea id="resultat_attendu" name="resultat_attendu">{{ old('resultat_attendu', $row->resultat_attendu) }}</textarea>
                        </div>

                        {{-- Cible --}}
                        <div>
                            <label for="type_cible">Cible</label>
                            <select id="type_cible" name="type_cible" class="mb-3" required>
                                <option value="quantitative" @selected($selectedTargetType === 'quantitative')>Cible quantitative</option>
                                <option value="qualitative" @selected($selectedTargetType === 'qualitative')>Cible par sous-action</option>
                            </select>
                            <input id="quantite_cible" name="quantite_cible" type="number" step="0.0001" min="0" data-quantitative-target
                                value="{{ old('quantite_cible', $row->quantite_cible) }}">
                            <input id="unite_cible" name="unite_cible" type="text" class="mt-3" data-quantitative-target placeholder="Unite" value="{{ old('unite_cible', $row->unite_cible) }}">
                        </div>

                        <div>
                            <label for="seuil_mode">Mode de seuil</label>
                            <select id="seuil_mode" name="seuil_mode" data-threshold-mode>
                                <option value="unique" @selected($selectedThresholdMode === 'unique')>Seuil unique</option>
                                <option value="trimestriel" @selected($selectedThresholdMode === 'trimestriel')>Seuil par trimestre</option>
                            </select>
                        </div>

                        <div id="threshold_unique" data-threshold-unique class="{{ $selectedThresholdMode === 'unique' ? '' : 'hidden' }}">
                            <label for="seuil_minimum">Seuil minimum attendu (%)</label>
                            <input id="seuil_minimum" name="seuil_minimum" type="number" step="0.01" min="0" max="100"
                                value="{{ old('seuil_minimum', $row->seuil_minimum ?? 80) }}">
                            @error('seuil_minimum') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <div id="threshold_quarterly" class="md:col-span-2 {{ $selectedThresholdMode === 'trimestriel' ? '' : 'hidden' }}" data-threshold-quarterly>
                            <label>Seuils trimestriels (%)</label>
                            <div class="form-grid-compact">
                                <input name="seuil_t1" type="number" step="0.01" min="0" max="100" value="{{ old('seuil_t1', $row->seuil_t1) }}" placeholder="T1">
                                <input name="seuil_t2" type="number" step="0.01" min="0" max="100" value="{{ old('seuil_t2', $row->seuil_t2) }}" placeholder="T2">
                                <input name="seuil_t3" type="number" step="0.01" min="0" max="100" value="{{ old('seuil_t3', $row->seuil_t3) }}" placeholder="T3">
                                <input name="seuil_t4" type="number" step="0.01" min="0" max="100" value="{{ old('seuil_t4', $row->seuil_t4) }}" placeholder="T4">
                            </div>
                        </div>

                        {{-- Fréquence --}}
                        <div>
                            <label for="frequence_execution">Fréquence de suivi</label>
                            @php $frequence = old('frequence_execution', $row->frequence_execution ?: 'hebdomadaire'); @endphp
                            <select id="frequence_execution" name="frequence_execution" required>
                                <option value="instantanee"   @selected($frequence === 'instantanee')>Instantanée</option>
                                <option value="journaliere"   @selected($frequence === 'journaliere')>Journalière</option>
                                <option value="hebdomadaire"  @selected($frequence === 'hebdomadaire')>Hebdomadaire</option>
                                <option value="mensuelle"     @selected($frequence === 'mensuelle')>Mensuelle</option>
                                <option value="annuelle"      @selected($frequence === 'annuelle')>Annuelle</option>
                            </select>
                        </div>

                        {{-- Date début --}}
                        <div>
                            <label for="date_debut">Date de début <span class="text-red-500">*</span></label>
                            <input id="date_debut" name="date_debut" type="date"
                                value="{{ old('date_debut', optional($row->date_debut)->format('Y-m-d')) }}" required>
                            @error('date_debut') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        {{-- Date fin --}}
                        <div>
                            <label for="date_fin">Date de fin prévue <span class="text-red-500">*</span></label>
                            <input id="date_fin" name="date_fin" type="date"
                                value="{{ old('date_fin', optional($row->date_fin)->format('Y-m-d')) }}" required>
                            @error('date_fin') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <section id="sub_actions_section" class="mt-4 rounded-lg border border-[#e5e7eb] bg-white p-4 {{ $showSubActionForm ? '' : 'hidden' }}" data-sub-actions-section>
                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="text-sm font-extrabold text-[#3996d3]">Sous-actions prévues</h3>
                            <button class="btn btn-secondary" type="button" id="add-sub-action">+ Ajouter une sous-action</button>
                        </div>
                        <div class="space-y-3" id="sub-actions-list" data-sub-actions-list>
                            @foreach ($subActionRows as $subIndex => $subAction)
                                <div class="rounded-lg border border-[#e5e7eb] bg-[#f8fbfe] p-3" data-sub-action-row>
                                    <input type="hidden" name="sous_actions[{{ $subIndex }}][id]" value="{{ $subAction['id'] ?? '' }}">
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <strong class="text-xs uppercase text-[#1c203d]">Sous-action</strong>
                                        <button class="btn btn-outline text-xs {{ $subIndex === 0 ? 'hidden' : '' }}" type="button" data-remove-sub-action>Retirer</button>
                                    </div>
                                    <div class="form-grid">
                                        <div class="md:col-span-2">
                                            <label>Libellé</label>
                                            <input name="sous_actions[{{ $subIndex }}][libelle]" type="text" value="{{ $subAction['libelle'] ?? '' }}">
                                        </div>
                                        <div>
                                            <label>Date de début</label>
                                            <input name="sous_actions[{{ $subIndex }}][date_debut]" type="date" value="{{ $subAction['date_debut'] ?? old('date_debut', optional($row->date_debut)->format('Y-m-d')) }}">
                                        </div>
                                        <div>
                                            <label>Date de fin</label>
                                            <input name="sous_actions[{{ $subIndex }}][date_fin]" type="date" value="{{ $subAction['date_fin'] ?? old('date_fin', optional($row->date_fin)->format('Y-m-d')) }}">
                                        </div>
                                        <div>
                                            <label>Cible prévue</label>
                                            <input name="sous_actions[{{ $subIndex }}][cible_prevue]" type="number" step="0.0001" min="0" value="{{ $subAction['cible_prevue'] ?? '' }}">
                                        </div>
                                        <div>
                                            <label>Unité</label>
                                            <input name="sous_actions[{{ $subIndex }}][unite]" type="text" value="{{ $subAction['unite'] ?? old('unite_cible', $row->unite_cible) }}">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label>Description</label>
                                            <textarea name="sous_actions[{{ $subIndex }}][description]">{{ $subAction['description'] ?? '' }}</textarea>
                                        </div>
                                        <div class="md:col-span-2">
                                            <label>Résultat attendu</label>
                                            <textarea name="sous_actions[{{ $subIndex }}][resultat_attendu]">{{ $subAction['resultat_attendu'] ?? '' }}</textarea>
                                        </div>
                                        <div class="md:col-span-2">
                                            <label>Commentaire</label>
                                            <textarea name="sous_actions[{{ $subIndex }}][commentaire]">{{ $subAction['commentaire'] ?? '' }}</textarea>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                </div>

                {{-- ============================================================
                     ÉTAPE 3 — Ressources et financement
                     ============================================================ --}}
                <div id="resources_section" class="form-section {{ $selectedPta ? '' : 'hidden' }}">
                    <h2 class="form-section-title">3) Ressources</h2>

                    <div class="form-grid-compact">
                        <label class="hidden">
                            <input type="hidden" name="ressource_main_oeuvre" value="0">
                            <input type="checkbox" name="ressource_main_oeuvre" value="1"
                                @checked((bool) old('ressource_main_oeuvre', $row->ressource_main_oeuvre))>
                            Main d'œuvre
                        </label>
                        <label class="checkbox-pill">
                            <input type="hidden" name="ressource_equipement" value="0">
                            <input type="checkbox" name="ressource_equipement" value="1"
                                @checked((bool) old('ressource_equipement', $row->ressource_equipement))>
                            Équipement
                        </label>
                        <label class="checkbox-pill">
                            <input type="hidden" name="ressource_partenariat" value="0">
                            <input type="checkbox" name="ressource_partenariat" value="1"
                                @checked((bool) old('ressource_partenariat', $row->ressource_partenariat))>
                            Partenariat
                        </label>
                        <label class="checkbox-pill">
                            <input type="hidden" name="ressource_autres" value="0">
                            <input type="checkbox" id="ressource_autres" name="ressource_autres" value="1"
                                @checked($otherResourcesEnabled)>
                            Autres ressources
                        </label>
                    </div>

                    <div id="autres_ressources_block" class="conditional-block mt-3 {{ $otherResourcesEnabled ? '' : 'hidden' }}">
                        <label for="ressource_autres_details">Détails autres ressources</label>
                        <textarea id="ressource_autres_details" name="ressource_autres_details">{{ old('ressource_autres_details', $row->ressource_autres_details) }}</textarea>
                    </div>

                    <div class="form-grid mt-3">
                        <div>
                            <label for="financement_requis">Besoin de financement</label>
                            <select id="financement_requis" name="financement_requis" required>
                                <option value="0" @selected($financementRequis === 0)>Non</option>
                                <option value="1" @selected($financementRequis === 1)>Oui</option>
                            </select>
                        </div>
                        <div>
                            <label for="montant_estime">Montant estimé</label>
                            <input id="montant_estime" name="montant_estime" type="number" step="0.01" min="0"
                                value="{{ old('montant_estime', $row->montant_estime) }}">
                        </div>
                    </div>

                    <div id="finance_fields" class="conditional-block mt-3 {{ $financementRequis === 1 ? '' : 'hidden' }}">
                        <div>
                            <label for="description_financement">Description du besoin de financement</label>
                            <textarea id="description_financement" name="description_financement">{{ old('description_financement', $row->description_financement) }}</textarea>
                        </div>
                        <div class="mt-3">
                            <label for="justificatif_financement">Pièce justificative</label>
                            <div class="showcase-upload-zone">
                                <p class="text-sm font-semibold text-slate-800">Déposer le justificatif</p>
                                <p class="mt-1 text-xs text-slate-500">PDF, Office ou image.</p>
                                <input
                                    id="justificatif_financement"
                                    class="mt-4"
                                    name="justificatif_financement"
                                    type="file"
                                    accept="{{ app(\App\Services\DocumentPolicySettings::class)->acceptAttribute() }}"
                                >
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ============================================================
                     ÉTAPE 4 — Risques
                     ============================================================ --}}
                <div id="risks_section" class="form-section {{ $selectedPta ? '' : 'hidden' }}">
                    <h2 class="form-section-title">4) Risques</h2>
                    <div class="form-grid">
                        <div>
                            <label for="risques">Risques identifiés</label>
                            <textarea id="risques" name="risques">{{ old('risques', $row->risques) }}</textarea>
                        </div>
                        <div>
                            <label for="mesures_preventives">Mesures préventives</label>
                            <textarea id="mesures_preventives" name="mesures_preventives">{{ old('mesures_preventives', $row->mesures_preventives) }}</textarea>
                        </div>
                    </div>
                </div>

                @if ($isEdit)
                    <p class="mt-2.5 text-sm text-slate-600">
                        Statut dynamique : <strong>{{ $row->statut_dynamique ?: 'non_demarre' }}</strong> |
                        Progression : <strong>{{ number_format((float) ($row->progression_reelle ?? 0), 2) }}%</strong>
                    </p>
                @endif

                <div id="form_actions" class="form-actions {{ $selectedPta ? '' : 'hidden' }}">
                    <button class="btn btn-green" type="submit">{{ $isEdit ? 'Mettre à jour' : 'Enregistrer l\'action' }}</button>
                    @if ($isEdit)
                        <a class="btn btn-follow" href="{{ route('workspace.actions.suivi', $row) }}">Voir le suivi</a>
                    @endif
                    <a class="btn btn-blue" href="{{ route('workspace.actions.index') }}">Retour</a>
                </div>
            </form>
        </section>
    </div>

    <template id="action-rmo-template">
        <div class="flex gap-2" data-action-rmo-row>
            <select name="rmo_ids[]" required>
                <option value="">Sélectionner un RMO</option>
                @foreach ($responsableOptions as $user)
                    <option value="{{ $user->id }}" data-direction-id="{{ $user->direction_id }}" data-service-id="{{ $user->service_id }}">
                        {{ $user->name }}
                        @if (!empty($user->agent_matricule)) - [{{ $user->agent_matricule }}] @endif
                        @if (!empty($user->agent_fonction)) - {{ $user->agent_fonction }} @endif
                        ({{ $user->roleLabel() }})
                    </option>
                @endforeach
            </select>
            <button class="btn btn-outline" type="button" data-remove-action-rmo>Retirer</button>
        </div>
    </template>

    <template id="sub-action-template">
        <div class="rounded-lg border border-[#e5e7eb] bg-[#f8fbfe] p-3" data-sub-action-row>
            <input type="hidden" name="sous_actions[__INDEX__][id]" value="">
            <div class="mb-2 flex items-center justify-between gap-2">
                <strong class="text-xs uppercase text-[#1c203d]">Sous-action</strong>
                <button class="btn btn-outline text-xs" type="button" data-remove-sub-action>Retirer</button>
            </div>
            <div class="form-grid">
                <div class="md:col-span-2">
                    <label>Libellé</label>
                    <input name="sous_actions[__INDEX__][libelle]" type="text" value="">
                </div>
                <div>
                    <label>Date de début</label>
                    <input name="sous_actions[__INDEX__][date_debut]" type="date" value="">
                </div>
                <div>
                    <label>Date de fin</label>
                    <input name="sous_actions[__INDEX__][date_fin]" type="date" value="">
                </div>
                <div>
                    <label>Cible prévue</label>
                    <input name="sous_actions[__INDEX__][cible_prevue]" type="number" step="0.0001" min="0" value="">
                </div>
                <div>
                    <label>Unité</label>
                    <input name="sous_actions[__INDEX__][unite]" type="text" value="">
                </div>
                <div class="md:col-span-2">
                    <label>Description</label>
                    <textarea name="sous_actions[__INDEX__][description]"></textarea>
                </div>
                <div class="md:col-span-2">
                    <label>Résultat attendu</label>
                    <textarea name="sous_actions[__INDEX__][resultat_attendu]"></textarea>
                </div>
                <div class="md:col-span-2">
                    <label>Commentaire</label>
                    <textarea name="sous_actions[__INDEX__][commentaire]"></textarea>
                </div>
            </div>
        </div>
    </template>
@endsection

@push('scripts')
<script @cspNonce>
(function () {
    var paoSelector         = document.getElementById('objectif_operationnel_id_selector');
    var ptaSection          = document.getElementById('pta_section');
    var actionSection       = document.getElementById('action_section');
    var resourcesSection    = document.getElementById('resources_section');
    var risksSection        = document.getElementById('risks_section');
    var formActions         = document.getElementById('form_actions');

    var ptaSelect           = document.getElementById('pta_id');
    var paoHiddenInput      = document.getElementById('pao_id');
    var directionLabel      = document.getElementById('direction_parent_label');
    var serviceLabel        = document.getElementById('service_parent_label');
    var strategicObjLabel   = document.getElementById('objectif_strategique_label');
    var existingActionSelect = document.getElementById('existing_action_id');

    var financementSelect   = document.getElementById('financement_requis');
    var financeFields       = document.getElementById('finance_fields');
    var autresCheckbox      = document.getElementById('ressource_autres');
    var autresBlock         = document.getElementById('autres_ressources_block');
    var targetTypeSelect    = document.getElementById('type_cible');
    var thresholdModeSelect = document.getElementById('seuil_mode');
    var thresholdUnique     = document.getElementById('threshold_unique');
    var thresholdQuarterly  = document.getElementById('threshold_quarterly');

    var kpiObjOp  = document.getElementById('kpi-obj-op');
    var kpiPta    = document.getElementById('kpi-pta');
    var kpiPtaTitre = document.getElementById('kpi-pta-titre');
    var rmoList = document.getElementById('action-rmo-list');
    var rmoTemplate = document.getElementById('action-rmo-template');
    var addRmoButton = document.getElementById('add-action-rmo');
    var primaryResponsableInput = document.getElementById('responsable_id');
    var kpiRmoCount = document.getElementById('kpi-rmo-count');
    var kpiRmoLabel = document.getElementById('kpi-rmo-label');
    var subActionsSection = document.getElementById('sub_actions_section');
    var subActionsList = document.getElementById('sub-actions-list');
    var subActionTemplate = document.getElementById('sub-action-template');
    var addSubActionButton = document.getElementById('add-sub-action');

    function show(el) { if (el) el.classList.remove('hidden'); }
    function hide(el) { if (el) el.classList.add('hidden'); }

    function setVal(el, val) { if (el) el.value = val || ''; }

    function disableFields(section, disabled) {
        if (!section) return;
        section.querySelectorAll('input, textarea, select').forEach(function (f) {
            if (f.type !== 'hidden') f.disabled = disabled;
        });
    }

    function filterExistingActions(objectifId) {
        if (!existingActionSelect) return;

        var currentValue = existingActionSelect.value;
        var firstMatch = null;
        Array.prototype.forEach.call(existingActionSelect.options, function (option) {
            if (!option.value) return;
            var match = option.getAttribute('data-objectif-id') === objectifId;
            option.hidden = !match;
            option.disabled = !match;
            if (match && !firstMatch) firstMatch = option;
        });

        var currentOption = existingActionSelect.options[existingActionSelect.selectedIndex];
        if (currentOption && currentOption.value && currentOption.getAttribute('data-objectif-id') !== objectifId) {
            existingActionSelect.value = '';
        } else if (currentValue) {
            existingActionSelect.value = currentValue;
        }
    }

    function applySelectedAction() {
        if (!existingActionSelect) return;

        var option = existingActionSelect.options[existingActionSelect.selectedIndex];
        if (!option || !option.value) return;

        setVal(document.getElementById('libelle'), option.getAttribute('data-libelle') || '');
        setVal(document.getElementById('description'), option.getAttribute('data-description') || '');
        setVal(document.getElementById('date_debut'), option.getAttribute('data-date-debut') || '');
        setVal(document.getElementById('date_fin'), option.getAttribute('data-date-fin') || '');

    }

    function rmoSelects() {
        return rmoList
            ? Array.prototype.slice.call(rmoList.querySelectorAll('select[name="rmo_ids[]"]'))
            : [];
    }

    function filterRmosForScope() {
        var ptaOption = ptaSelect ? ptaSelect.options[ptaSelect.selectedIndex] : null;
        var directionId = ptaOption ? (ptaOption.getAttribute('data-direction-id') || '') : '';
        var serviceId = ptaOption ? (ptaOption.getAttribute('data-service-id') || '') : '';

        rmoSelects().forEach(function (select) {
            var current = select.value;
            Array.prototype.forEach.call(select.options, function (option, index) {
                if (index === 0) {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }

                var optionDirection = option.getAttribute('data-direction-id') || '';
                var optionService = option.getAttribute('data-service-id') || '';
                var visible = (!directionId || !optionDirection || optionDirection === directionId)
                    && (!serviceId || !optionService || optionService === serviceId);
                option.hidden = !visible;
                option.disabled = !visible;
                if (!visible && option.value === current) {
                    select.value = '';
                }
            });
        });

        syncRmoSelection();
    }

    function refreshRmoRows() {
        if (!rmoList) return;

        rmoList.querySelectorAll('[data-action-rmo-row]').forEach(function (row, index) {
            var select = row.querySelector('select');
            var remove = row.querySelector('[data-remove-action-rmo]');
            if (select) select.id = 'rmo_ids_' + index;
            if (remove) remove.classList.toggle('hidden', index === 0);
        });
    }

    function addRmoRow() {
        if (!rmoList || !rmoTemplate) return;
        rmoList.insertAdjacentHTML('beforeend', rmoTemplate.innerHTML);
        refreshRmoRows();
        filterRmosForScope();
    }

    function subActionRows() {
        return subActionsList
            ? Array.prototype.slice.call(subActionsList.querySelectorAll('[data-sub-action-row]'))
            : [];
    }

    function refreshSubActionRows() {
        subActionRows().forEach(function (row, index) {
            var remove = row.querySelector('[data-remove-sub-action]');
            if (remove) remove.classList.toggle('hidden', index === 0);
        });
    }

    function addSubActionRow() {
        if (!subActionsList || !subActionTemplate) return;

        var index = subActionRows().length;
        var html = subActionTemplate.innerHTML.split('__INDEX__').join(String(index));
        subActionsList.insertAdjacentHTML('beforeend', html);

        var row = subActionsList.querySelector('[data-sub-action-row]:last-child');
        if (row) {
            var actionStart = document.getElementById('date_debut');
            var actionEnd = document.getElementById('date_fin');
            var actionUnit = document.getElementById('unite_cible');
            var subStart = row.querySelector('input[name$="[date_debut]"]');
            var subEnd = row.querySelector('input[name$="[date_fin]"]');
            var subUnit = row.querySelector('input[name$="[unite]"]');
            if (subStart && actionStart) subStart.value = actionStart.value;
            if (subEnd && actionEnd) subEnd.value = actionEnd.value;
            if (subUnit && actionUnit) subUnit.value = actionUnit.value;
        }

        refreshSubActionRows();
    }

    function syncThresholdMode() {
        var mode = thresholdModeSelect ? thresholdModeSelect.value : 'unique';
        if (thresholdUnique) thresholdUnique.classList.toggle('hidden', mode !== 'unique');
        if (thresholdQuarterly) thresholdQuarterly.classList.toggle('hidden', mode !== 'trimestriel');
    }

    // ── Étape 1 : changement d'objectif opérationnel (PAO) ──────────────────
    function onPaoChange() {
        var opt = paoSelector ? paoSelector.options[paoSelector.selectedIndex] : null;
        var objectifId = paoSelector ? paoSelector.value : '';
        var paoId = opt ? (opt.getAttribute('data-pao-id') || '') : '';
        var selectedPtaId = opt ? (opt.getAttribute('data-pta-id') || '') : '';
        var selectedServiceId = opt ? (opt.getAttribute('data-service-id') || '') : '';

        setVal(paoHiddenInput, paoId);

        // Mise à jour des champs texte lectures seules
        setVal(strategicObjLabel,  opt ? (opt.getAttribute('data-strategic-objective') || '') : '');
        filterExistingActions(objectifId);

        // Mise à jour Indicateur de performance card
        if (kpiObjOp) kpiObjOp.textContent = objectifId ? 'Obj. #' + objectifId : '--';

        if (!objectifId) {
            // Masquer toutes les étapes suivantes
            hide(ptaSection);
            hide(actionSection);
            hide(resourcesSection);
            hide(risksSection);
            hide(formActions);
            setVal(ptaSelect, '');
            return;
        }

        // Filtrer les options du select PTA
        var visibleCount = 0;
        var firstVisible = null;
        Array.prototype.forEach.call(ptaSelect.options, function (o) {
            if (!o.value) return;
            var match = o.getAttribute('data-objectif-id') === objectifId
                || (selectedPtaId !== '' && o.value === selectedPtaId);
            o.hidden   = !match;
            o.disabled = !match;
            if (match) { visibleCount++; if (!firstVisible) firstVisible = o; }
        });

        // Si le PTA actuellement sélectionné n'appartient pas à ce PAO → reset
        var currentOpt = ptaSelect.options[ptaSelect.selectedIndex];
        if (currentOpt && currentOpt.getAttribute('data-objectif-id') !== objectifId) {
            ptaSelect.value = '';
        }

        // Auto-sélection si un seul PTA disponible
        if (visibleCount === 1 && firstVisible) {
            ptaSelect.value = firstVisible.value;
        }

        onPtaChange();
    }

    // ── Étape 2 : changement de PTA ─────────────────────────────────────────
    function onPtaChange() {
        var opt = ptaSelect ? ptaSelect.options[ptaSelect.selectedIndex] : null;
        var ptaId = ptaSelect ? ptaSelect.value : '';

        setVal(directionLabel, opt ? (opt.getAttribute('data-direction-label') || '') : '');
        setVal(serviceLabel,   opt ? (opt.getAttribute('data-service-label')   || '') : '');

        // Mise à jour Indicateur de performance cards
        if (kpiPta)      kpiPta.textContent      = ptaId ? '#' + ptaId : '--';
        if (kpiPtaTitre) kpiPtaTitre.textContent = opt ? (opt.getAttribute('data-pta-title') || 'Aucun PTA selectionne') : 'Aucun PTA selectionne';

        var hasPta = !!ptaId;
        if (hasPta) {
            show(actionSection);
            show(resourcesSection);
            show(risksSection);
            show(formActions);
            disableFields(actionSection,    false);
            disableFields(resourcesSection, false);
            disableFields(risksSection,     false);
            filterRmosForScope();
        } else {
            hide(actionSection);
            hide(resourcesSection);
            hide(risksSection);
            hide(formActions);
        }
    }

    // ── Financement conditionnel ─────────────────────────────────────────────
    function syncRmoSelection() {
        var selects = rmoSelects();
        if (!selects.length) {
            return;
        }

        var selected = selects
            .map(function (select) { return select.options[select.selectedIndex]; })
            .filter(function (option) { return option && option.value; });
        var first = selected[0] || null;

        if (primaryResponsableInput) {
            primaryResponsableInput.value = first ? first.value : '';
        }

        if (kpiRmoCount) {
            kpiRmoCount.textContent = selected.length ? String(selected.length) : '--';
        }

        if (kpiRmoLabel) {
            kpiRmoLabel.textContent = first ? first.textContent.trim() : 'Aucun agent selectionne';
        }
    }

    function syncFinanceFields() {
        if (!financementSelect || !financeFields) return;
        var show_finance = financementSelect.value === '1';
        financeFields.classList.toggle('hidden', !show_finance);
        financeFields.querySelectorAll('input, textarea').forEach(function (f) {
            if (f.type !== 'hidden') f.disabled = !show_finance;
        });
    }

    function syncAutresRessources() {
        if (!autresCheckbox || !autresBlock) return;
        autresBlock.classList.toggle('hidden', !autresCheckbox.checked);
        autresBlock.querySelectorAll('textarea').forEach(function (f) {
            f.disabled = !autresCheckbox.checked;
        });
    }

    function syncTargetType() {
        if (!targetTypeSelect) return;
        var quantitative = targetTypeSelect.value === 'quantitative';
        document.querySelectorAll('[data-quantitative-target]').forEach(function (field) {
            field.classList.toggle('hidden', !quantitative);
            field.disabled = !quantitative;
            if (field.id === 'quantite_cible' || field.id === 'unite_cible') {
                field.required = quantitative;
            }
        });

        if (subActionsSection) {
            subActionsSection.classList.toggle('hidden', quantitative);
            subActionsSection.querySelectorAll('input, textarea, select, button').forEach(function (field) {
                field.disabled = quantitative;
            });
            if (!quantitative) {
                refreshSubActionRows();
            }
        }
    }

    // ── Écouteurs ────────────────────────────────────────────────────────────
    if (paoSelector)      paoSelector.addEventListener('change', onPaoChange);
    if (ptaSelect)        ptaSelect.addEventListener('change', onPtaChange);
    if (existingActionSelect) existingActionSelect.addEventListener('change', applySelectedAction);
    if (addRmoButton) addRmoButton.addEventListener('click', addRmoRow);
    if (rmoList) {
        rmoList.addEventListener('change', syncRmoSelection);
        rmoList.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement) || !target.matches('[data-remove-action-rmo]')) return;

            var row = target.closest('[data-action-rmo-row]');
            if (row && rmoList.querySelectorAll('[data-action-rmo-row]').length > 1) {
                row.remove();
                refreshRmoRows();
                syncRmoSelection();
            }
        });
    }
    if (financementSelect) financementSelect.addEventListener('change', syncFinanceFields);
    if (autresCheckbox)   autresCheckbox.addEventListener('change', syncAutresRessources);
    if (targetTypeSelect) targetTypeSelect.addEventListener('change', syncTargetType);
    if (thresholdModeSelect) thresholdModeSelect.addEventListener('change', syncThresholdMode);
    if (addSubActionButton) addSubActionButton.addEventListener('click', addSubActionRow);
    if (subActionsList) {
        subActionsList.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement) || !target.matches('[data-remove-sub-action]')) return;

            var row = target.closest('[data-sub-action-row]');
            if (row && subActionRows().length > 1) {
                row.remove();
                refreshSubActionRows();
            }
        });
    }

    // ── Initialisation ───────────────────────────────────────────────────────
    onPaoChange();
    refreshRmoRows();
    refreshSubActionRows();
    filterRmosForScope();
    syncRmoSelection();
    syncFinanceFields();
    syncAutresRessources();
    syncThresholdMode();
    syncTargetType();
})();
</script>
@endpush
