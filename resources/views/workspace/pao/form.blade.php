@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $objectifMeta = is_array($objectifMap ?? null) ? $objectifMap : [];
        $selectedObjectifId = (int) old('pas_objectif_id', $row->pas_objectif_id);
        $selectedDirectionId = (int) old('direction_id', $row->direction_id);
        $selectedServiceId = (int) old('service_id', $row->service_id);
        $selectedMeta = $selectedObjectifId > 0 && isset($objectifMeta[$selectedObjectifId]) ? $objectifMeta[$selectedObjectifId] : null;
    @endphp

    <div class="app-screen-flow">
    <section class="showcase-hero mb-4 app-screen-block">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">PAO</span>
                <h1 class="showcase-title">{{ $isEdit ? 'Modifier un PAO existant' : 'Enregistrer un nouveau PAO' }}</h1>
            </div>
            <div class="showcase-action-row">
                <a class="btn btn-secondary" href="{{ route('workspace.pao.index') }}">Retour liste</a>
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4 app-screen-kpis">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Mode</p>
            <p class="showcase-kpi-number">{{ $isEdit ? 'Edit.' : 'Nouv.' }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">OS disponibles</p>
            <p class="showcase-kpi-number">{{ count($objectifOptions) }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">PAS parents</p>
            <p class="showcase-kpi-number">{{ count($pasOptions) }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Workflow</p>
            <p class="showcase-kpi-number text-[1.35rem]">{{ $workflowStatusLabel((string) old('statut', $row->statut ?: 'brouillon')) }}</p>
        </article>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.pao.update', $row) : route('workspace.pao.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Perimetre PAO</h2>
                <div class="grid gap-4">
                    <div>
                        <label for="pas_objectif_id">Objectif strategique</label>
                        <select id="pas_objectif_id" name="pas_objectif_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($objectifOptions as $objectif)
                                <option value="{{ $objectif->id }}" @selected((int) old('pas_objectif_id', $row->pas_objectif_id) === $objectif->id)>
                                    {{ $objectif->code }} - {{ $objectif->libelle }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="pas_parent">PAS parent</label>
                        <input id="pas_parent" type="text" value="{{ $selectedMeta['pas'] ?? '' }}" readonly>
                        <p id="pas_parent_help" class="mt-2 text-xs text-slate-500">Renseigne a partir de l OS.</p>
                    </div>
                    <div>
                        <label for="axe_parent">Axe strategique</label>
                        <input id="axe_parent" type="text" value="{{ $selectedMeta['axe'] ?? '' }}" readonly>
                    </div>
                    <div>
                        <label for="direction_id">Direction</label>
                        <select id="direction_id" name="direction_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($directionOptions as $direction)
                                <option value="{{ $direction->id }}" @selected($selectedDirectionId === $direction->id)>
                                    {{ $direction->code }} - {{ $direction->libelle }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="service_id">Service affecte</label>
                        <select id="service_id" name="service_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($serviceOptions as $service)
                                <option
                                    value="{{ $service->id }}"
                                    data-direction-id="{{ $service->direction_id }}"
                                    @selected($selectedServiceId === $service->id)
                                >
                                    {{ $service->code }} - {{ $service->libelle }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="annee">Annee</label>
                        <input id="annee" name="annee" type="number" min="2000" value="{{ old('annee', $row->annee) }}" required>
                    </div>
                    <div>
                        <label for="echeance">Echeance</label>
                        <input id="echeance" name="echeance" type="date" value="{{ old('echeance', $row->echeance) }}">
                    </div>
                    <div>
                        <label for="statut">Statut</label>
                        <select id="statut" name="statut" required>
                            @foreach ($statusOptions as $status)
                                <option value="{{ $status }}" @selected(old('statut', $row->statut ?: 'brouillon') === $status)>{{ $workflowStatusLabel($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Contenu strategique</h2>
                <div>
                    <label for="titre">Titre</label>
                    <input id="titre" name="titre" type="text" value="{{ old('titre', $row->titre) }}" required>
                </div>

                <div class="grid gap-4 mt-3">
                    <div>
                        <label for="objectif_operationnel">Objectif operationnel</label>
                        <textarea id="objectif_operationnel" name="objectif_operationnel">{{ old('objectif_operationnel', $row->objectif_operationnel) }}</textarea>
                    </div>
                    <div>
                        <label for="resultats_attendus">Resultats attendus</label>
                        <textarea id="resultats_attendus" name="resultats_attendus">{{ old('resultats_attendus', $row->resultats_attendus) }}</textarea>
                    </div>
                </div>
                <div class="mt-3">
                    <label for="indicateurs_associes">Indicateurs associes</label>
                    <textarea id="indicateurs_associes" name="indicateurs_associes">{{ old('indicateurs_associes', $row->indicateurs_associes) }}</textarea>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
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
                                <td><span class="inline-block rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800">{{ $item['action'] }}</span></td>
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
                                <td colspan="5" class="text-slate-600">Aucune transition enregistree.</td>
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
    <script>
        (function () {
            var anneeInput = document.getElementById('annee');
            var echeanceInput = document.getElementById('echeance');
            var objectifInput = document.getElementById('pas_objectif_id');
            var directionInput = document.getElementById('direction_id');
            var serviceInput = document.getElementById('service_id');
            var pasParentInput = document.getElementById('pas_parent');
            var axeParentInput = document.getElementById('axe_parent');
            var pasParentHelp = document.getElementById('pas_parent_help');
            var objectifMap = @json($objectifMeta);

            function syncEcheanceRange() {
                if (!anneeInput || !echeanceInput) {
                    return;
                }

                var year = (anneeInput.value || '').trim();
                if (!/^\d{4}$/.test(year)) {
                    echeanceInput.min = '';
                    echeanceInput.max = '';
                    return;
                }

                echeanceInput.min = year + '-01-01';
                echeanceInput.max = year + '-12-31';

                if (echeanceInput.value && (echeanceInput.value < echeanceInput.min || echeanceInput.value > echeanceInput.max)) {
                    echeanceInput.value = '';
                }
            }

            function syncStrategicContext() {
                if (!objectifInput || !pasParentInput || !axeParentInput) {
                    return;
                }

                var objectifId = (objectifInput.value || '').trim();
                if (objectifId === '' || !objectifMap[objectifId]) {
                    pasParentInput.value = '';
                    axeParentInput.value = '';
                    if (pasParentHelp) {
                        pasParentHelp.textContent = 'Selectionnez un OS pour afficher le PAS parent.';
                    }
                    return;
                }

                var meta = objectifMap[objectifId];
                pasParentInput.value = meta.pas || '';
                axeParentInput.value = meta.axe || '';

                if (pasParentHelp) {
                    pasParentHelp.textContent = meta.periode
                        ? 'Periode du PAS parent : ' + meta.periode
                        : 'PAS parent detecte.';
                }
            }

            function filterServicesByDirection() {
                if (!directionInput || !serviceInput) {
                    return;
                }

                var selectedDirectionId = (directionInput.value || '').trim();
                var resetSelection = false;

                Array.prototype.forEach.call(serviceInput.options, function (option, index) {
                    if (index === 0) {
                        option.disabled = false;
                        return;
                    }

                    var optionDirectionId = (option.getAttribute('data-direction-id') || '').trim();
                    var allowed = selectedDirectionId === '' || optionDirectionId === selectedDirectionId;
                    option.disabled = !allowed;

                    if (!allowed && option.selected) {
                        resetSelection = true;
                    }
                });

                if (resetSelection) {
                    serviceInput.value = '';
                }
            }

            if (anneeInput) {
                anneeInput.addEventListener('input', syncEcheanceRange);
            }

            if (objectifInput) {
                objectifInput.addEventListener('change', syncStrategicContext);
            }

            if (directionInput) {
                directionInput.addEventListener('change', filterServicesByDirection);
            }

            syncEcheanceRange();
            syncStrategicContext();
            filterServicesByDirection();
        })();
    </script>
@endpush
