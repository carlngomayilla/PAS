@extends('layouts.workspace')

@section('content')
    @php
        $summary = is_array($summary ?? null) ? $summary : [];
        $summaryCards = [
            ['label' => 'Utilisateurs', 'value' => $summary['total'] ?? $rows->total(), 'href' => route('workspace.referentiel.utilisateurs.index')],
            ['label' => 'Actifs', 'value' => $summary['actifs'] ?? 0, 'href' => route('workspace.referentiel.utilisateurs.index', ['is_active' => 1])],
            ['label' => 'Agents', 'value' => $summary['agents'] ?? 0, 'href' => route('workspace.referentiel.utilisateurs.index', ['role' => \App\Models\User::ROLE_AGENT])],
            ['label' => 'Encadrement', 'value' => $summary['encadrement'] ?? 0, 'href' => route('workspace.referentiel.utilisateurs.index')],
            ['label' => 'Directions', 'value' => $summary['directions_total'] ?? 0, 'href' => route('workspace.referentiel.directions.index')],
            ['label' => 'Services', 'value' => $summary['services_total'] ?? 0, 'href' => route('workspace.referentiel.services.index')],
        ];
    @endphp
    <div class="app-screen-flow">
    <section class="showcase-panel mb-4 app-screen-block">
        <h1 class="showcase-panel-title">Référentiel - Utilisateurs</h1>
    </section>
    <section class="showcase-summary-grid mb-4 app-screen-kpis">
        @foreach ($summaryCards as $card)
            <x-stat-card-link :href="$card['href']" :label="$card['label']" :value="$card['value']" :meta="null" />
        @endforeach
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <h2>Navigation</h2>
        <div class="flex flex-wrap gap-1.5">
            @if ($canWrite)
                <a class="btn btn-primary" href="{{ route('workspace.referentiel.utilisateurs.create') }}">Nouvel utilisateur</a>
            @endif
            <a class="btn btn-secondary" href="{{ route('workspace.referentiel.directions.index') }}">Directions</a>
            <a class="btn btn-secondary" href="{{ route('workspace.referentiel.services.index') }}">Services</a>
            @if ($canManageRoles)
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.utilisateurs.index') }}">Utilisateurs</a>
            @endif
        </div>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.referentiel.utilisateurs.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Nom ou email">
                </div>
                <div>
                    <label for="role">Rôle</label>
                    <select id="role" name="role">
                        <option value="">Tous</option>
                        @foreach ($roleOptions as $role)
                            <option value="{{ $role }}" @selected($filters['role'] === $role)>{{ $role }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="direction_id">Direction</label>
                    <select id="direction_id" name="direction_id">
                        <option value="">Toutes</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected($filters['direction_id'] === $direction->id)>
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
                                {{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="is_active">Statut</label>
                    <select id="is_active" name="is_active">
                        <option value="">Tous</option>
                        <option value="1" @selected($filters['is_active'] === '1')>Actifs</option>
                        <option value="0" @selected($filters['is_active'] === '0')>Inactifs</option>
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.utilisateurs.index') }}">Réinitialiser</a>
            </div>
        </form>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <h2>Liste des utilisateurs</h2>
        <div class="app-table-wrapper">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Profil</th>
                        <th>Rôle</th>
                        <th>Statut</th>
                        <th>Direction</th>
                        <th>Service</th>
                        @if ($canWrite)
                            <th>Opérations</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->id }}</td>
                            <td>
                                <div class="flex min-w-[250px] items-center gap-2.5">
                                    @if ($row->profile_photo_url)
                                        <img src="{{ $row->profile_photo_url }}" alt="Photo de {{ $row->name }}" class="h-10 w-10 rounded-full object-cover ring-2 ring-white shadow-sm">
                                    @else
                                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-[#3996d3] text-xs font-semibold text-white">
                                            {{ $row->profile_initials }}
                                        </span>
                                    @endif
                                    <div>
                                        <p class="font-medium text-slate-900">{{ $row->name }}</p>
                                        <p class="text-xs text-slate-600">{{ $row->email }}</p>
                                        @if ($row->isAgent())
                                            <p class="mt-1 text-xs text-slate-500">
                                                Matricule: {{ $row->agent_matricule ?: '-' }}
                                                | Fonction: {{ $row->agent_fonction ?: '-' }}
                                                | Tel: {{ $row->agent_telephone ?: '-' }}
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="anbg-badge anbg-badge-neutral px-3">
                                    {{ $row->roleLabel() }} ({{ $row->role }})
                                </span>
                            </td>
                            <td>
                                <span class="anbg-badge {{ $row->is_active ? 'anbg-badge-success' : 'anbg-badge-danger' }} px-3">
                                    {{ $row->is_active ? 'Actif' : 'Inactif' }}
                                </span>
                            </td>
                            <td>{{ $row->direction?->code ? $row->direction->code . ' - ' . $row->direction->libelle : '-' }}</td>
                            <td>{{ $row->service?->code ? $row->service->code . ' - ' . $row->service->libelle : '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="btn btn-warning" href="{{ route('workspace.referentiel.utilisateurs.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.referentiel.utilisateurs.destroy', $row) }}" data-confirm-message="Supprimer cet utilisateur ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
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
                            <td colspan="{{ $canWrite ? 7 : 6 }}">
                                <x-ui.empty-state
                                    title="Aucun utilisateur trouvé"
                                    message="Aucun compte ne correspond aux filtres courants."
                                    icon="users"
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
                var selectedDirection = String(directionInput.value || '');
                var selectedService = String(serviceInput.value || '');
                var selectedStillVisible = false;

                Array.prototype.forEach.call(serviceInput.options, function (option, index) {
                    if (index === 0) {
                        option.hidden = false;
                        option.disabled = false;
                        return;
                    }

                    var visible = selectedDirection === '' || String(option.getAttribute('data-direction-id') || '') === selectedDirection;
                    option.hidden = !visible;
                    option.disabled = !visible;

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
