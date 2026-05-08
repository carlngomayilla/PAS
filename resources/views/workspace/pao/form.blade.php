@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $objectifMeta = is_array($objectifMap ?? null) ? $objectifMap : [];
        $selectedObjectifId = (int) old('pas_objectif_id', $row->pas_objectif_id);
        $selectedDirectionId = (int) old('direction_id', $row->direction_id);
        $selectedMeta = $selectedObjectifId > 0 && isset($objectifMeta[$selectedObjectifId]) ? $objectifMeta[$selectedObjectifId] : null;
        $selectedAxeId = (int) old('pas_axe_id', $selectedMeta['axe_id'] ?? 0);
        $axeOptions = collect($objectifOptions)
            ->pluck('pasAxe')
            ->filter()
            ->unique('id')
            ->sortBy('ordre')
            ->values();
        $oldOperationalObjectives = old('objectifs_operationnels');
        if (! is_array($oldOperationalObjectives) || $oldOperationalObjectives === []) {
            $existingObjectives = $row->relationLoaded('objectifsOperationnels')
                ? $row->objectifsOperationnels
                : collect();
            $oldOperationalObjectives = $existingObjectives->isNotEmpty()
                ? $existingObjectives->map(fn ($objective) => [
                    'id' => $objective->id,
                    'libelle' => $objective->libelle,
                    'service_id' => $objective->service_id,
                    'echeance' => optional($objective->echeance)->format('Y-m-d'),
                    'description' => $objective->description,
                    'indicateurs' => $objective->indicateurs,
                ])->values()->all()
                : [[
                    'id' => null,
                    'libelle' => old('objectif_operationnel', $row->objectif_operationnel),
                    'service_id' => old('service_id', $row->service_id),
                    'echeance' => old('echeance', optional($row->echeance)->format('Y-m-d') ?: $row->echeance),
                    'description' => old('resultats_attendus', $row->resultats_attendus),
                    'indicateurs' => old('indicateurs_associes', $row->indicateurs_associes),
                ]];
        }
    @endphp

    <div class="app-screen-flow">
        <section class="showcase-hero mb-4 app-screen-block">
            <div class="showcase-hero-body">
                <div>
                    <span class="showcase-eyebrow">PAO</span>
                    <h1 class="showcase-title">{{ $isEdit ? 'Modifier un PAO existant' : 'Enregistrer un nouveau PAO' }}</h1>
                </div>
                <div class="showcase-action-row">
                    <a class="btn btn-blue" href="{{ route('workspace.pao.index') }}">Retour liste</a>
                </div>
            </div>
        </section>
