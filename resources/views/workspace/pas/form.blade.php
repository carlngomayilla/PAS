@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);

        $initialAxes = old('axes', $axesPayload ?? []);
        if (! is_array($initialAxes) || count($initialAxes) === 0) {
            $initialAxes = [[
                'libelle' => '',
                'objectifs' => [[
                    'libelle' => '',
                ]],
            ]];
        }
    @endphp

    <div class="app-screen-flow">
        <section class="showcase-hero mb-4 app-screen-block">
            <div class="showcase-hero-body">
                <div>
                    <span class="showcase-eyebrow">PAS</span>
                    <h1 class="showcase-title">{{ $isEdit ? 'Modifier un PAS existant' : 'Enregistrer un nouveau PAS' }}</h1>
                </div>
                <div class="showcase-action-row">
                    <a class="btn btn-secondary" href="{{ route('workspace.pas.index') }}">Retour liste</a>
                </div>
            </div>
        </section>
<section class="showcase-panel mb-4 app-screen-block">
            <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.pas.update', $row) : route('workspace.pas.store') }}">
                @csrf
                @if ($isEdit)
                    @method('PUT')
                @endif

                <div class="form-section">
                    <h2 class="form-section-title">Informations stratégiques</h2>
                    <div class="form-grid">
                        <input id="titre" name="titre" type="hidden" value="{{ old('titre', $row->titre ?: 'PAS') }}">
                        <div>
                            <label for="pas_label_preview">Libellé PAS</label>
                            <input id="pas_label_preview" type="text" value="{{ $row->titre ?: 'PAS' }}" readonly>
                        </div>
                        <div>
                            <label for="periode_debut">Période début</label>
                            <input id="periode_debut" name="periode_debut" type="number" value="{{ old('periode_debut', $row->periode_debut) }}" min="2000" required>
                        </div>
                        <div>
                            <label for="periode_fin">Période fin</label>
                            <input id="periode_fin" name="periode_fin" type="number" value="{{ old('periode_fin', $row->periode_fin) }}" min="2000" required>
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
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="form-section-title">Axes et objectifs stratégiques</h2>
                            <p class="form-section-subtitle">Chaque axe doit contenir au moins un objectif stratégique.</p>
                        </div>
                        <button type="button" id="add-axe" class="btn btn-primary">Ajouter un axe</button>
                    </div>

                    <div id="axes-list" class="mt-4 space-y-4"></div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Mettre à jour' : 'Créer' }}</button>
                    <a class="btn btn-secondary" href="{{ route('workspace.pas.index') }}">Retour</a>
                </div>
            </form>
        </section>

        @if ($isEdit)
            <section class="showcase-panel mb-4 app-screen-block">
                <h2 class="showcase-panel-title">Timeline validation</h2>
                <div class="app-table-wrapper">
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
@endsection

