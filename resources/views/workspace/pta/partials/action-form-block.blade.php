@php
    $isTemplate = (bool) ($isTemplate ?? false);
    $index = $index ?? 0;
    $number = $isTemplate ? '__NUMBER__' : ((int) $index + 1);
    $rowData = is_array($rowData ?? null) ? $rowData : [];
    $selectedRmos = collect($selectedRmos ?? [null]);
    if ($selectedRmos->isEmpty()) {
        $selectedRmos = collect([null]);
    }
    $resourceOptions = \App\Models\Action::resourceOptions();
    $selectedResources = collect($rowData['ressources_necessaires'] ?? [])
        ->filter(fn ($value): bool => is_string($value))
        ->values()
        ->all();
    $financementRequis = filter_var($rowData['financement_requis'] ?? false, FILTER_VALIDATE_BOOL);
    $justificatifObligatoire = filter_var($rowData['justificatif_obligatoire'] ?? false, FILTER_VALIDATE_BOOL);
    $modeEvaluation = $rowData['mode_evaluation'] ?? \App\Models\Action::MODE_SOUS_ACTIONS;
    if ($modeEvaluation === \App\Models\Action::MODE_MIXTE) {
        $modeEvaluation = \App\Models\Action::MODE_QUANTITATIF;
    }
    $thresholdMode = in_array(($rowData['seuil_mode'] ?? 'unique'), ['unique', 'trimestriel'], true)
        ? (string) $rowData['seuil_mode']
        : 'unique';
    $subActionRows = collect($rowData['sous_actions'] ?? [])
        ->filter(fn ($subAction): bool => is_array($subAction))
        ->values();
    if ($subActionRows->isEmpty()) {
        $subActionRows = collect([[]]);
    }
    $showSubActionForm = $modeEvaluation === \App\Models\Action::MODE_SOUS_ACTIONS;
@endphp

