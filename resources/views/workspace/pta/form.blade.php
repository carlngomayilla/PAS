@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $objectifOptions = collect($objectifOperationnelOptions ?? []);
        $responsableOptions = collect($responsableOptions ?? []);
        $selectedObjectif = $objectifOptions->firstWhere('id', (int) old('objectif_operationnel_id', $row->objectif_operationnel_id));
        $selectedPao = $selectedObjectif?->pao;
        $serviceCodeForTitle = $selectedObjectif?->service?->code ?: $selectedObjectif?->service?->libelle ?: 'SERVICE';
        $defaultTitle = old('titre', $row->titre ?: 'PTA - '.$serviceCodeForTitle);
        $selectedDirectionLabel = $selectedObjectif?->direction?->code ? $selectedObjectif->direction->code.' - '.$selectedObjectif->direction->libelle : '';
        $selectedServiceLabel = $selectedObjectif?->service?->code ? $selectedObjectif->service->code.' - '.$selectedObjectif->service->libelle : '';
        $selectedPasLabel = $selectedObjectif?->pas?->titre ?? '';
        $selectedAxeLabel = $selectedObjectif?->pasAxe?->code ? $selectedObjectif->pasAxe->code.' - '.$selectedObjectif->pasAxe->libelle : '';
        $selectedObjectifStrategiqueLabel = $selectedObjectif?->pasObjectif?->code ? $selectedObjectif->pasObjectif->code.' - '.$selectedObjectif->pasObjectif->libelle : '';
        $selectedObjectifDescription = $selectedObjectif?->description ?: $selectedObjectif?->libelle;
        $oldActions = old('actions');
        if (is_array($oldActions)) {
            $actionRows = collect($oldActions)->values();
        } elseif ($isEdit && $row->relationLoaded('actions') && $row->actions->isNotEmpty()) {
            $actionRows = $row->actions->map(function ($action) {
                return [
                    'id' => $action->id,
                    'libelle' => $action->libelle,
                    'description' => $action->description,
                    'date_debut' => optional($action->date_debut)->format('Y-m-d'),
                    'date_fin' => optional($action->date_fin)->format('Y-m-d'),
                    'statut' => $action->statut ?: 'non_demarre',
                    'mode_evaluation' => $action->mode_evaluation ?: \App\Models\Action::MODE_SOUS_ACTIONS,
                    'priorite' => $action->priorite,
                    'montant_estime' => $action->montant_estime,
                    'nature_financement' => $action->nature_financement ?: $action->description_financement,
                    'source_financement' => $action->source_financement,
                    'commentaire_financement' => $action->commentaire_financement,
                    'financement_requis' => (bool) $action->financement_requis,
                    'intitule_cible' => $action->intitule_cible,
                    'quantite_cible' => $action->quantite_cible,
                    'unite_cible' => $action->unite_cible,
                    'seuil_minimum' => $action->seuil_minimum,
                    'seuil_mode' => $action->seuil_mode ?: 'unique',
                    'seuil_t1' => $action->seuil_t1,
                    'seuil_t2' => $action->seuil_t2,
                    'seuil_t3' => $action->seuil_t3,
                    'seuil_t4' => $action->seuil_t4,
                    'justificatif_obligatoire' => (bool) $action->justificatif_obligatoire,
                    'sous_actions' => $action->relationLoaded('sousActions')
                        ? $action->sousActions->map(fn ($sousAction): array => [
                            'id' => $sousAction->id,
                            'libelle' => $sousAction->libelle,
                            'description' => $sousAction->description,
                            'resultat_attendu' => $sousAction->resultat_attendu,
                            'date_debut' => optional($sousAction->date_debut)->format('Y-m-d'),
                            'date_fin' => optional($sousAction->date_fin)->format('Y-m-d'),
                            'cible_prevue' => $sousAction->cible_prevue,
                            'unite' => $sousAction->unite,
                            'commentaire' => $sousAction->commentaire,
                        ])->values()->all()
                        : [],
                    'resultat_attendu' => $action->resultat_attendu,
                    'observations' => $action->observations,
                    'ressources_necessaires' => $action->ressources_necessaires ?: array_keys(array_filter([
                        'main_oeuvre' => (bool) $action->ressource_main_oeuvre,
                        'ressources_materielles' => (bool) $action->ressource_equipement,
                        'ressources_techniques' => (bool) $action->ressource_partenariat,
                        'autres_ressources' => (bool) $action->ressource_autres,
                    ])),
                    'ressources_details' => $action->ressources_details ?: $action->ressource_autres_details,
                    'risque_potentiel' => $action->risque_potentiel,
                    'niveau_risque' => $action->niveau_risque,
                    'mesures_preventives' => $action->mesures_preventives,
                    'rmo_ids' => $action->relationLoaded('responsables')
                        ? $action->responsables->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                        : array_filter([(int) $action->responsable_id]),
                ];
            })->values();
        } else {
            $actionRows = collect([[
                'libelle' => '',
                'description' => '',
                'date_debut' => '',
                'date_fin' => '',
                'statut' => 'non_demarre',
                'mode_evaluation' => \App\Models\Action::MODE_SOUS_ACTIONS,
                'priorite' => 'normale',
                'montant_estime' => '',
                'nature_financement' => '',
                'source_financement' => '',
                'commentaire_financement' => '',
                'financement_requis' => false,
                'intitule_cible' => '',
                'quantite_cible' => '',
                'unite_cible' => '',
                'seuil_minimum' => 80,
                'seuil_mode' => 'unique',
                'seuil_t1' => '',
                'seuil_t2' => '',
                'seuil_t3' => '',
                'seuil_t4' => '',
                'justificatif_obligatoire' => false,
                'sous_actions' => [],
                'resultat_attendu' => '',
                'observations' => '',
                'ressources_necessaires' => [],
                'ressources_details' => '',
                'risque_potentiel' => '',
                'niveau_risque' => '',
                'mesures_preventives' => '',
                'rmo_ids' => [],
            ]]);
        }
    @endphp

    <div class="app-screen-flow">
        <section class="showcase-hero mb-4 app-screen-block">
            <div class="showcase-hero-body">
                <div>
                    <span class="showcase-eyebrow">PTA</span>
                    <h1 class="showcase-title">{{ $isEdit ? 'Modifier le PTA du service' : 'Enregistrer le PTA du service' }}</h1>
                </div>
                <div class="showcase-action-row">
                    <a class="btn btn-blue" href="{{ route('workspace.pta.index') }}">Retour liste</a>
                </div>
            </div>
        </section>