@push('scripts')
    <script @cspNonce>
        (function () {
            var axesList = document.getElementById('axes-list');
            var addAxeButton = document.getElementById('add-axe');
            var axesData = @json($initialAxes);
            var titleInput = document.getElementById('titre');
            var pasPreview = document.getElementById('pas_label_preview');
            var startInput = document.getElementById('periode_debut');
            var endInput = document.getElementById('periode_fin');

            if (!axesList || !addAxeButton || !Array.isArray(axesData)) {
                return;
            }

            var axeCounter = 0;

            function syncPasLabel() {
                var start = startInput ? String(startInput.value || '').trim() : '';
                var end = endInput ? String(endInput.value || '').trim() : '';
                var label = 'PAS';

                if (start && end && start !== end) {
                    label = 'PAS ' + start + '-' + end;
                } else if (start) {
                    label = 'PAS ' + start;
                }

                if (titleInput) titleInput.value = label;
                if (pasPreview) pasPreview.value = label;
            }

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function createObjectifHtml(axeIndex, objectifIndex, objectif) {
                return '' +
                    '<div class="rounded-2xl border border-slate-200/85 p-3" data-objectif-index="' + objectifIndex + '">' +
                        '<div class="mb-2 flex items-center justify-between gap-2">' +
                            '<h4 class="text-sm font-semibold">Objectif stratégique</h4>' +
                            '<button type="button" class="remove-objectif btn btn-warning px-2 py-1 text-xs">Supprimer</button>' +
                        '</div>' +
                        '<div>' +
                            '<label>Libellé objectif</label>' +
                            '<input type="text" name="axes[' + axeIndex + '][objectifs][' + objectifIndex + '][libelle]" value="' + escapeHtml(objectif.libelle || '') + '" required>' +
                        '</div>' +
                    '</div>';
            }

            function appendObjectif(axeCard, objectif) {
                var axeIndex = axeCard.getAttribute('data-axe-index');
                var objectifList = axeCard.querySelector('.objectifs-list');
                var nextObjectifIndex = parseInt(axeCard.getAttribute('data-next-objectif-index') || '0', 10);
                var payload = objectif && typeof objectif === 'object' ? objectif : {};
                var wrapper = document.createElement('div');

                wrapper.innerHTML = createObjectifHtml(axeIndex, nextObjectifIndex, payload);
                objectifList.appendChild(wrapper.firstElementChild);
                axeCard.setAttribute('data-next-objectif-index', String(nextObjectifIndex + 1));
            }

            function createAxeCard(axe) {
                var axeIndex = axeCounter++;
                var payload = axe && typeof axe === 'object' ? axe : {};
                var objectifs = Array.isArray(payload.objectifs) && payload.objectifs.length > 0
                    ? payload.objectifs
                    : [{}];

                var card = document.createElement('div');
                card.className = 'rounded-2xl border border-slate-200/85 p-4';
                card.setAttribute('data-axe-index', String(axeIndex));
                card.setAttribute('data-next-objectif-index', '0');
                card.innerHTML = '' +
                    '<div class="mb-3 flex items-center justify-between gap-2">' +
                        '<h3 class="text-base font-semibold">Axe stratégique</h3>' +
                        '<button type="button" class="remove-axe btn btn-warning px-2 py-1 text-xs">Supprimer axe</button>' +
                    '</div>' +
                    '<div>' +
                        '<label>Libellé axe</label>' +
                        '<input type="text" name="axes[' + axeIndex + '][libelle]" value="' + escapeHtml(payload.libelle || '') + '" required>' +
                    '</div>' +
                    '<div class="mt-4">' +
                        '<div class="mb-2 flex items-center justify-between gap-2">' +
                            '<h4 class="text-sm font-semibold">Objectifs stratégiques</h4>' +
                            '<button type="button" class="add-objectif btn btn-secondary px-3 py-1.5 text-xs">Ajouter un objectif</button>' +
                        '</div>' +
                        '<div class="objectifs-list space-y-3"></div>' +
                    '</div>';

                axesList.appendChild(card);
                objectifs.forEach(function (objectif) {
                    appendObjectif(card, objectif);
                });
            }

            addAxeButton.addEventListener('click', function () {
                createAxeCard({
                    libelle: '',
                    objectifs: [{ libelle: '' }]
                });
            });

            axesList.addEventListener('click', function (event) {
                var removeAxeButton = event.target.closest('.remove-axe');
                if (removeAxeButton) {
                    var axeCards = axesList.querySelectorAll('[data-axe-index]');
                    if (axeCards.length <= 1) {
                        return;
                    }

                    removeAxeButton.closest('[data-axe-index]').remove();
                    return;
                }

                var addObjectifButton = event.target.closest('.add-objectif');
                if (addObjectifButton) {
                    appendObjectif(addObjectifButton.closest('[data-axe-index]'), { libelle: '' });
                    return;
                }

                var removeObjectifButton = event.target.closest('.remove-objectif');
                if (removeObjectifButton) {
                    var axeCard = removeObjectifButton.closest('[data-axe-index]');
                    var objectifs = axeCard ? axeCard.querySelectorAll('[data-objectif-index]') : [];
                    if (objectifs.length <= 1) {
                        return;
                    }

                    removeObjectifButton.closest('[data-objectif-index]').remove();
                }
            });

            if (startInput) startInput.addEventListener('input', syncPasLabel);
            if (endInput) endInput.addEventListener('input', syncPasLabel);
            syncPasLabel();

            axesData.forEach(function (axe) {
                createAxeCard(axe);
            });
        })();
    </script>
@endpush
