@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
        $availableServices = collect($paoOptions ?? [])
            ->pluck('service_id')
            ->filter()
            ->unique()
            ->count();
    @endphp
    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">{{ $isEdit ? 'Edition PTA' : 'Nouveau PTA' }}</span>
                <h1 class="showcase-title">{{ $isEdit ? 'Modifier un PTA existant' : 'Enregistrer un nouveau PTA' }}</h1>
                <p class="showcase-subtitle">Le PTA herite maintenant directement du service porte par le PAO. Le chef du service cible est le seul profil metier autorise a l ouvrir.</p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        PAO parent
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#3996d3]"></span>
                        Service du PAO
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#8fc043]"></span>
                        Perimetre fige
                    </span>
                </div>
            </div>
            <div class="showcase-action-row">
                <a class="btn btn-blue" href="{{ route('workspace.pta.index') }}">Retour liste</a>
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Mode</p>
            <p class="showcase-kpi-number">{{ $isEdit ? 'Edit.' : 'Nouv.' }}</p>
            <p class="showcase-kpi-meta">{{ $isEdit ? 'Mise a jour d un PTA existant' : 'Creation d un plan de service' }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">PAO disponibles</p>
            <p class="showcase-kpi-number">{{ count($paoOptions) }}</p>
            <p class="showcase-kpi-meta">Plans annuels selectionnables</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Services cibles</p>
            <p class="showcase-kpi-number">{{ $availableServices }}</p>
            <p class="showcase-kpi-meta">Services deja portes par les PAO</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Workflow</p>
            <p class="showcase-kpi-number text-[1.35rem]">{{ strtoupper((string) old('statut', $row->statut ?: 'brouillon')) }}</p>
            <p class="showcase-kpi-meta">{{ old('titre', $row->titre) ? 'Titre renseigne' : 'Titre a definir' }}</p>
        </article>
    </section>

    <section class="showcase-panel mb-4">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.pta.update', $row) : route('workspace.pta.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Perimetre PTA</h2>
                <p class="form-section-subtitle">Le PAO parent impose la direction et le service. Le formulaire reste donc strictement aligne sur son perimetre.</p>
                <div class="form-grid">
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
                        <p class="field-hint">Renseignee automatiquement a partir du PAO.</p>
                    </div>
                    <div>
                        <label for="service_label">Service</label>
                        <input id="service_label" type="text" value="" readonly>
                        <input id="service_id" name="service_id" type="hidden" value="{{ old('service_id', $row->service_id) }}">
                        <p class="field-hint">Le PTA sera cree pour ce seul service.</p>
                    </div>
                    <div>
                        <label for="statut">Statut</label>
                        <select id="statut" name="statut" required>
                            @foreach ($statusOptions as $status)
                                <option value="{{ $status }}" @selected(old('statut', $row->statut ?: 'brouillon') === $status)>{{ $status }}</option>
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
                <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.pta.index') }}">Retour</a>
            </div>
        </form>
    </section>

    @if ($isEdit)
        <section class="showcase-panel mb-4">
            <h2 class="showcase-panel-title">Timeline validation</h2>
            <p class="showcase-panel-subtitle">Historique des transitions de statut et des motifs de retour sur ce PTA.</p>
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
                                        {{ $item['from'] ?? '-' }} -> {{ $item['to'] ?? '-' }}
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

                if (serviceInput) {
                    serviceInput.value = serviceId;
                }

                if (directionLabel) {
                    directionLabel.value = directionText;
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
