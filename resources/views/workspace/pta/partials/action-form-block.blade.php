@php
    $isTemplate = (bool) ($isTemplate ?? false);
    $index = $index ?? 0;
    $number = $isTemplate ? '__NUMBER__' : ((int) $index + 1);
    $rowData = is_array($rowData ?? null) ? $rowData : [];
    $actionHeadingLabel = trim((string) ($rowData['libelle'] ?? ''));
    $actionHeadingLabel = $actionHeadingLabel !== '' ? $actionHeadingLabel : 'Nouvelle action';
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
    $modeEvaluation = $rowData['mode_evaluation'] ?? \App\Models\Action::MODE_SANS_QUANTITE;
    if ($modeEvaluation === \App\Models\Action::MODE_MIXTE) {
        $modeEvaluation = \App\Models\Action::MODE_QUANTITATIF;
    }
    if (! array_key_exists($modeEvaluation, \App\Models\Action::evaluationModeOptions())) {
        $modeEvaluation = \App\Models\Action::MODE_SANS_QUANTITE;
    }

    // ── Workflow V2 : type_action pilote le formulaire (cf. docs/WORKFLOW-SUIVI-V2.md).
    // Si rowData fournit déjà type_action on l'utilise, sinon on le dérive du mode_evaluation.
    $typeActionMap = [
        \App\Models\Action::MODE_QUANTITATIF => \App\Models\Action::TYPE_QUANTITATIVE,
        \App\Models\Action::MODE_SANS_QUANTITE => \App\Models\Action::TYPE_NON_QUANTITATIVE,
        \App\Models\Action::MODE_SOUS_ACTIONS => \App\Models\Action::TYPE_COMPOSEE,
    ];
    $typeAction = $rowData['type_action'] ?? ($typeActionMap[$modeEvaluation] ?? \App\Models\Action::TYPE_NON_QUANTITATIVE);
    if (! array_key_exists($typeAction, \App\Models\Action::typeActionOptions())) {
        $typeAction = \App\Models\Action::TYPE_NON_QUANTITATIVE;
    }
    $requiresComment = filter_var($rowData['requires_comment'] ?? false, FILTER_VALIDATE_BOOL);
    $allowsDifficulty = filter_var($rowData['allows_difficulty'] ?? true, FILTER_VALIDATE_BOOL);

    $showTargetFields = $typeAction === \App\Models\Action::TYPE_QUANTITATIVE;
    $thresholdMode = in_array(($rowData['seuil_mode'] ?? 'unique'), ['unique', 'trimestriel'], true)
        ? (string) $rowData['seuil_mode']
        : 'unique';
    $subActionRows = collect($rowData['sous_actions'] ?? [])
        ->filter(fn ($subAction): bool => is_array($subAction))
        ->values();
    if ($subActionRows->isEmpty()) {
        $subActionRows = collect([[]]);
    }
    $showSubActionForm = $typeAction === \App\Models\Action::TYPE_COMPOSEE;
    $errorBag = $errors->getBag('default');
    $errorKeys = collect($errorBag->keys());
    $hasActionErrors = ! $isTemplate && $errorKeys->contains(fn (string $key): bool => str_starts_with($key, "actions.$index."));
    $hasAssignmentErrors = ! $isTemplate && $errors->has("actions.$index.rmo_ids");
    $hasPlanningErrors = ! $isTemplate && ($errors->has("actions.$index.date_debut") || $errors->has("actions.$index.date_fin"));
    $hasTargetErrors = ! $isTemplate && (
        $errors->has("actions.$index.quantite_cible")
        || $errors->has("actions.$index.unite_cible")
        || $errors->has("actions.$index.seuil_minimum")
    );
    $hasFinancingErrors = ! $isTemplate && (
        $errors->has("actions.$index.montant_estime")
        || $errors->has("actions.$index.nature_financement")
        || $errors->has("actions.$index.justificatif_financement")
    );
