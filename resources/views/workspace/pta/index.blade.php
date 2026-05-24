@extends('layouts.workspace')

@section('content')
    @php
        $currentUser = auth()->user();
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $ps = is_array($ptaStats ?? null) ? $ptaStats : [];
        $directionOptions = collect($serviceOptions ?? [])
            ->pluck('direction')
            ->filter()
            ->unique('id')
            ->sortBy('code')
            ->values();
        $summaryCards = [
            ['label' => 'Total PTA',            'value' => $ps['total'] ?? $rows->total(),   'meta' => null, 'href' => route('workspace.pta.index'),                             'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Actifs',               'value' => $ps['actifs'] ?? 0,               'meta' => null, 'href' => route('workspace.pta.index', ['statut' => 'valide_ou_verrouille']), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Brouillons',           'value' => $ps['brouillons'] ?? 0,           'meta' => null, 'href' => route('workspace.pta.index', ['statut' => 'brouillon']),  'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Sans action',          'value' => $ps['sans_action'] ?? 0,          'meta' => null, 'href' => route('workspace.pta.index', ['without_action' => 1]),    'badge' => null, 'badge_tone' => ($ps['sans_action'] ?? 0) > 0 ? 'warning' : 'neutral'],
        ];
    @endphp

    <div class="app-screen-flow">
    <x-ui.page-title
        title="Plan de travail annuel"
        subtitle="Suivi des plans de travail par service, avec rattachement aux objectifs opérationnels."
    >
        <x-slot:actions>
            @if ($canWrite)
                <a class="btn btn-primary" href="{{ route('workspace.pta.create') }}">Nouveau PTA</a>
            @endif
        </x-slot:actions>
    </x-ui.page-title>

    <section class="showcase-summary-grid pta-summary-row mb-4 app-screen-kpis">
        @foreach ($summaryCards as $card)
            <x-stat-card-link
                :href="$card['href']"
                :label="$card['label']"
                :value="$card['value']"
                :meta="$card['meta']"
                :badge="$card['badge']"
                :badge-tone="$card['badge_tone']"
            />
        @endforeach
    </section>

    <section class="showcase-toolbar mb-4 app-screen-block">
        <div><h2 class="showcase-panel-title">Filtres</h2></div>
        <form method="GET" action="{{ route('workspace.pta.index') }}" class="mt-4">
            <div class="showcase-filter-grid">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Titre ou description">
                </div>
                <div>
                    <label for="pao_id">PAO</label>
                    <select id="pao_id" name="pao_id">
                        <option value="">Tous</option>
                        @foreach ($paoOptions as $pao)
                            <option value="{{ $pao->id }}" @selected($filters['pao_id'] === $pao->id)>
                                #{{ $pao->id }} - {{ $pao->titre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="direction_id">Direction</label>
                    <select id="direction_id" name="direction_id">
                        <option value="">Toutes</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected((int) ($filters['direction_id'] ?? 0) === (int) $direction->id)>
                                {{ $direction->code }} - {{ $direction->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="service_id">Service</label>
                    <select id="service_id" name="service_id">
                        <option value="">Tous</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" data-direction-id="{{ $service->direction_id }}" @selected($filters['service_id'] === $service->id)>
                                {{ $service->code }} - {{ $service->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="statut">Statut</label>
                    <select id="statut" name="statut">
                        <option value="">Tous</option>
                        @foreach ($statusOptions as $status)
                            <option value="{{ $status }}" @selected($filters['statut'] === $status)>{{ $workflowStatusLabel($status) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if ($filters['without_action'])
                <input type="hidden" name="without_action" value="1">
            @endif
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-secondary" href="{{ route('workspace.pta.index') }}">Réinitialiser</a>
            </div>
            @if ($filters['without_action'])
                <div class="mt-4 rounded-[1rem] border border-amber-200/80 bg-amber-50/90 px-4 py-3 text-sm font-medium text-amber-900">
                    PTA sans action
                </div>
            @endif
        </form>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des PTA</h2>
            </div>
            <span class="text-sm font-medium text-slate-500">{{ $rows->count() }} ligne(s)</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Titre</th>
                        <th>PAO</th>
                        <th>Direction</th>
                        <th>Service</th>
                        <th>Statut</th>
                        <th>Nb actions</th>
                        <th>Validateur</th>
                        @if ($canWrite)
                            <th>Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $statusClasses = match ((string) $row->statut) {
                                'brouillon' => 'anbg-badge anbg-badge-neutral',
                                'soumis' => 'anbg-badge anbg-badge-warning',
                                'valide' => 'anbg-badge anbg-badge-success',
                                'verrouille' => 'anbg-badge anbg-badge-info',
                                default => 'anbg-badge anbg-badge-neutral',
                            };
                            $canSubmit = $row->statut === 'brouillon'
                                && ($currentUser->hasGlobalWriteAccess()
                                    || ($currentUser->hasRole(\App\Models\User::ROLE_DIRECTION)
                                        && (int) $currentUser->direction_id === (int) $row->direction_id)
                                    || ($currentUser->hasRole(\App\Models\User::ROLE_SERVICE)
                                        && (int) $currentUser->direction_id === (int) $row->direction_id
                                        && (int) $currentUser->service_id === (int) $row->service_id));
                            $canApprove = $row->statut === 'soumis'
                                && ($currentUser->hasGlobalWriteAccess()
                                    || ($currentUser->hasRole(\App\Models\User::ROLE_DIRECTION)
                                        && (int) $currentUser->direction_id === (int) $row->direction_id));
                            $canLock = $row->statut === 'valide'
                                && $currentUser->hasGlobalWriteAccess();
                            $canReopen = in_array($row->statut, ['soumis', 'valide'], true)
                                && ($canApprove || ($row->statut === 'soumis' && $canSubmit));
                        @endphp
                        <tr>
                            <td>#{{ $row->id }}</td>
                            <td>
                                <div class="font-semibold text-slate-900">{{ $row->titre }}</div>
                            </td>
                            <td>{{ $row->pao?->titre ?? '-' }}</td>
                            <td>{{ $row->direction?->code }} {{ $row->direction?->libelle ? '- '.$row->direction->libelle : '' }}</td>
                            <td>{{ $row->service?->code }} {{ $row->service?->libelle ? '- '.$row->service->libelle : '' }}</td>
                            <td>
                                <span class="{{ $statusClasses }}">
                                    {{ $workflowStatusLabel($row->statut) }}
                                </span>
                            </td>
                            <td>{{ $row->actions_count }}</td>
                            <td>{{ $row->validateur?->name ?? '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-warning" href="{{ route('workspace.pta.edit', $row) }}">Modifier</a>
                                        @if ($canSubmit)
                                            <form method="POST" action="{{ route('workspace.pta.submit', $row) }}">
                                                @csrf
                                                <button class="btn btn-primary" type="submit">Soumettre</button>
                                            </form>
                                        @endif
                                        @if ($canApprove)
                                            <form method="POST" action="{{ route('workspace.pta.approve', $row) }}">
                                                @csrf
                                                <button class="btn btn-success" type="submit">Valider</button>
                                            </form>
                                        @endif
                                        @if ($canLock)
                                            <form method="POST" action="{{ route('workspace.pta.lock', $row) }}" data-confirm-message="Verrouiller ce PTA ?" data-confirm-tone="warning" data-confirm-label="Verrouiller">
                                                @csrf
                                                <button class="btn btn-primary" type="submit">Verrouiller</button>
                                            </form>
                                        @endif
                                        @if ($canReopen)
                                            <form method="POST" action="{{ route('workspace.pta.reopen', $row) }}" data-prompt-title="Retour brouillon" data-prompt-message="Saisir le motif de retour brouillon (PTA)." data-prompt-label="Motif de retour" data-prompt-placeholder="Minimum 5 caracteres" data-prompt-target="motif_retour" data-prompt-minlength="5" data-prompt-confirm="Confirmer">
                                                @csrf
                                                <input type="hidden" name="motif_retour" value="">
                                                <button class="btn btn-warning" type="submit">Retour brouillon</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('workspace.pta.destroy', $row) }}" data-confirm-message="Supprimer ce PTA ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-danger" type="submit">Supprimer</button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canWrite ? 9 : 8 }}">
                                <x-ui.empty-state
                                    title="Aucun PTA trouvé"
                                    message="Aucun plan de travail annuel ne correspond aux filtres courants."
                                    icon="filter"
                                    tone="info"
                                    class="my-4"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $rows->links() }}</div>
    </section>
    </div>
@endsection

@push('scripts')
    <script @cspNonce>
        (function () {
            var directionInput = document.getElementById('direction_id');
            var serviceInput = document.getElementById('service_id');

            if (!directionInput || !serviceInput) {
                return;
            }

            function syncServices() {
                var directionId = String(directionInput.value || '');
                var selectedService = String(serviceInput.value || '');
                var selectedStillVisible = false;

                Array.prototype.forEach.call(serviceInput.options, function (option, index) {
                    if (index === 0) {
                        option.hidden = false;
                        return;
                    }

                    var visible = directionId === '' || String(option.getAttribute('data-direction-id') || '') === directionId;
                    option.hidden = !visible;

                    if (visible && option.value === selectedService) {
                        selectedStillVisible = true;
                    }
                });

                if (selectedService && !selectedStillVisible) {
                    serviceInput.value = '';
                }
            }

            directionInput.addEventListener('change', syncServices);
            syncServices();
        })();
    </script>
@endpush
