@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $availableServices = collect($paoOptions ?? [])
            ->pluck('service_id')
            ->filter()
            ->unique()
            ->count();
    @endphp
    <div class="app-screen-flow">
    <section class="showcase-hero mb-4 app-screen-block">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">PTA</span>
                <h1 class="showcase-title">{{ $isEdit ? 'Modifier un PTA existant' : 'Enregistrer un nouveau PTA' }}</h1>
            </div>
            <div class="showcase-action-row">
                <a class="btn btn-secondary" href="{{ route('workspace.pta.index') }}">Retour liste</a>
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4 app-screen-kpis">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Mode</p>
            <p class="showcase-kpi-number">{{ $isEdit ? 'Edit.' : 'Nouv.' }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">PAO disponibles</p>
            <p class="showcase-kpi-number">{{ count($paoOptions) }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Services cibles</p>
            <p class="showcase-kpi-number">{{ $availableServices }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Workflow</p>
            <p class="showcase-kpi-number text-[1.35rem]">{{ $workflowStatusLabel((string) old('statut', $row->statut ?: 'brouillon')) }}</p>
        </article>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.pta.update', $row) : route('workspace.pta.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Perimetre PTA</h2>
                <div class="grid gap-4">
                    <div>
                        <label for="pao_id">PAO</label>
                        <select id="pao_id" name="pao_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($paoOptions as $pao)
                                <option
                                    value="{{ $pao->id }}"
                                    data-direction-id="{{ $pao->direction_id }}"
                                    data-service-id="{{ $pao->service_id }}"
                                    data-direction-label="{{ $pao->direction?->code }} - {{ $pao->direction?->libelle }}"
                                    data-service-label="{{ $pao->service?->code }} - {{ $pao->service?->libelle }}"
                                    @selected((int) old('pao_id', $row->pao_id) === $pao->id)
                                >
                                    #{{ $pao->id }} - {{ $pao->titre }} ({{ $pao->direction?->code }} / {{ $pao->service?->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="direction_label">Direction</label>
                        <input id="direction_label" type="text" value="" readonly>
                        <input id="direction_id" name="direction_id" type="hidden" value="{{ old('direction_id', $row->direction_id) }}">
                    </div>
                    <div>
                        <label for="service_label">Service executant</label>
                        <input id="service_label" type="text" value="" readonly>
                        <input id="service_id" name="service_id" type="hidden" value="{{ old('service_id', $row->service_id) }}">
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
                <h2 class="form-section-title">Contenu du plan</h2>
                <div>
                    <label for="titre">Titre</label>
                    <input id="titre" name="titre" type="text" value="{{ old('titre', $row->titre) }}" required>
                </div>
                <div class="mt-3">
                    <label for="description">Description</label>
                    <textarea id="description" name="description">{{ old('description', $row->description) }}</textarea>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
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
            var paoSelect = document.getElementById('pao_id');
            var directionInput = document.getElementById('direction_id');
            var directionLabel = document.getElementById('direction_label');
            var serviceInput = document.getElementById('service_id');
            var serviceLabel = document.getElementById('service_label');

            function syncPaoScope() {
                if (!paoSelect) {
                    return;
                }

                var option = paoSelect.options[paoSelect.selectedIndex];
                var directionId = option ? (option.getAttribute('data-direction-id') || '').trim() : '';
                var serviceId = option ? (option.getAttribute('data-service-id') || '').trim() : '';
                var directionText = option ? (option.getAttribute('data-direction-label') || '').trim() : '';
                var serviceText = option ? (option.getAttribute('data-service-label') || '').trim() : '';

                if (directionInput) {
                    directionInput.value = directionId;
                }

                if (directionLabel) {
                    directionLabel.value = directionText;
                }

                if (serviceInput) {
                    serviceInput.value = serviceId;
                }

                if (serviceLabel) {
                    serviceLabel.value = serviceText;
                }
            }

            if (paoSelect) {
                paoSelect.addEventListener('change', syncPaoScope);
            }

            syncPaoScope();
        })();
    </script>
@endpush