@endphp

@php
    // Action existante (deja persistee) → accordeon ferme par defaut : on voit
    // uniquement le nom et les 3 boutons. L'utilisateur clique pour derouler.
    // Action nouvelle (creation, template, ou block sans id) → accordeon ouvert.
    $hasPersistedId = ! $isTemplate && ! empty($rowData['id']);
    $blockHasErrors = $hasActionErrors || $hasAssignmentErrors || $hasPlanningErrors || $hasTargetErrors || $hasFinancingErrors;
    $accordionOpen = ! $hasPersistedId || $blockHasErrors;

    // Etat de "gel apres enregistrement" (regle metier 2026-05-29) :
    // une action parametree ET non deverrouillee se fige en lecture seule
    // jusqu'a ce que le DG approuve une demande de modification.
    $viewer = auth()->user();
    $viewerCanBypassFreeze = $viewer && ($viewer->isSuperAdmin() || $viewer->hasRole(\App\Models\User::ROLE_DG) || $viewer->hasPermission('planning.write.global'));
    $isParametre = ($rowData['statut_parametrage'] ?? null) === 'parametre';
    $hasOpenUnlock = ! empty($rowData['modification_unlocked_at']) && (empty($rowData['modification_unlock_expires_at']) || strtotime($rowData['modification_unlock_expires_at']) > time());
    $isFrozen = $hasPersistedId && $isParametre && ! empty($rowData['modification_locked_at']) && ! $hasOpenUnlock && ! $viewerCanBypassFreeze;
