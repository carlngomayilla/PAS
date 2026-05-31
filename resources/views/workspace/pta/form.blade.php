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
                    'mode_evaluation' => $action->mode_evaluation ?: ((int) ($action->nombre_sous_actions_prevu ?? 0) > 0 ? \App\Models\Action::MODE_SOUS_ACTIONS : \App\Models\Action::MODE_SANS_QUANTITE),
                    'priorite' => $action->priorite,
                    'montant_estime' => $action->montant_estime,
                    'nature_financement' => $action->nature_financement ?: $action->description_financement,
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
                    'sous_actions' => $action->relationLoaded('sousActions') && $action->sousActions->isNotEmpty()
                        ? $action->sousActions->map(fn ($sousAction): array => [
                            'id' => $sousAction->id,
                            'agent_id' => $sousAction->agent_id,
                            'libelle' => $sousAction->libelle,
                            'description' => $sousAction->description,
                            'resultat_attendu' => $sousAction->resultat_attendu,
                            'date_debut' => optional($sousAction->date_debut)->format('Y-m-d'),
                            'date_fin' => optional($sousAction->date_fin)->format('Y-m-d'),
                            'cible_prevue' => $sousAction->cible_prevue,
                            'unite' => $sousAction->unite,
                            'commentaire' => $sousAction->commentaire,
                        ])->values()->all()
                        : collect(range(1, max(0, (int) ($action->nombre_sous_actions_prevu ?? 0))))->map(fn (int $number): array => [
                            'libelle' => 'Sous-action '.$number,
                            'date_debut' => optional($action->date_debut)->format('Y-m-d'),
                            'date_fin' => optional($action->date_fin)->format('Y-m-d'),
                        ])->all(),
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
                    // Etat de gel pour la nouvelle UX "Demande modification" (2026-05-29) :
                    // une action est consideree comme "enregistree et figee" si elle
                    // est parametree ET son verrou n'a pas ete leve par le DG. Le
                    // bypass cote service ne s'applique PAS a l'UI : seuls SA et DG
                    // voient le bouton Enregistrer permanente.
                    'statut_parametrage' => $action->statut_parametrage,
                    'modification_locked_at' => optional($action->modification_locked_at)->format('Y-m-d H:i:s'),
                    'modification_unlocked_at' => optional($action->modification_unlocked_at)->format('Y-m-d H:i:s'),
                    'modification_unlock_expires_at' => optional($action->modification_unlock_expires_at)->format('Y-m-d H:i:s'),
                ];
            })->values();
        } else {
            $actionRows = collect([[
                'libelle' => '',
                'description' => '',
                'date_debut' => '',
                'date_fin' => '',
                'statut' => 'non_demarre',
                'mode_evaluation' => \App\Models\Action::MODE_SANS_QUANTITE,
                'priorite' => 'normale',
                'montant_estime' => '',
                'nature_financement' => '',
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

    <div class="app-screen-flow pta-registration-screen">
        @if ($isEdit)
            @include('workspace.planning-unlocks._lock-banner', [
                'target' => $row,
                'route' => route('workspace.pta.unlock-requests.store', $row),
            ])
        @endif

        <section class="showcase-panel pta-form-panel mb-4 app-screen-block">
            <form method="POST" enctype="multipart/form-data" class="form-shell pta-form-shell" action="{{ $isEdit ? route('workspace.pta.update', $row) : route('workspace.pta.store') }}">
                @csrf
                @if ($isEdit)
                    @method('PUT')
                @endif

                <input id="titre" name="titre" type="hidden" value="{{ $defaultTitle }}">

                <div class="form-section pta-scope-section">
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
                                        {{ $objectif->libelle }} ({{ $objectif->direction?->code }} / {{ $objectif->service?->code }})
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
                                    <option value="{{ $status }}" @selected(old('statut', $row->statut ?: 'en_cours') === $status)>{{ $workflowStatusLabel($status) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="hidden md:col-span-2">
                            <label for="objectif_operationnel_description">Description de l'objectif opérationnel sélectionné</label>
                            <textarea id="objectif_operationnel_description" class="hidden" rows="4" readonly>{{ old('objectif_operationnel_description', $selectedObjectifDescription) }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="form-section pta-actions-section">
                    <div class="pta-actions-header mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="form-section-title mb-0">Actions liées à l'objectif opérationnel</h2>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="pta-action-count-badge anbg-badge anbg-badge-info px-3"><span id="pta-action-count">{{ $actionRows->count() }}</span> action(s)</span>
                            <button id="add-action-button" class="btn btn-primary" type="button">+ Ajouter une autre action</button>
                        </div>
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
                <div class="app-table-wrapper overflow-x-auto">
                    <table class="app-table data-table">
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
                                    <td colspan="5">
                                        <x-ui.empty-state
                                            title="Aucune transition enregistrée"
                                            message="Le circuit de validation apparaîtra ici après les premiers changements de statut."
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
                'mode_evaluation' => \App\Models\Action::MODE_SANS_QUANTITE,
                'priorite' => 'normale',
                'montant_estime' => '',
                'nature_financement' => '',
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

                // Workflow V2 : le select pilote type_action (quantitative /
                // non_quantitative / composee).
                var modeField = block.querySelector('[data-type-action-select]') || block.querySelector('[data-mode-select]');
                var mode = modeField ? modeField.value : 'non_quantitative';
                var targetWrappers = block.querySelectorAll('[data-target-wrapper]');
                var subActionsSection = block.querySelector('[data-sub-actions-section]');
                var isQuantitative = mode === 'quantitative';
                var showSubActions = mode === 'composee';

                targetWrappers.forEach(function (wrapper) {
                    var input = wrapper.querySelector('input:not([type="hidden"]), select, textarea');
                    wrapper.classList.toggle('hidden', !isQuantitative);
                    if (input) {
                        input.disabled = !isQuantitative;
                        input.required = isQuantitative && !wrapper.hasAttribute('data-target-optional');
                    }
                });

                if (subActionsSection) {
                    subActionsSection.classList.toggle('hidden', !showSubActions);
                    subActionsSection.querySelectorAll('input, textarea, select, button').forEach(function (field) {
                        field.disabled = !showSubActions;
                    });
                    if (showSubActions) {
                        refreshSubActionIndexes(block);
                        updateWeightTotal(block);
                    }
                }

                // Indice contextuel sous le select.
                var hint = block.querySelector('[data-type-action-hint]');
                if (hint) {
                    hint.textContent = isQuantitative
                        ? 'Cible chiffrée + unité + seuils numériques.'
                        : (showSubActions
                            ? 'Performance calculée depuis les sous-actions (poids Σ=100%).'
                            : 'Pièce justificative attendue (réalisé = 0 % ou 100 %).');
                }
            }

            // Affiche/masque cible+unité d'une sous-action selon son type.
            function syncSubActionType(row) {
                if (!row) return;
                var typeField = row.querySelector('[data-sub-type-select]');
                var type = typeField ? typeField.value : 'quantitative';
                var isQuanti = type === 'quantitative';
                row.querySelectorAll('[data-sub-target-wrapper]').forEach(function (wrapper) {
                    wrapper.classList.toggle('hidden', !isQuanti);
                    var input = wrapper.querySelector('input:not([type="hidden"]), select, textarea');
                    if (input) { input.disabled = !isQuanti; }
                });
            }

            // Recalcule la somme des poids des sous-actions + feedback couleur.
            function updateWeightTotal(block) {
                if (!block) return;
                var total = 0;
                var hasValue = false;
                block.querySelectorAll('[data-sub-action-row]').forEach(function (row) {
                    if (row.classList.contains('hidden')) return;
                    var input = row.querySelector('[data-sub-weight-input]');
                    if (input && input.value !== '') {
                        total += parseFloat(input.value) || 0;
                        hasValue = true;
                    }
                });
                var counter = block.querySelector('[data-weight-total]');
                if (counter) {
                    counter.textContent = hasValue ? (Math.round(total * 100) / 100) : '0';
                    var wrapper = block.querySelector('[data-weight-counter]');
                    if (wrapper) {
                        var ok = !hasValue || Math.abs(total - 100) < 0.01;
                        wrapper.style.color = ok ? '#16a34a' : '#dc2626';
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

                syncSubActionAgentOptions(block);
            }

            function selectedActionRmoIds(block) {
                if (!block) return [];

                return Array.prototype.slice.call(block.querySelectorAll('[data-rmo-list] select'))
                    .map(function (select) { return select.value; })
                    .filter(function (value, index, values) { return value && values.indexOf(value) === index; });
            }

            function syncSubActionAgentOptions(block) {
                var allowed = selectedActionRmoIds(block);
                if (!block || !allowed.length) return;

                block.querySelectorAll('[data-sub-action-agent-select]').forEach(function (select, index) {
                    var current = select.value;
                    Array.prototype.forEach.call(select.options, function (option, index) {
                        if (index === 0) {
                            option.hidden = false;
                            option.disabled = false;
                            return;
                        }

                        var visible = allowed.indexOf(option.value) !== -1;
                        option.hidden = !visible;
                        option.disabled = !visible;
                    });

                    if (current && allowed.indexOf(current) === -1) {
                        select.value = allowed[0] || '';
                    } else if (!current) {
                        select.value = allowed[index % allowed.length] || '';
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

                // Rétablit les valeurs par défaut V2 des cases à cocher de la sous-action
                // (le clone les a toutes décochées) : justificatif + difficulté activés.
                var cbProof = clone.querySelector('input[type="checkbox"][name$="[requires_proof]"]');
                if (cbProof) cbProof.checked = true;
                var cbDiff = clone.querySelector('input[type="checkbox"][name$="[allows_difficulty]"]');
                if (cbDiff) cbDiff.checked = true;

                list.appendChild(clone);
                refreshSubActionIndexes(block);
                syncSubActionEcheances(block);
                syncSubActionType(clone);
                updateWeightTotal(block);
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
                        )) {
                            field.required = active;
                        } else if (field.name && field.name.indexOf('[justificatif_financement]') !== -1) {
                            field.required = false;
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

                syncSubActionEcheances(block);
            }

            function syncSubActionEcheances(block) {
                if (!block) return;

                var actionEnd = block.querySelector('input[name$="[date_fin]"]:not([name*="[sous_actions]"])');
                var objectiveOption = objectifSelect ? objectifSelect.options[objectifSelect.selectedIndex] : null;
                var objectiveEcheance = optionValue(objectiveOption, 'data-echeance');
                var maxDate = actionEnd && actionEnd.value ? actionEnd.value : objectiveEcheance;

                if (objectiveEcheance && maxDate && maxDate > objectiveEcheance) {
                    maxDate = objectiveEcheance;
                }

                block.querySelectorAll('input[name*="[sous_actions]"][name$="[date_fin]"]').forEach(function (field) {
                    field.max = maxDate || '';
                    if (maxDate && field.value && field.value > maxDate) {
                        field.value = maxDate;
                    }
                });
            }

            function refreshActionIndexes() {
                if (!actionsList) return;

                var blocks = actionsList.querySelectorAll('[data-action-block]');
                blocks.forEach(function (block, index) {
                    block.setAttribute('data-action-index', String(index));
                    var title = block.querySelector('[data-action-title]');
                    if (title) title.textContent = 'Action ' + (index + 1);
                    syncActionSummary(block);

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
                    block.querySelectorAll('[data-sub-action-row]').forEach(syncSubActionType);
                    updateWeightTotal(block);
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
                var addedBlock = actionsList.querySelector('[data-action-block]:last-child');
                if (addedBlock) {
                    closeOtherActions(addedBlock);
                    addedBlock.open = true;
                }
            }

            function syncActionSummary(block) {
                if (!block) return;

                var labelInput = block.querySelector('input[name$="[libelle]"]');
                var summary = block.querySelector('[data-action-summary]');
                if (summary) {
                    summary.textContent = labelInput && labelInput.value.trim() ? labelInput.value.trim() : 'Nouvelle action';
                }
            }

            function closeOtherActions(activeBlock) {
                if (!actionsList || !activeBlock) return;

                actionsList.querySelectorAll('[data-action-block]').forEach(function (block) {
                    if (block !== activeBlock && block instanceof HTMLDetailsElement) {
                        block.open = false;
                    }
                });
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

                    if (target.matches('[data-sub-type-select]')) {
                        syncSubActionType(target.closest('[data-sub-action-row]'));
                    }

                    if (target.matches('[data-financing-select]')) {
                        syncActionFinancing(target.closest('[data-action-block]'));
                    }

                    if (target.matches('[data-rmo-list] select')) {
                        syncSubActionAgentOptions(target.closest('[data-action-block]'));
                    }

                    if (target.matches('input[name$="[date_fin]"]')) {
                        var targetBlock = target.closest('[data-action-block]');
                        if (target.name.indexOf('[sous_actions]') === -1) {
                            syncActionEcheance(targetBlock);
                        } else {
                            syncSubActionEcheances(targetBlock);
                        }
                    }
                });

                actionsList.addEventListener('input', function (event) {
                    var target = event.target;
                    if (!(target instanceof HTMLElement)) return;

                    if (target.matches('[data-target-input]')) {
                        target.dataset.forceSousActions = target.value.trim() === '' ? '1' : '0';
                        syncActionMode(target.closest('[data-action-block]'));
                    }

                    if (target.matches('[data-sub-weight-input]')) {
                        updateWeightTotal(target.closest('[data-action-block]'));
                    }

                    if (target.matches('input[name$="[libelle]"]')) {
                        syncActionSummary(target.closest('[data-action-block]'));
                    }

                    if (target.matches('input[name$="[date_fin]"]')) {
                        var targetBlock = target.closest('[data-action-block]');
                        if (target.name.indexOf('[sous_actions]') === -1) {
                            syncActionEcheance(targetBlock);
                        } else {
                            syncSubActionEcheances(targetBlock);
                        }
                    }
                });

                @if ($isEdit)
                    var inlineUpsertUrl = @json(route('workspace.pta.actions.upsert-inline', $row));
                    var inlineDeleteUrlBase = @json(rtrim(url('workspace/actions'), '/'));
                @else
                    var inlineUpsertUrl = null;
                    var inlineDeleteUrlBase = null;
                @endif
                var csrfToken = @json(csrf_token());

                function flashActionMessage(block, isError, message) {
                    var existing = block.querySelector('[data-action-flash]');
                    if (existing) existing.remove();
                    var div = document.createElement('div');
                    div.setAttribute('data-action-flash', '');
                    div.className = 'mt-2 rounded px-3 py-2 text-sm ' + (isError
                        ? 'bg-red-50 text-red-700 border border-red-200'
                        : 'bg-green-50 text-green-700 border border-green-200');
                    div.textContent = message;
                    var body = block.querySelector('.pta-action-body');
                    if (body) body.insertBefore(div, body.firstChild);
                    if (! isError) setTimeout(function () { div.remove(); }, 4000);
                }

                function collectActionPayload(block) {
                    var index = block.getAttribute('data-action-index');
                    var prefix = 'actions[' + index + ']';
                    var payload = {};
                    var fields = block.querySelectorAll('[name^="' + prefix + '"]');
                    fields.forEach(function (field) {
                        if (field.disabled) return;
                        var raw = field.getAttribute('name');
                        var key = raw.substring(prefix.length); // [libelle] | [rmo_ids][] | etc.
                        if (key === '') return;
                        // Strip leading '[' and trailing ']' from outermost level → libelle, rmo_ids[], etc.
                        var path = key.replace(/^\[|\]$/g, '').split('][');
                        var value;
                        if (field.type === 'checkbox') {
                            if (! field.checked) return;
                            value = field.value || true;
                        } else if (field.type === 'radio') {
                            if (! field.checked) return;
                            value = field.value;
                        } else if (field.type === 'file') {
                            return; // files non supportes en JSON
                        } else {
                            value = field.value;
                        }
                        // Construire la structure imbriquee
                        var cur = payload;
                        for (var i = 0; i < path.length - 1; i++) {
                            var seg = path[i];
                            if (seg === '') seg = (Object.keys(cur).length).toString();
                            if (! (seg in cur)) cur[seg] = isNaN(parseInt(path[i+1], 10)) && path[i+1] !== '' ? {} : [];
                            cur = cur[seg];
                        }
                        var last = path[path.length - 1];
                        if (last === '') {
                            if (! Array.isArray(cur)) cur = [];
                            cur.push(value);
                        } else {
                            cur[last] = value;
                        }
                    });
                    return payload;
                }

                actionsList.addEventListener('click', function (event) {
                    var target = event.target;
                    if (!(target instanceof HTMLElement)) return;

                    // Bouton "Demande de modification" : POST vers la route unlock-requests.
                    if (target.matches('[data-request-modification]')) {
                        event.preventDefault();
                        event.stopPropagation();
                        var blockReq = target.closest('[data-action-block]');
                        if (! blockReq) return;
                        var actionId = blockReq.getAttribute('data-action-id');
                        if (! actionId) {
                            flashActionMessage(blockReq, true, 'Action non sauvegardee : impossible de demander une modification.');
                            return;
                        }
                        var reason = window.prompt(
                            "Motif de la demande de modification (min 5 caracteres) :\n\nLa demande sera transmise au DG. Le service Planification sera également notifié.",
                            ''
                        );
                        if (reason === null) return;
                        if (reason.trim().length < 5) {
                            flashActionMessage(blockReq, true, 'Motif requis (5 caracteres minimum).');
                            return;
                        }
                        target.disabled = true;
                        target.textContent = 'Envoi…';
                        var fd = new FormData();
                        fd.append('_token', csrfToken);
                        fd.append('reason', reason);
                        fetch('/workspace/actions/' + actionId + '/demandes-deverrouillage', {
                            method: 'POST',
                            body: fd,
                            credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                        }).then(function (resp) {
                            target.disabled = false;
                            target.textContent = 'Demande de modification';
                            if (resp.ok || resp.redirected) {
                                flashActionMessage(blockReq, false, 'Demande envoyee au DG (Planification en copie).');
                            } else {
                                flashActionMessage(blockReq, true, 'Echec de l\'envoi (HTTP ' + resp.status + ').');
                            }
                        }).catch(function (err) {
                            target.disabled = false;
                            target.textContent = 'Demande de modification';
                            flashActionMessage(blockReq, true, 'Erreur reseau : ' + (err && err.message ? err.message : 'inconnue'));
                        });
                        return;
                    }

                    // Bouton "Modifier" : ouvre l'accordeon + focus + scroll.
                    if (target.matches('[data-edit-action]')) {
                        event.preventDefault();
                        event.stopPropagation();
                        var blockEdit = target.closest('[data-action-block]');
                        if (blockEdit instanceof HTMLDetailsElement) {
                            blockEdit.open = true;
                            target.classList.add('hidden');
                            var firstInput = blockEdit.querySelector('input:not([type=hidden]), textarea, select');
                            if (firstInput) {
                                firstInput.focus();
                                blockEdit.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        }
                        return;
                    }

                    // Bouton "Enregistrer" : sauvegarde AJAX inline de cette action.
                    if (target.matches('[data-save-action]')) {
                        event.preventDefault();
                        event.stopPropagation();
                        var blockSave = target.closest('[data-action-block]');
                        if (! blockSave || ! inlineUpsertUrl) {
                            flashActionMessage(blockSave, true, "Sauvegarde inline indisponible en creation. Utilisez 'Enregistrer le PTA' en bas.");
                            return;
                        }
                        var payload = collectActionPayload(blockSave);
                        target.disabled = true;
                        target.textContent = 'Sauvegarde…';
                        fetch(inlineUpsertUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify(payload),
                            credentials: 'same-origin',
                        }).then(function (response) {
                            return response.json().then(function (data) {
                                return { ok: response.ok, data: data };
                            });
                        }).then(function (result) {
                            target.disabled = false;
                            target.textContent = 'Enregistrer';
                            if (result.ok && result.data && result.data.ok) {
                                flashActionMessage(blockSave, false, result.data.message || 'Action enregistree.');
                                // Mettre a jour l'id de l'action (selecteur strict pour ne pas
                                // attraper les inputs sous_actions[N][id]).
                                if (result.data.action && result.data.action.id) {
                                    var blockIndex = blockSave.getAttribute('data-action-index');
                                    var actionIdInput = blockSave.querySelector('input[name="actions[' + blockIndex + '][id]"]');
                                    if (actionIdInput) actionIdInput.value = result.data.action.id;
                                    blockSave.setAttribute('data-action-id', result.data.action.id);
                                    blockSave.id = 'action-' + result.data.action.id;
                                }
                                // Mettre a jour les ids des sous-actions (evite les doublons aux saves suivants).
                                if (result.data.action && Array.isArray(result.data.action.sous_action_ids)) {
                                    var blockIndex2 = blockSave.getAttribute('data-action-index');
                                    var subIdInputs = blockSave.querySelectorAll('input[name^="actions[' + blockIndex2 + '][sous_actions]"][name$="[id]"]');
                                    result.data.action.sous_action_ids.forEach(function (saId, idx) {
                                        if (subIdInputs[idx]) subIdInputs[idx].value = saId;
                                    });
                                }
                                // Mettre a jour le titre/summary du heading
                                var summarySpan = blockSave.querySelector('[data-action-summary]');
                                if (summarySpan && result.data.action) summarySpan.textContent = result.data.action.libelle;
                            } else {
                                var msg = (result.data && result.data.message) || 'Erreur lors de la sauvegarde.';
                                if (result.data && result.data.errors) {
                                    msg += ' Champs : ' + Object.keys(result.data.errors).join(', ');
                                }
                                flashActionMessage(blockSave, true, msg);
                            }
                        }).catch(function (err) {
                            target.disabled = false;
                            target.textContent = 'Enregistrer';
                            flashActionMessage(blockSave, true, 'Erreur reseau : ' + (err && err.message ? err.message : 'inconnue'));
                        });
                        return;
                    }

                    if (target.matches('[data-remove-action]')) {
                        event.preventDefault();
                        event.stopPropagation();
                        var block = target.closest('[data-action-block]');
                        if (! block) return;
                        var actionId = block.getAttribute('data-action-id');
                        // Si action deja persistee : DELETE AJAX.
                        if (actionId && actionId !== '' && inlineDeleteUrlBase) {
                            if (! window.confirm('Supprimer definitivement cette action et tout son contenu (sous-actions, semaines) ?')) return;
                            var motif = window.prompt('Motif de suppression (min 5 caracteres) :', 'Suppression depuis le formulaire PTA');
                            if (motif === null || motif.trim().length < 5) {
                                flashActionMessage(block, true, 'Motif requis (5 caracteres minimum).');
                                return;
                            }
                            target.disabled = true;
                            var fd = new FormData();
                            fd.append('_method', 'DELETE');
                            fd.append('_token', csrfToken);
                            fd.append('motif', motif);
                            fetch(inlineDeleteUrlBase + '/' + actionId, {
                                method: 'POST',
                                body: fd,
                                credentials: 'same-origin',
                                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                            }).then(function (resp) {
                                target.disabled = false;
                                if (resp.ok || resp.redirected) {
                                    block.remove();
                                    refreshActionIndexes();
                                } else {
                                    flashActionMessage(block, true, 'Echec suppression (HTTP ' + resp.status + ').');
                                }
                            }).catch(function (err) {
                                target.disabled = false;
                                flashActionMessage(block, true, 'Erreur reseau : ' + (err && err.message ? err.message : 'inconnue'));
                            });
                            return;
                        }
                        // Action non persistee : juste retirer le bloc.
                        if (actionsList.querySelectorAll('[data-action-block]').length > 1) {
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

                actionsList.addEventListener('toggle', function (event) {
                    var block = event.target;
                    if (block instanceof HTMLDetailsElement && block.matches('[data-action-block]') && block.open) {
                        closeOtherActions(block);
                    }
                }, true);
            }

            syncScope();
            refreshActionIndexes();
        })();
    </script>
@endpush