<div class="rounded-lg border border-[#e5e7eb] bg-white p-4 shadow-sm" data-action-block data-action-index="{{ $index }}">
    <div class="mb-4 flex items-center justify-between gap-3">
        <h3 class="text-sm font-extrabold uppercase tracking-wide text-[#1c203d]" data-action-title>Action {{ $number }}</h3>
        <button class="btn btn-danger {{ !$isTemplate && (int) $index === 0 ? 'hidden' : '' }}" type="button" data-remove-action>Supprimer</button>
    </div>

    <input type="hidden" name="actions[{{ $index }}][id]" value="{{ $rowData['id'] ?? '' }}">

    <div class="space-y-4">
        <section class="rounded-lg border border-[#e5e7eb] bg-white p-4">
            <h4 class="mb-3 text-sm font-extrabold text-[#3996d3]">1. Identification de l'action</h4>
            <div class="form-grid">
                <div class="md:col-span-2">
                    <label>Libellé de l'action</label>
                    <input name="actions[{{ $index }}][libelle]" type="text" value="{{ $rowData['libelle'] ?? '' }}" required>
                    @error("actions.$index.libelle") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="hidden md:col-span-2">
                    <label>Description de l'action</label>
                    <textarea class="hidden" disabled>{{ $rowData['description'] ?? '' }}</textarea>
                </div>
                <div class="hidden md:col-span-2">
                    <label>Résultat attendu</label>
                    <textarea class="hidden" disabled>{{ $rowData['resultat_attendu'] ?? '' }}</textarea>
                </div>
                <div class="hidden">
                    <label>Statut initial</label>
                    @php $actionStatus = $rowData['statut'] ?? 'non_demarre'; @endphp
                    <select class="hidden" disabled>
                        <option value="non_demarre" @selected($actionStatus === 'non_demarre')>Non démarrée</option>
                        <option value="en_cours" @selected($actionStatus === 'en_cours')>En cours</option>
                        <option value="suspendu" @selected($actionStatus === 'suspendu')>Suspendue</option>
                    </select>
                </div>
                <div class="hidden md:col-span-2">
                    <label>Observations internes</label>
                    <textarea class="hidden" disabled>{{ $rowData['observations'] ?? '' }}</textarea>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-[#e5e7eb] bg-white p-4">
            <h4 class="mb-3 text-sm font-extrabold text-[#3996d3]">2. Responsable / affectation</h4>
            <div class="form-grid">
                <div class="md:col-span-2">
                    <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <label class="mb-0">RMO / agent assigné</label>
                        <button class="btn btn-secondary" type="button" data-add-rmo>+ Ajouter un autre RMO</button>
                    </div>
                    <div class="space-y-2" data-rmo-list>
                        @foreach ($selectedRmos as $rmoIndex => $rmoId)
                            <div class="flex gap-2" data-rmo-row>
                                <select name="actions[{{ $index }}][rmo_ids][]" required>
                                    <option value="">Sélectionner un RMO</option>
                                    @foreach ($responsableOptions as $responsable)
                                        <option value="{{ $responsable->id }}" data-direction-id="{{ $responsable->direction_id }}" data-service-id="{{ $responsable->service_id }}" @selected((int) $rmoId === (int) $responsable->id)>
                                            {{ $responsable->name }}
                                            @if (! empty($responsable->agent_matricule)) - [{{ $responsable->agent_matricule }}] @endif
                                            @if (! empty($responsable->agent_fonction)) - {{ $responsable->agent_fonction }} @endif
                                            ({{ $responsable->roleLabel() }})
                                        </option>
                                    @endforeach
                                </select>
                                <button class="btn btn-outline {{ $rmoIndex === 0 ? 'hidden' : '' }}" type="button" data-remove-rmo>Retirer</button>
                            </div>
                        @endforeach
                    </div>
                    @error("actions.$index.rmo_ids") <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-[#e5e7eb] bg-white p-4">
            <h4 class="mb-3 text-sm font-extrabold text-[#3996d3]">3. Planification</h4>
            <div class="form-grid-compact">
                <div>
                    <label>Date de début</label>
                    <input name="actions[{{ $index }}][date_debut]" type="date" value="{{ $rowData['date_debut'] ?? '' }}" required>
                    @error("actions.$index.date_debut") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label>Date fin</label>
                    <input name="actions[{{ $index }}][date_fin]" type="date" value="{{ $rowData['date_fin'] ?? '' }}" data-date-fin-input>
                    @error("actions.$index.date_fin") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="hidden">
                    <label>Échéance</label>
                    <input class="hidden" type="date" value="{{ $rowData['date_fin'] ?? '' }}" readonly data-echeance-preview>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-[#e5e7eb] bg-white p-4">
            <h4 class="mb-3 text-sm font-extrabold text-[#3996d3]">4. Cible et seuil</h4>
            <div class="form-grid">
                <div>
                    <label>Type de cible</label>
                    <select name="actions[{{ $index }}][mode_evaluation]" data-mode-select>
                        <option value="quantitatif" @selected($modeEvaluation === 'quantitatif')>Cible quantitative</option>
                        <option value="sous_actions" @selected($modeEvaluation === 'sous_actions')>Cible par sous-action</option>
                    </select>
                </div>
                <div data-target-wrapper>
                    <label>Valeur cible</label>
                    <input name="actions[{{ $index }}][quantite_cible]" data-target-input type="number" step="0.0001" min="0" value="{{ $rowData['quantite_cible'] ?? '' }}">
                    @error("actions.$index.quantite_cible") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div data-target-wrapper>
                    <label>Unité de mesure</label>
                    <input name="actions[{{ $index }}][unite_cible]" data-unit-input type="text" value="{{ $rowData['unite_cible'] ?? '' }}">
                    @error("actions.$index.unite_cible") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label>Mode de seuil</label>
                    <select name="actions[{{ $index }}][seuil_mode]" data-threshold-mode>
                        <option value="unique" @selected($thresholdMode === 'unique')>Seuil unique</option>
                        <option value="trimestriel" @selected($thresholdMode === 'trimestriel')>Seuil par trimestre</option>
                    </select>
                </div>
                <div data-threshold-unique>
                    <label>Seuil minimum attendu (%)</label>
                    <input name="actions[{{ $index }}][seuil_minimum]" type="number" step="0.01" min="0" max="100" value="{{ $rowData['seuil_minimum'] ?? 80 }}">
                    @error("actions.$index.seuil_minimum") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2 {{ $thresholdMode === 'trimestriel' ? '' : 'hidden' }}" data-threshold-quarterly>
                    <label>Seuils trimestriels (%)</label>
                    <div class="form-grid-compact">
                        <input name="actions[{{ $index }}][seuil_t1]" type="number" step="0.01" min="0" max="100" value="{{ $rowData['seuil_t1'] ?? '' }}" placeholder="T1">
                        <input name="actions[{{ $index }}][seuil_t2]" type="number" step="0.01" min="0" max="100" value="{{ $rowData['seuil_t2'] ?? '' }}" placeholder="T2">
                        <input name="actions[{{ $index }}][seuil_t3]" type="number" step="0.01" min="0" max="100" value="{{ $rowData['seuil_t3'] ?? '' }}" placeholder="T3">
                        <input name="actions[{{ $index }}][seuil_t4]" type="number" step="0.01" min="0" max="100" value="{{ $rowData['seuil_t4'] ?? '' }}" placeholder="T4">
                    </div>
                </div>
                <label class="checkbox-pill self-end">
                    <input type="hidden" name="actions[{{ $index }}][justificatif_obligatoire]" value="0">
                    <input type="checkbox" name="actions[{{ $index }}][justificatif_obligatoire]" value="1" @checked($justificatifObligatoire)>
                    Justificatif obligatoire
                </label>
            </div>
        </section>

        <section class="rounded-lg border border-[#e5e7eb] bg-white p-4 {{ $showSubActionForm ? '' : 'hidden' }}" data-sub-actions-section>
            <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <h4 class="text-sm font-extrabold text-[#3996d3]">5. Sous-actions prévues</h4>
                <button class="btn btn-secondary" type="button" data-add-sub-action>+ Ajouter une sous-action</button>
            </div>
            <div class="space-y-3" data-sub-actions-list>
                @foreach ($subActionRows as $subIndex => $subAction)
                    <div class="rounded-lg border border-[#e5e7eb] bg-[#f8fbfe] p-3" data-sub-action-row>
                        <input type="hidden" name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][id]" value="{{ $subAction['id'] ?? '' }}">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <strong class="text-xs uppercase text-[#1c203d]">Sous-action</strong>
                            <button class="btn btn-outline text-xs {{ $subIndex === 0 ? 'hidden' : '' }}" type="button" data-remove-sub-action>Retirer</button>
                        </div>
                        <div class="form-grid">
                            <div class="md:col-span-2">
                                <label>Libellé</label>
                                <input name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][libelle]" type="text" value="{{ $subAction['libelle'] ?? '' }}">
                            </div>
                            <div>
                                <label>Date de début</label>
                                <input name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][date_debut]" type="date" value="{{ $subAction['date_debut'] ?? ($rowData['date_debut'] ?? '') }}">
                            </div>
                            <div>
                                <label>Date de fin</label>
                                <input name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][date_fin]" type="date" value="{{ $subAction['date_fin'] ?? ($rowData['date_fin'] ?? '') }}">
                            </div>
                            <div>
                                <label>Cible prévue</label>
                                <input name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][cible_prevue]" type="number" step="0.0001" min="0" value="{{ $subAction['cible_prevue'] ?? '' }}">
                            </div>
                            <div>
                                <label>Unité</label>
                                <input name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][unite]" type="text" value="{{ $subAction['unite'] ?? ($rowData['unite_cible'] ?? '') }}">
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-lg border border-[#e5e7eb] bg-white p-4">
            <h4 class="mb-3 text-sm font-extrabold text-[#3996d3]">6. Ressources nécessaires</h4>
            <div class="form-grid-compact">
                @foreach ($resourceOptions as $resourceCode => $resourceLabel)
                    <label class="checkbox-pill">
                        <input
                            type="checkbox"
                            name="actions[{{ $index }}][ressources_necessaires][]"
                            value="{{ $resourceCode }}"
                            data-resource-checkbox
                            @if ($resourceCode === 'autres_ressources') data-other-resource @endif
                            @checked(in_array($resourceCode, $selectedResources, true))
                        >
                        {{ $resourceLabel }}
                    </label>
                @endforeach
            </div>
            <div class="mt-3" data-resource-details>
                <label>Précisions sur les ressources nécessaires</label>
                <textarea name="actions[{{ $index }}][ressources_details]">{{ $rowData['ressources_details'] ?? '' }}</textarea>
            </div>
        </section>

        <section class="rounded-lg border border-[#e5e7eb] bg-white p-4">
            <h4 class="mb-3 text-sm font-extrabold text-[#3996d3]">7. Financement</h4>
            <div class="form-grid">
                <div>
                    <label>Besoin de financement</label>
                    <select name="actions[{{ $index }}][financement_requis]" required data-financing-select>
                        <option value="0" @selected(! $financementRequis)>Non</option>
                        <option value="1" @selected($financementRequis)>Oui</option>
                    </select>
                </div>
            </div>
            <div class="mt-3 form-grid {{ $financementRequis ? '' : 'hidden' }}" data-financing-fields>
                <div>
                    <label>Montant</label>
                    <input name="actions[{{ $index }}][montant_estime]" type="number" step="0.01" min="0" value="{{ $rowData['montant_estime'] ?? '' }}">
                    @error("actions.$index.montant_estime") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label>Pièce justificative</label>
                    <input name="actions[{{ $index }}][justificatif_financement]" type="file" accept="{{ app(\App\Services\DocumentPolicySettings::class)->acceptAttribute() }}">
                    @error("actions.$index.justificatif_financement") <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

    </div>
</div>