@endphp
<details @if ($hasPersistedId) id="action-{{ $rowData['id'] }}" @endif class="pta-action-block rounded-lg border border-[#d8ecf8] bg-white shadow-sm @if ($isFrozen) is-frozen @endif" data-action-block data-action-index="{{ $index }}" data-action-id="{{ $rowData['id'] ?? '' }}" data-action-frozen="{{ $isFrozen ? '1' : '0' }}" @if ($accordionOpen) open @endif>
    <summary class="pta-action-heading flex list-none items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50 transition-colors">
        <span class="min-w-0">
            <span class="flex min-w-0 items-baseline gap-1 text-sm font-extrabold tracking-wide text-[#1c203d]" data-action-title>
                <span class="shrink-0 uppercase" data-action-number>Action {{ $number }}</span>
                <span class="shrink-0 text-slate-400" aria-hidden="true">-</span>
                <span class="min-w-0 truncate normal-case text-[#3996d3]" data-action-title-label>{{ $actionHeadingLabel }}</span>
                @if ($isFrozen)
                    <span class="ml-2 anbg-badge anbg-badge-info px-2 py-0.5 text-[10px]" title="Action enregistree et figee. Demandez une modification au DG pour la modifier.">🔒 Enregistree</span>
                @endif
            </span>
            <span class="block truncate text-xs font-semibold text-slate-500" data-action-summary>{{ $actionHeadingLabel }}</span>
        </span>
        <span class="flex shrink-0 items-center gap-2">
            {{-- Boutons par action : Enregistrer / Modifier / Supprimer. --}}
            {{-- onclick stopPropagation pour ne pas declencher le toggle de l'accordeon. --}}
            @if ($isFrozen)
                {{-- Action figee : remplacer Enregistrer par Demande de modification (workflow DG/Planification). --}}
                <button class="btn btn-warning btn-sm" type="button" data-request-modification onclick="event.preventDefault(); event.stopPropagation();" title="Demander au DG l'autorisation de modifier cette action">Demande de modification</button>
            @else
                <button class="btn btn-success btn-sm" type="button" data-save-action onclick="event.preventDefault(); event.stopPropagation();" title="Enregistrer cette action seule">Enregistrer</button>
            @endif
            <button class="btn btn-warning btn-sm @if ($accordionOpen) hidden @endif" type="button" data-edit-action onclick="event.preventDefault(); event.stopPropagation();" title="Ouvrir l'action pour modification">Modifier</button>
            <button class="btn btn-danger btn-sm {{ !$isTemplate && (int) $index === 0 && ! $hasPersistedId ? 'hidden' : '' }}" type="button" data-remove-action onclick="event.preventDefault(); event.stopPropagation();" title="Supprimer cette action">Supprimer</button>
            <svg class="app-collapsible-chevron h-4 w-4 text-[#3996d3] transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </span>
    </summary>
<div class="pta-action-body border-t border-[#d8ecf8] p-4">

    {{-- Hidden input id : reste actif (hors fieldset disabled) pour etre soumis. --}}
    <input type="hidden" name="actions[{{ $index }}][id]" value="{{ $rowData['id'] ?? '' }}">

    @if ($isFrozen)
        <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-amber-800">
            <strong>Action enregistree et figee.</strong> Les champs sont en lecture seule. Pour les modifier, utilisez le bouton <em>Demande de modification</em> ci-dessus — la demande sera transmise au DG (et au service Planification).
        </div>
    @endif

    <fieldset @if ($isFrozen) disabled class="pta-action-fieldset-frozen opacity-70" @endif>
    <div class="space-y-4">
        <details class="form-step-accordion" open>
            <summary>1. Identification de l'action</summary>
            <div class="form-step-body">
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
            </div>
        </details>

        <details class="form-step-accordion" open>
            <summary>2. Responsable / affectation</summary>
            <div class="form-step-body">
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
            </div>
        </details>

        <details class="form-step-accordion" open>
            <summary>3. Planification</summary>
            <div class="form-step-body">
            <div class="form-grid-compact">
                <div>
                    <label>Date de début</label>
                    <input name="actions[{{ $index }}][date_debut]" type="date" value="{{ $rowData['date_debut'] ?? '' }}" required>
                    @error("actions.$index.date_debut") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label>Date de fin</label>
                    <input name="actions[{{ $index }}][date_fin]" type="date" value="{{ $rowData['date_fin'] ?? '' }}" data-date-fin-input>
                    @error("actions.$index.date_fin") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="hidden">
                    <label>Échéance</label>
                    <input class="hidden" type="date" value="{{ $rowData['date_fin'] ?? '' }}" readonly data-echeance-preview>
                </div>
            </div>
            </div>
        </details>

        <details class="form-step-accordion" open>
            <summary>4. Cible et seuil</summary>
            <div class="form-step-body">
            <div class="form-grid">
                <div>
                    <label>Type d'action</label>
                    <select name="actions[{{ $index }}][type_action]" data-type-action-select data-mode-select>
                        <option value="quantitative" @selected($typeAction === 'quantitative')>Action simple quantitative</option>
                        <option value="non_quantitative" @selected($typeAction === 'non_quantitative')>Action simple non quantitative</option>
                        <option value="composee" @selected($typeAction === 'composee')>Action composée (sous-actions)</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500" data-type-action-hint>
                        @switch($typeAction)
                            @case('quantitative') Cible chiffrée + unité + seuils numériques. @break
                            @case('composee') Performance calculée depuis les sous-actions (poids Σ=100%). @break
                            @default Pièce justificative attendue (réalisé = 0 % ou 100 %).
                        @endswitch
                    </p>
                </div>
                <div class="{{ $showTargetFields ? '' : 'hidden' }}" data-target-wrapper>
                    <label>Valeur cible</label>
                    <input name="actions[{{ $index }}][quantite_cible]" data-target-input type="number" step="1" min="0" value="{{ isset($rowData['quantite_cible']) && $rowData['quantite_cible'] !== '' && $rowData['quantite_cible'] !== null ? (int) $rowData['quantite_cible'] : '' }}" @disabled(! $showTargetFields)>
                    @error("actions.$index.quantite_cible") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="{{ $showTargetFields ? '' : 'hidden' }}" data-target-wrapper>
                    <label>Unité de mesure</label>
                    <input name="actions[{{ $index }}][unite_cible]" data-unit-input type="text" value="{{ $rowData['unite_cible'] ?? '' }}" @disabled(! $showTargetFields)>
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
                <label class="checkbox-pill self-end">
                    <input type="hidden" name="actions[{{ $index }}][requires_comment]" value="0">
                    <input type="checkbox" name="actions[{{ $index }}][requires_comment]" value="1" @checked($requiresComment)>
                    Commentaire obligatoire à la soumission
                </label>
                <label class="checkbox-pill self-end">
                    <input type="hidden" name="actions[{{ $index }}][allows_difficulty]" value="0">
                    <input type="checkbox" name="actions[{{ $index }}][allows_difficulty]" value="1" @checked($allowsDifficulty)>
                    Activer le champ « difficulté rencontrée »
                </label>
            </div>
            </div>
        </details>

        <details class="form-step-accordion {{ $showSubActionForm ? '' : 'hidden' }}" data-sub-actions-section open>
            <summary>5. Sous-actions prévues</summary>
            <div class="form-step-body">
            <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <h4 class="text-sm font-extrabold text-[#3996d3]">Sous-actions prévues</h4>
                <div class="flex items-center gap-3">
                    <span class="text-xs font-semibold" data-weight-counter>Σ poids : <strong data-weight-total>0</strong> %</span>
                    <button class="btn btn-secondary" type="button" data-add-sub-action>+ Ajouter une sous-action</button>
                </div>
            </div>
            <p class="mb-2 text-xs text-slate-500" data-weight-hint>La somme des poids des sous-actions doit être égale à 100 % (laissez vide pour une moyenne simple).</p>
            <div class="space-y-3" data-sub-actions-list>
                @foreach ($subActionRows as $subIndex => $subAction)
                    @php
                        $saType = $subAction['sub_action_type'] ?? ((filled($subAction['cible_prevue'] ?? null) && (float) ($subAction['cible_prevue'] ?? 0) > 0) ? 'quantitative' : 'non_quantitative');
                        $saRequiresProof = filter_var($subAction['requires_proof'] ?? true, FILTER_VALIDATE_BOOL);
                        $saRequiresComment = filter_var($subAction['requires_comment'] ?? false, FILTER_VALIDATE_BOOL);
                        $saAllowsDifficulty = filter_var($subAction['allows_difficulty'] ?? true, FILTER_VALIDATE_BOOL);
                    @endphp
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
                                <label>Type</label>
                                <select name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][sub_action_type]" data-sub-type-select>
                                    <option value="quantitative" @selected($saType === 'quantitative')>Quantitative</option>
                                    <option value="non_quantitative" @selected($saType === 'non_quantitative')>Non quantitative</option>
                                </select>
                            </div>
                            <div>
                                <label>RMO en charge</label>
                                <select name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][agent_id]" data-sub-action-agent-select>
                                    <option value="">RMO principal</option>
                                    @foreach ($responsableOptions as $responsable)
                                        <option value="{{ $responsable->id }}" @selected((int) ($subAction['agent_id'] ?? 0) === (int) $responsable->id)>{{ $responsable->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label>Date de début</label>
                                <input name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][date_debut]" type="date" value="{{ $subAction['date_debut'] ?? ($rowData['date_debut'] ?? '') }}">
                            </div>
                            <div>
                                <label>Date de fin</label>
                                <input name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][date_fin]" type="date" value="{{ $subAction['date_fin'] ?? ($rowData['date_fin'] ?? '') }}">
                            </div>
                            <div data-sub-target-wrapper class="{{ $saType === 'quantitative' ? '' : 'hidden' }}">
                                <label>Cible prévue</label>
                                <input name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][cible_prevue]" type="number" step="1" min="0" value="{{ isset($subAction['cible_prevue']) && $subAction['cible_prevue'] !== '' && $subAction['cible_prevue'] !== null ? (int) $subAction['cible_prevue'] : '' }}">
                            </div>
                            <div data-sub-target-wrapper class="{{ $saType === 'quantitative' ? '' : 'hidden' }}">
                                <label>Unité</label>
                                <input name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][unite]" type="text" value="{{ $subAction['unite'] ?? ($rowData['unite_cible'] ?? '') }}">
                            </div>
                            <div>
                                <label>Poids (%)</label>
                                <input name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][weight]" type="number" step="0.01" min="0" max="100" value="{{ $subAction['weight'] ?? '' }}" data-sub-weight-input placeholder="ex. 25">
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <label class="checkbox-pill text-xs">
                                <input type="hidden" name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][requires_proof]" value="0">
                                <input type="checkbox" name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][requires_proof]" value="1" @checked($saRequiresProof)>
                                Justificatif obligatoire
                            </label>
                            <label class="checkbox-pill text-xs">
                                <input type="hidden" name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][requires_comment]" value="0">
                                <input type="checkbox" name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][requires_comment]" value="1" @checked($saRequiresComment)>
                                Commentaire obligatoire
                            </label>
                            <label class="checkbox-pill text-xs">
                                <input type="hidden" name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][allows_difficulty]" value="0">
                                <input type="checkbox" name="actions[{{ $index }}][sous_actions][{{ $subIndex }}][allows_difficulty]" value="1" @checked($saAllowsDifficulty)>
                                Champ difficulté
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
            </div>
        </details>

        <details class="form-step-accordion" open>
            <summary>6. Ressources nécessaires</summary>
            <div class="form-step-body">
            <div class="form-grid-compact pta-resource-grid">
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
            </div>
        </details>

        <details class="form-step-accordion" open>
            <summary>7. Risques</summary>
            <div class="form-step-body">
            <div class="form-grid">
                <div class="md:col-span-2">
                    <label>Risque potentiel</label>
                    <textarea name="actions[{{ $index }}][risque_potentiel]">{{ $rowData['risque_potentiel'] ?? '' }}</textarea>
                    @error("actions.$index.risque_potentiel") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label>Niveau de risque</label>
                    @php $riskLevel = (string) ($rowData['niveau_risque'] ?? ''); @endphp
                    <select name="actions[{{ $index }}][niveau_risque]">
                        <option value="" @selected($riskLevel === '')>Non renseigné</option>
                        <option value="faible" @selected($riskLevel === 'faible')>Faible</option>
                        <option value="modere" @selected($riskLevel === 'modere')>Modere</option>
                        <option value="eleve" @selected($riskLevel === 'eleve')>Eleve</option>
                        <option value="critique" @selected($riskLevel === 'critique')>Critique</option>
                    </select>
                    @error("actions.$index.niveau_risque") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label>Mesures préventives</label>
                    <textarea name="actions[{{ $index }}][mesures_preventives]">{{ $rowData['mesures_preventives'] ?? '' }}</textarea>
                    @error("actions.$index.mesures_preventives") <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            </div>
        </details>

        <details class="form-step-accordion" open>
            <summary>8. Financement</summary>
            <div class="form-step-body">
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
                    <label>Nature du financement</label>
                    <input name="actions[{{ $index }}][nature_financement]" type="text" value="{{ $rowData['nature_financement'] ?? '' }}" placeholder="Ex. fonctionnement, investissement, mission">
                    @error("actions.$index.nature_financement") <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label>Pièce justificative</label>
                    <input name="actions[{{ $index }}][justificatif_financement]" type="file" accept="{{ app(\App\Services\DocumentPolicySettings::class)->acceptAttribute() }}" @if($financementRequis) required @endif>
                    @error("actions.$index.justificatif_financement") <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            </div>
        </details>

    </div>
    </fieldset>
</div>
</details>