<section class="showcase-panel mb-4 app-screen-block">
            <form method="POST" enctype="multipart/form-data" class="form-shell" action="{{ $isEdit ? route('workspace.pta.update', $row) : route('workspace.pta.store') }}">
                @csrf
                @if ($isEdit)
                    @method('PUT')
                @endif

                <input id="titre" name="titre" type="hidden" value="{{ $defaultTitle }}">

                <div class="form-section">
                    <h2 class="form-section-title">Périmètre</h2>
                    <div class="form-grid">
                        <div class="md:col-span-2">
                            <label for="objectif_operationnel_id">Objectif opérationnel transmis au service</label>
                            <select id="objectif_operationnel_id" name="objectif_operationnel_id" required>
                                <option value="">Sélectionner</option>
                                @foreach ($objectifOptions as $objectif)
                                    @php
                                        $pao = $objectif->pao;
                                        $generatedPtaTitle = 'PTA - '.($objectif->service?->code ?: $objectif->service?->libelle ?: 'SERVICE');
                                    @endphp
                                    <option
                                        value="{{ $objectif->id }}"
                                        data-direction-label="{{ $objectif->direction?->code }} - {{ $objectif->direction?->libelle }}"
                                        data-direction-id="{{ $objectif->direction_id }}"
                                        data-service-label="{{ $objectif->service?->code }} - {{ $objectif->service?->libelle }}"
                                        data-service-id="{{ $objectif->service_id }}"
                                        data-pas-label="{{ $objectif->pas?->titre }}"
                                        data-axis-label="{{ $objectif->pasAxe?->code }} - {{ $objectif->pasAxe?->libelle }}"
                                        data-pao-title="{{ $pao?->titre }}"
                                        data-pta-title="{{ $generatedPtaTitle }}"
                                        data-strategic-objective-label="{{ $objectif->pasObjectif?->code }} - {{ $objectif->pasObjectif?->libelle }}"
                                        data-operational-description="{{ $objectif->description ?: $objectif->libelle }}"
                                        data-echeance="{{ optional($objectif->echeance)->format('Y-m-d') }}"
                                        @selected((int) old('objectif_operationnel_id', $row->objectif_operationnel_id) === (int) $objectif->id)
                                    >
                                        #{{ $objectif->id }} - {{ $objectif->libelle }} ({{ $objectif->direction?->code }} / {{ $objectif->service?->code }})
                                    </option>
                                @endforeach
                            </select>
                            @error('objectif_operationnel_id') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="pta_title_label">Titre PTA généré</label>
                            <input id="pta_title_label" type="text" value="{{ $defaultTitle }}" readonly>
                        </div>
                        <div>
                            <label for="pao_origin_label">PAO d'origine</label>
                            <input id="pao_origin_label" type="text" value="{{ $selectedPao?->titre ?? '' }}" readonly>
                        </div>
                        <div>
                            <label for="pas_label">PAS lié</label>
                            <input id="pas_label" type="text" value="{{ $selectedPasLabel }}" readonly>
                        </div>
                        <div>
                            <label for="axe_label">Axe stratégique</label>
                            <input id="axe_label" type="text" value="{{ $selectedAxeLabel }}" readonly>
                        </div>
                        <div>
                            <label for="pas_objectif_label">Objectif stratégique</label>
                            <input id="pas_objectif_label" type="text" value="{{ $selectedObjectifStrategiqueLabel }}" readonly>
                        </div>
                        <div>
                            <label for="direction_label">Direction</label>
                            <input id="direction_label" type="text" value="{{ $selectedDirectionLabel }}" readonly>
                        </div>
                        <div>
                            <label for="service_label">Service</label>
                            <input id="service_label" type="text" value="{{ $selectedServiceLabel }}" readonly>
                        </div>
                        <div>
                            <label for="objectif_echeance_label">Échéance</label>
                            <input id="objectif_echeance_label" type="text" value="{{ optional($selectedObjectif?->echeance)->format('Y-m-d') }}" readonly>
                        </div>
                        <div class="hidden">
                            <label for="statut" class="hidden">Statut</label>
                            <select id="statut" class="hidden" disabled>
                                @foreach ($statusOptions as $status)
                                    <option value="{{ $status }}" @selected(old('statut', $row->statut ?: 'brouillon') === $status)>{{ $workflowStatusLabel($status) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="hidden md:col-span-2">
                            <label for="objectif_operationnel_description">Description de l'objectif opérationnel sélectionné</label>
                            <textarea id="objectif_operationnel_description" class="hidden" rows="4" readonly>{{ old('objectif_operationnel_description', $selectedObjectifDescription) }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="form-section-title mb-0">Actions liées à l'objectif opérationnel</h2>
                        </div>
                        <button id="add-action-button" class="btn btn-blue" type="button">+ Ajouter une autre action</button>
                    </div>

                    @error('actions') <p class="field-error">{{ $message }}</p> @enderror

                    <div id="pta-actions-list" class="space-y-4">
                        @foreach ($actionRows as $index => $actionRow)
                            @php
                                $rowData = is_array($actionRow) ? $actionRow : [];
                                $selectedRmos = collect($rowData['rmo_ids'] ?? [])
                                    ->filter(fn ($id) => is_numeric($id))
                                    ->map(fn ($id) => (int) $id)
                                    ->filter()
                                    ->values();
                                if ($selectedRmos->isEmpty()) {
                                    $selectedRmos = collect([null]);
                                }
                            @endphp
                            @include('workspace.pta.partials.action-form-block', [
                                'index' => $index,
                                'rowData' => $rowData,
                                'selectedRmos' => $selectedRmos,
                                'responsableOptions' => $responsableOptions,
                            ])
                        @endforeach
                    </div>

                    @if ($responsableOptions->isEmpty())
                        <p class="field-hint mt-3 text-[#f9b13c]">Aucun utilisateur actif disponible pour votre périmètre.</p>
                    @endif
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Mettre à jour le PTA' : 'Enregistrer le PTA' }}</button>
                    <a class="btn btn-secondary" href="{{ route('workspace.pta.index') }}">Retour</a>
                </div>
            </form>
        </section>

        @if ($isEdit)
            <section class="showcase-panel mb-4 app-screen-block">
                <h2 class="showcase-panel-title">Timeline validation</h2>
                <div class="overflow-auto">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Action</th>
                                <th>Transition statut</th>
                                <th>Motif retour</th>
                                <th>Par</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($timeline as $item)
                                <tr>
                                    <td>{{ $item['date'] ?? '-' }}</td>
                                    <td><span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-xs">{{ $item['action'] }}</span></td>
                                    <td>
                                        @if (!empty($item['from']) || !empty($item['to']))
                                            {{ !empty($item['from']) ? $workflowStatusLabel((string) $item['from']) : '-' }} -> {{ !empty($item['to']) ? $workflowStatusLabel((string) $item['to']) : '-' }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>{{ $item['reason'] ?? '-' }}</td>
                                    <td>{{ $item['user'] }} ({{ $item['user_role'] }})</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-slate-500">Aucune transition enregistree.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>

    <template id="pta-action-template">
        @include('workspace.pta.partials.action-form-block', [
            'index' => '__INDEX__',
            'rowData' => [
                'libelle' => '',
                'description' => '',
                'date_debut' => '',
                'date_fin' => '',
                'statut' => 'non_demarre',
                'mode_evaluation' => \App\Models\Action::MODE_SOUS_ACTIONS,
                'priorite' => 'normale',
                'montant_estime' => '',
                'nature_financement' => '',
                'source_financement' => '',
                'commentaire_financement' => '',
                'financement_requis' => false,
                'intitule_cible' => '',
                'quantite_cible' => '',
                'unite_cible' => '',
                'seuil_minimum' => 80,
                'seuil_mode' => 'unique',
                'seuil_t1' => '',
                'seuil_t2' => '',
                'seuil_t3' => '',
                'seuil_t4' => '',
                'justificatif_obligatoire' => false,
                'sous_actions' => [],
                'resultat_attendu' => '',
                'observations' => '',
                'ressources_necessaires' => [],
                'ressources_details' => '',
                'risque_potentiel' => '',
                'niveau_risque' => '',
                'mesures_preventives' => '',
            ],
            'selectedRmos' => collect([null]),
            'responsableOptions' => $responsableOptions,
            'isTemplate' => true,
        ])
    </template>

    <template id="pta-rmo-template">
        <div class="flex gap-2" data-rmo-row>
            <select name="actions[__ACTION_INDEX__][rmo_ids][]" required>
                <option value="">Sélectionner un RMO</option>
                @foreach ($responsableOptions as $responsable)
                    <option value="{{ $responsable->id }}" data-direction-id="{{ $responsable->direction_id }}" data-service-id="{{ $responsable->service_id }}">
                        {{ $responsable->name }}
                        @if (!empty($responsable->agent_matricule)) - [{{ $responsable->agent_matricule }}] @endif
                        @if (!empty($responsable->agent_fonction)) - {{ $responsable->agent_fonction }} @endif
                        ({{ $responsable->roleLabel() }})
                    </option>
                @endforeach
            </select>
            <button class="btn btn-outline" type="button" data-remove-rmo>Retirer</button>
        </div>
    </template>
@endsection

@push('scripts')
    <script @cspNonce>
        (function () {
            var objectifSelect = document.getElementById('objectif_operationnel_id');
            var directionLabel = document.getElementById('direction_label');
            var serviceLabel = document.getElementById('service_label');
            var pasLabel = document.getElementById('pas_label');
            var axeLabel = document.getElementById('axe_label');
            var paoLabel = document.getElementById('pao_origin_label');
            var titleInput = document.getElementById('titre');
            var titleLabel = document.getElementById('pta_title_label');
            var strategicObjectiveLabel = document.getElementById('pas_objectif_label');
            var operationalObjectiveDescription = document.getElementById('objectif_operationnel_description');
            var echeanceLabel = document.getElementById('objectif_echeance_label');
            var actionsList = document.getElementById('pta-actions-list');
            var actionTemplate = document.getElementById('pta-action-template');
            var rmoTemplate = document.getElementById('pta-rmo-template');
            var actionCount = document.getElementById('pta-action-count');
            var addActionButton = document.getElementById('add-action-button');

            function optionValue(option, key) {
                return option ? (option.getAttribute(key) || '').trim() : '';
            }

            function syncScope() {
                var option = objectifSelect ? objectifSelect.options[objectifSelect.selectedIndex] : null;
                var ptaTitle = optionValue(option, 'data-pta-title') || 'PTA - SERVICE';

                if (directionLabel) directionLabel.value = optionValue(option, 'data-direction-label');
                if (serviceLabel) serviceLabel.value = optionValue(option, 'data-service-label');
                if (pasLabel) pasLabel.value = optionValue(option, 'data-pas-label');
                if (axeLabel) axeLabel.value = optionValue(option, 'data-axis-label');
                if (paoLabel) paoLabel.value = optionValue(option, 'data-pao-title');
                if (titleInput) titleInput.value = ptaTitle;
                if (titleLabel) titleLabel.value = ptaTitle;
                if (strategicObjectiveLabel) strategicObjectiveLabel.value = optionValue(option, 'data-strategic-objective-label');
                if (echeanceLabel) echeanceLabel.value = optionValue(option, 'data-echeance');
                document.querySelectorAll('[data-action-block]').forEach(function (block) {
                    syncActionEcheance(block);
                });
                filterRmosForScope();
            }

            function filterRmosForScope() {
                var option = objectifSelect ? objectifSelect.options[objectifSelect.selectedIndex] : null;
                var directionId = optionValue(option, 'data-direction-id');
                var serviceId = optionValue(option, 'data-service-id');

                document.querySelectorAll('[data-rmo-list] select').forEach(function (select) {
                    var current = select.value;
                    Array.prototype.forEach.call(select.options, function (selectOption, index) {
                        if (index === 0) {
                            selectOption.hidden = false;
                            selectOption.disabled = false;
                            return;
                        }

                        var optionDirection = selectOption.getAttribute('data-direction-id') || '';
                        var optionService = selectOption.getAttribute('data-service-id') || '';
                        var visible = (!directionId || !optionDirection || optionDirection === directionId)
                            && (!serviceId || !optionService || optionService === serviceId);
                        selectOption.hidden = !visible;
                        selectOption.disabled = !visible;
                        if (!visible && selectOption.value === current) {
                            select.value = '';
                        }
                    });
                });
            }

            function syncActionMode(block) {
                if (!block) return;

                var modeField = block.querySelector('[data-mode-select]');
                var mode = modeField ? modeField.value : 'sous_actions';
                var targetInput = block.querySelector('[data-target-input]');
                var targetValue = targetInput ? targetInput.value.trim() : '';
                var targetWrappers = block.querySelectorAll('[data-target-wrapper]');
                var subActionsSection = block.querySelector('[data-sub-actions-section]');
                var showSubActions = mode === 'sous_actions';

                if (mode === 'quantitatif' && targetInput && targetInput.dataset.forceSousActions === '1' && targetValue === '') {
                    mode = 'sous_actions';
                    modeField.value = mode;
                    targetInput.dataset.forceSousActions = '0';
                    showSubActions = true;
                }

                targetWrappers.forEach(function (wrapper) {
                    var input = wrapper.querySelector('input:not([type="hidden"]), select, textarea');
                    var active = mode === 'quantitatif';
                    wrapper.classList.toggle('hidden', !active);
                    if (input) {
                        input.required = active && !wrapper.hasAttribute('data-target-optional');
                    }
                });

                if (subActionsSection) {
                    subActionsSection.classList.toggle('hidden', !showSubActions);
                    subActionsSection.querySelectorAll('input, textarea, select, button').forEach(function (field) {
                        field.disabled = !showSubActions;
                    });
                    if (showSubActions) {
                        refreshSubActionIndexes(block);
                    }
                }
            }

            function syncThresholdMode(block) {
                if (!block) return;

                var modeField = block.querySelector('[data-threshold-mode]');
                var mode = modeField ? modeField.value : 'unique';
                var uniqueField = block.querySelector('[data-threshold-unique]');
                var quarterlyFields = block.querySelector('[data-threshold-quarterly]');

                if (uniqueField) {
                    uniqueField.classList.toggle('hidden', mode !== 'unique');
                }

                if (quarterlyFields) {
                    quarterlyFields.classList.toggle('hidden', mode !== 'trimestriel');
                }
            }

            function refreshSubActionIndexes(block) {
                if (!block) return;

                var actionIndex = block.getAttribute('data-action-index') || '0';
                var rows = block.querySelectorAll('[data-sub-action-row]');

                rows.forEach(function (row, rowIndex) {
                    row.querySelectorAll('[name]').forEach(function (field) {
                        field.name = field.name
                            .replace(/actions\[(?:\d+|__INDEX__|__ACTION_INDEX__)\]/, 'actions[' + actionIndex + ']')
                            .replace(/\[sous_actions\]\[(?:\d+|__SUB_INDEX__)\]/, '[sous_actions][' + rowIndex + ']');
                    });

                    var removeSubAction = row.querySelector('[data-remove-sub-action]');
                    if (removeSubAction) {
                        removeSubAction.classList.toggle('hidden', rowIndex === 0);
                    }
                });
            }

            function addSubAction(block) {
                if (!block) return;

                var list = block.querySelector('[data-sub-actions-list]');
                var firstRow = list ? list.querySelector('[data-sub-action-row]') : null;
                if (!list || !firstRow) return;

                var clone = firstRow.cloneNode(true);
                clone.querySelectorAll('input, textarea, select').forEach(function (field) {
                    if (field.type === 'hidden') {
                        field.value = '';
                        return;
                    }

                    if (field.type === 'checkbox' || field.type === 'radio') {
                        field.checked = false;
                        return;
                    }

                    field.value = '';
                });

                var actionStart = block.querySelector('input[name$="[date_debut]"]');
                var actionEnd = block.querySelector('input[name$="[date_fin]"]');
                var subStart = clone.querySelector('input[name$="[date_debut]"]');
                var subEnd = clone.querySelector('input[name$="[date_fin]"]');
                var unit = block.querySelector('[data-unit-input]');
                var subUnit = clone.querySelector('input[name$="[unite]"]');

                if (subStart && actionStart) subStart.value = actionStart.value;
                if (subEnd && actionEnd) subEnd.value = actionEnd.value;
                if (subUnit && unit) subUnit.value = unit.value;

                list.appendChild(clone);
                refreshSubActionIndexes(block);
            }

            function syncActionFinancing(block) {
                if (!block) return;

                var select = block.querySelector('[data-financing-select]');
                var fields = block.querySelector('[data-financing-fields]');
                var active = select && select.value === '1';

                if (fields) {
                    fields.classList.toggle('hidden', !active);
                    fields.querySelectorAll('input, textarea, select').forEach(function (field) {
                        field.disabled = !active;
                        if (field.name && (
                            field.name.indexOf('[montant_estime]') !== -1
                            || field.name.indexOf('[nature_financement]') !== -1
                            || field.name.indexOf('[justificatif_financement]') !== -1
                        )) {
                            field.required = active;
                        }
                        if (!active && field.type !== 'file') {
                            field.value = '';
                        }
                    });
                }
            }

            function syncActionEcheance(block) {
                if (!block) return;

                var dateFin = block.querySelector('input[name$="[date_fin]"]');
                var preview = block.querySelector('[data-echeance-preview]');
                var objectiveOption = objectifSelect ? objectifSelect.options[objectifSelect.selectedIndex] : null;
                var objectiveEcheance = optionValue(objectiveOption, 'data-echeance');

                if (dateFin) {
                    dateFin.max = objectiveEcheance || '';

                    if (objectiveEcheance && !dateFin.value) {
                        dateFin.value = objectiveEcheance;
                    }

                    if (objectiveEcheance && dateFin.value && dateFin.value > objectiveEcheance) {
                        dateFin.value = objectiveEcheance;
                    }
                }

                if (dateFin && preview) {
                    preview.value = dateFin.value;
                }
            }

            function refreshActionIndexes() {
                if (!actionsList) return;

                var blocks = actionsList.querySelectorAll('[data-action-block]');
                blocks.forEach(function (block, index) {
                    block.setAttribute('data-action-index', String(index));
                    var title = block.querySelector('[data-action-title]');
                    if (title) title.textContent = 'Action ' + (index + 1);

                    block.querySelectorAll('[name]').forEach(function (field) {
                        field.name = field.name.replace(/actions\[(?:\d+|__INDEX__|__ACTION_INDEX__)\]/, 'actions[' + index + ']');
                    });

                    var removeAction = block.querySelector('[data-remove-action]');
                    if (removeAction) {
                        removeAction.classList.toggle('hidden', index === 0);
                    }

                    block.querySelectorAll('[data-rmo-list]').forEach(function (list) {
                        var rows = list.querySelectorAll('[data-rmo-row]');
                        rows.forEach(function (row, rowIndex) {
                            var removeRmo = row.querySelector('[data-remove-rmo]');
                            if (removeRmo) removeRmo.classList.toggle('hidden', rowIndex === 0);
                        });
                    });

                    syncActionMode(block);
                    syncActionFinancing(block);
                    syncActionEcheance(block);
                    syncThresholdMode(block);
                    refreshSubActionIndexes(block);
                });
                filterRmosForScope();

                if (actionCount) {
                    actionCount.textContent = String(blocks.length);
                }
            }

            function addAction() {
                if (!actionsList || !actionTemplate) return;

                var index = actionsList.querySelectorAll('[data-action-block]').length;
                var html = actionTemplate.innerHTML
                    .replaceAll('__INDEX__', String(index))
                    .replaceAll('__NUMBER__', String(index + 1));
                actionsList.insertAdjacentHTML('beforeend', html);
                refreshActionIndexes();
            }

            function addRmo(block) {
                if (!block || !rmoTemplate) return;

                var index = block.getAttribute('data-action-index') || '0';
                var list = block.querySelector('[data-rmo-list]');
                if (!list) return;

                list.insertAdjacentHTML('beforeend', rmoTemplate.innerHTML.replaceAll('__ACTION_INDEX__', index));
                refreshActionIndexes();
                filterRmosForScope();
            }

            if (objectifSelect) {
                objectifSelect.addEventListener('change', syncScope);
            }

            if (addActionButton) {
                addActionButton.addEventListener('click', addAction);
            }

            if (actionsList) {
                actionsList.addEventListener('change', function (event) {
                    var target = event.target;
                    if (!(target instanceof HTMLElement)) return;

                    if (target.matches('[data-mode-select]')) {
                        var block = target.closest('[data-action-block]');
                        syncActionMode(block);
                        refreshSubActionIndexes(block);
                    }

                    if (target.matches('[data-threshold-mode]')) {
                        syncThresholdMode(target.closest('[data-action-block]'));
                    }

                    if (target.matches('[data-financing-select]')) {
                        syncActionFinancing(target.closest('[data-action-block]'));
                    }

                    if (target.matches('input[name$="[date_fin]"]')) {
                        syncActionEcheance(target.closest('[data-action-block]'));
                    }
                });

                actionsList.addEventListener('input', function (event) {
                    var target = event.target;
                    if (!(target instanceof HTMLElement)) return;

                    if (target.matches('[data-target-input]')) {
                        target.dataset.forceSousActions = target.value.trim() === '' ? '1' : '0';
                        syncActionMode(target.closest('[data-action-block]'));
                    }

                    if (target.matches('input[name$="[date_fin]"]')) {
                        syncActionEcheance(target.closest('[data-action-block]'));
                    }
                });

                actionsList.addEventListener('click', function (event) {
                    var target = event.target;
                    if (!(target instanceof HTMLElement)) return;

                    if (target.matches('[data-remove-action]')) {
                        var block = target.closest('[data-action-block]');
                        if (block && actionsList.querySelectorAll('[data-action-block]').length > 1) {
                            block.remove();
                            refreshActionIndexes();
                        }
                    }

                    if (target.matches('[data-add-rmo]')) {
                        addRmo(target.closest('[data-action-block]'));
                    }

                    if (target.matches('[data-mode-select]')) {
                        var modeBlock = target.closest('[data-action-block]');
                        syncActionMode(modeBlock);
                        refreshSubActionIndexes(modeBlock);
                    }

                    if (target.matches('[data-remove-rmo]')) {
                        var rmoList = target.closest('[data-rmo-list]');
                        var row = target.closest('[data-rmo-row]');
                        if (rmoList && row && rmoList.querySelectorAll('[data-rmo-row]').length > 1) {
                            row.remove();
                            refreshActionIndexes();
                        }
                    }

                    if (target.matches('[data-add-sub-action]')) {
                        addSubAction(target.closest('[data-action-block]'));
                    }

                    if (target.matches('[data-remove-sub-action]')) {
                        var subActionList = target.closest('[data-sub-actions-list]');
                        var subActionRow = target.closest('[data-sub-action-row]');
                        var parentBlock = target.closest('[data-action-block]');

                        if (subActionList && subActionRow && subActionList.querySelectorAll('[data-sub-action-row]').length > 1) {
                            subActionRow.remove();
                            refreshSubActionIndexes(parentBlock);
                        }
                    }
                });
            }

            syncScope();
            refreshActionIndexes();
        })();
    </script>
@endpush