<section class="showcase-panel mb-4 app-screen-block">
            <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.pao.update', $row) : route('workspace.pao.store') }}">
                @csrf
                @if ($isEdit)
                    @method('PUT')
                @endif

                <div class="form-section">
                    <h2 class="form-section-title">Perimetre PAO</h2>
                    <div class="form-grid">
                        <div class="md:col-span-2">
                            <label for="pas_axe_id">Axe strategique</label>
                            <select id="pas_axe_id" name="pas_axe_id" required>
                                <option value="">Sélectionner d'abord'un axe</option>
                                @foreach ($axeOptions as $axe)
                                    <option value="{{ $axe->id }}" @selected($selectedAxeId === (int) $axe->id)>
                                        {{ $axe->code }} - {{ $axe->libelle }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label for="pas_objectif_id">Objectif stratégique</label>
                            <select id="pas_objectif_id" name="pas_objectif_id" required>
                                <option value="">Sélectionner</option>
                                @foreach ($objectifOptions as $objectif)
                                    <option
                                        value="{{ $objectif->id }}"
                                        data-axe-id="{{ $objectif->pas_axe_id }}"
                                        @selected((int) old('pas_objectif_id', $row->pas_objectif_id) === $objectif->id)
                                    >
                                        {{ $objectif->code }} - {{ $objectif->libelle }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="pas_parent">PAS parent</label>
                            <input id="pas_parent" type="text" value="{{ $selectedMeta['pas'] ?? '' }}" readonly>
                        </div>
                        <div>
                            <label for="direction_id">Direction</label>
                            <select id="direction_id" name="direction_id" required>
                                <option value="">Sélectionner</option>
                                @foreach ($directionOptions as $direction)
                                    <option value="{{ $direction->id }}" @selected($selectedDirectionId === $direction->id)>
                                        {{ $direction->code }} - {{ $direction->libelle }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="annee">Année</label>
                            <input id="annee" name="annee" type="number" min="2000" value="{{ old('annee', $row->annee) }}" required>
                        </div>
                        <div class="hidden">
                            <label for="statut" class="hidden">Statut</label>
                            <select id="statut" class="hidden" disabled>
                                @foreach ($statusOptions as $status)
                                    <option value="{{ $status }}" @selected(old('statut', $row->statut ?: 'brouillon') === $status)>{{ $workflowStatusLabel($status) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="form-section-title">Déclinaison opérationnelle</h2>
                        </div>
                        <button id="add-operational-objective" class="btn btn-blue" type="button">+ Ajouter un autre objectif opérationnel</button>
                    </div>

                    <div id="operational-objectives-list" class="space-y-4">
                        @foreach ($oldOperationalObjectives as $objectiveIndex => $objective)
                            <div class="operational-objective-block rounded-xl border border-[#3996d3]/20 bg-white p-4 shadow-sm" data-operational-objective-block>
                                <input type="hidden" name="objectifs_operationnels[{{ $objectiveIndex }}][id]" value="{{ $objective['id'] ?? '' }}">
                                <div class="mb-3 flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-bold text-[#1c203d]" data-operational-objective-title>Objectif opérationnel {{ $objectiveIndex + 1 }}</h3>
                                    <button class="btn btn-secondary text-xs" type="button" data-remove-operational-objective @if ($objectiveIndex === 0) hidden @endif>Supprimer</button>
                                </div>
                                <div class="form-grid">
                                    <div class="md:col-span-2">
                                        <label for="objectifs_operationnels_{{ $objectiveIndex }}_libelle">Libellé de l'objectif opérationnel</label>
                                        <textarea id="objectifs_operationnels_{{ $objectiveIndex }}_libelle" name="objectifs_operationnels[{{ $objectiveIndex }}][libelle]" required>{{ $objective['libelle'] ?? '' }}</textarea>
                                    </div>
                                    <div>
                                        <label for="objectifs_operationnels_{{ $objectiveIndex }}_service_id">Service concerné</label>
                                        <select id="objectifs_operationnels_{{ $objectiveIndex }}_service_id" name="objectifs_operationnels[{{ $objectiveIndex }}][service_id]" required data-operational-service>
                                            <option value="">Sélectionner</option>
                                            @foreach ($serviceOptions as $service)
                                                <option
                                                    value="{{ $service->id }}"
                                                    data-direction-id="{{ $service->direction_id }}"
                                                    @selected((int) ($objective['service_id'] ?? 0) === (int) $service->id)
                                                >
                                                    {{ $service->code }} - {{ $service->libelle }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="objectifs_operationnels_{{ $objectiveIndex }}_echeance">Échéance</label>
                                        <input id="objectifs_operationnels_{{ $objectiveIndex }}_echeance" name="objectifs_operationnels[{{ $objectiveIndex }}][echeance]" type="date" value="{{ $objective['echeance'] ?? '' }}" required data-operational-echeance>
                                    </div>
                                    <div class="hidden md:col-span-2">
                                        <label for="objectifs_operationnels_{{ $objectiveIndex }}_description">Description / résultat attendu</label>
                                        <textarea id="objectifs_operationnels_{{ $objectiveIndex }}_description" class="hidden" disabled>{{ $objective['description'] ?? '' }}</textarea>
                                    </div>
                                    <div class="hidden md:col-span-2">
                                        <label for="objectifs_operationnels_{{ $objectiveIndex }}_indicateurs">Indicateurs prévus</label>
                                        <textarea id="objectifs_operationnels_{{ $objectiveIndex }}_indicateurs" class="hidden" disabled>{{ $objective['indicateurs'] ?? '' }}</textarea>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Mettre à jour' : 'Créer' }}</button>
                    <a class="btn btn-secondary" href="{{ route('workspace.pao.index') }}">Retour</a>
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
@endsection

@push('scripts')
    <script @cspNonce>
        (function () {
            var anneeInput = document.getElementById('annee');
            var axeInput = document.getElementById('pas_axe_id');
            var objectifInput = document.getElementById('pas_objectif_id');
            var directionInput = document.getElementById('direction_id');
            var pasParentInput = document.getElementById('pas_parent');
            var pasParentHelp = document.getElementById('pas_parent_help');
            var objectivesList = document.getElementById('operational-objectives-list');
            var addObjectiveButton = document.getElementById('add-operational-objective');
            var objectifMap = @json($objectifMeta);

            function syncEcheanceRange() {
                var year = (anneeInput.value || '').trim();
                var hasYear = /^\d{4}$/.test(year);

                document.querySelectorAll('[data-operational-echeance]').forEach(function (input) {
                    if (!hasYear) {
                        input.min = '';
                        input.max = '';
                        return;
                    }

                    input.min = year + '-01-01';
                    input.max = year + '-12-31';

                    if (input.value && (input.value < input.min || input.value > input.max)) {
                        input.value = '';
                    }
                });
            }

            function syncStrategicContext() {
                if (!objectifInput || !pasParentInput) {
                    return;
                }

                var objectifId = (objectifInput.value || '').trim();
                if (objectifId === '' || !objectifMap[objectifId]) {
                    pasParentInput.value = '';
                    if (pasParentHelp) {
                        pasParentHelp.textContent = 'Sélectionnez un objectif stratégique pour afficher son contexte parent.';
                    }
                    return;
                }

                var meta = objectifMap[objectifId];
                pasParentInput.value = meta.pas || '';
                if (axeInput && meta.axe_id) {
                    axeInput.value = String(meta.axe_id);
                    filterObjectifsByAxe(false);
                }

                if (pasParentHelp) {
                    pasParentHelp.textContent = meta.periode
                        ? 'Période du PAS parent : ' + meta.periode
                        : 'PAS parent détecté.';
                }
            }

            function filterObjectifsByAxe(resetInvalid) {
                if (!axeInput || !objectifInput) {
                    return;
                }

                var selectedAxeId = (axeInput.value || '').trim();
                var selectedObjectifId = (objectifInput.value || '').trim();

                Array.prototype.forEach.call(objectifInput.options, function (option, index) {
                    if (index === 0) {
                        option.hidden = false;
                        return;
                    }

                    var optionAxeId = (option.getAttribute('data-axe-id') || '').trim();
                    var visible = selectedAxeId === '' || optionAxeId === selectedAxeId;
                    option.hidden = !visible;

                    if (resetInvalid && !visible && option.value === selectedObjectifId) {
                        objectifInput.value = '';
                    }
                });
            }

            function filterServicesByDirection() {
                if (!directionInput) {
                    return;
                }

                var selectedDirectionId = (directionInput.value || '').trim();

                document.querySelectorAll('[data-operational-service]').forEach(function (serviceInput) {
                    var selectedServiceId = (serviceInput.value || '').trim();

                    Array.prototype.forEach.call(serviceInput.options, function (option, index) {
                        if (index === 0) {
                            option.hidden = false;
                            return;
                        }

                        var optionDirectionId = (option.getAttribute('data-direction-id') || '').trim();
                        var visible = selectedDirectionId === '' || optionDirectionId === selectedDirectionId;
                        option.hidden = !visible;

                        if (!visible && option.value === selectedServiceId) {
                            serviceInput.value = '';
                        }
                    });
                });
            }

            function renumberObjectives() {
                if (!objectivesList) {
                    return;
                }

                objectivesList.querySelectorAll('[data-operational-objective-block]').forEach(function (block, index) {
                    var title = block.querySelector('[data-operational-objective-title]');
                    var removeButton = block.querySelector('[data-remove-operational-objective]');

                    if (title) {
                        title.textContent = 'Objectif opérationnel ' + (index + 1);
                    }

                    if (removeButton) {
                        removeButton.hidden = index === 0;
                    }

                    block.querySelectorAll('input, select, textarea').forEach(function (field) {
                        if (field.name) {
                            field.name = field.name.replace(/objectifs_operationnels\[\d+\]/, 'objectifs_operationnels[' + index + ']');
                        }
                        if (field.id) {
                            field.id = field.id.replace(/objectifs_operationnels_\d+_/, 'objectifs_operationnels_' + index + '_');
                        }
                    });

                    block.querySelectorAll('label[for]').forEach(function (label) {
                        label.setAttribute('for', label.getAttribute('for').replace(/objectifs_operationnels_\d+_/, 'objectifs_operationnels_' + index + '_'));
                    });
                });
            }

            function addObjectiveBlock() {
                if (!objectivesList) {
                    return;
                }

                var firstBlock = objectivesList.querySelector('[data-operational-objective-block]');
                if (!firstBlock) {
                    return;
                }

                var clone = firstBlock.cloneNode(true);
                clone.querySelectorAll('input, textarea').forEach(function (field) {
                    field.value = '';
                });
                clone.querySelectorAll('select').forEach(function (field) {
                    field.value = '';
                });

                objectivesList.appendChild(clone);
                renumberObjectives();
                syncEcheanceRange();
                filterServicesByDirection();
            }

            if (objectivesList) {
                objectivesList.addEventListener('click', function (event) {
                    var removeButton = event.target.closest('[data-remove-operational-objective]');
                    if (!removeButton || removeButton.hidden) {
                        return;
                    }

                    var block = removeButton.closest('[data-operational-objective-block]');
                    if (block) {
                        block.remove();
                        renumberObjectives();
                    }
                });
            }

            if (addObjectiveButton) {
                addObjectiveButton.addEventListener('click', addObjectiveBlock);
            }

            if (anneeInput) {
                anneeInput.addEventListener('input', syncEcheanceRange);
            }

            if (objectifInput) {
                objectifInput.addEventListener('change', syncStrategicContext);
            }

            if (axeInput) {
                axeInput.addEventListener('change', function () {
                    filterObjectifsByAxe(true);
                    syncStrategicContext();
                });
            }

            if (directionInput) {
                directionInput.addEventListener('change', filterServicesByDirection);
            }

            syncEcheanceRange();
            filterObjectifsByAxe(false);
            syncStrategicContext();
            filterServicesByDirection();
        })();
    </script>
@endpush
