@extends('layouts.workspace')

@section('content')
    @php
        $summary = is_array($summary ?? null) ? $summary : [];
        $summaryCards = [
            ['label' => 'Services', 'value' => $summary['total'] ?? $rows->total(), 'href' => route('workspace.referentiel.services.index')],
            ['label' => 'Actifs', 'value' => $summary['actifs'] ?? 0, 'href' => route('workspace.referentiel.services.index', ['actif' => 1])],
            ['label' => 'Directions', 'value' => $summary['directions_total'] ?? 0, 'href' => route('workspace.referentiel.directions.index')],
        ];
        if ($canManageRoles) {
            $summaryCards[] = ['label' => 'Utilisateurs', 'value' => $summary['users_total'] ?? 0, 'href' => route('workspace.referentiel.utilisateurs.index')];
        }
        $summaryCards[] = ['label' => 'PTA', 'value' => $summary['ptas_total'] ?? 0, 'href' => route('workspace.pta.index')];
    @endphp
    <div class="app-screen-flow">
    <section class="showcase-panel mb-4 app-screen-block">
        <h1 class="showcase-panel-title">Référentiel - Services</h1>
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
                <a class="btn btn-primary" href="{{ route('workspace.referentiel.services.create') }}">Nouveau service</a>
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
        <form method="GET" action="{{ route('workspace.referentiel.services.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Code ou libellé">
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
                    <label for="actif">Actif</label>
                    <select id="actif" name="actif">
                        <option value="">Tous</option>
                        <option value="1" @selected($filters['actif'] === 1)>Oui</option>
                        <option value="0" @selected($filters['actif'] === 0)>Non</option>
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.services.index') }}">Réinitialiser</a>
            </div>
        </form>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <h2>Liste des services</h2>
        <div class="app-table-wrapper">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Direction</th>
                        <th>Code</th>
                        <th>Libellé</th>
                        <th>Actif</th>
                        <th>Utilisateurs</th>
                        <th>PTA</th>
                        @if ($canWrite)
                            <th>Opérations</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->id }}</td>
                            <td>{{ $row->direction?->code }} - {{ $row->direction?->libelle }}</td>
                            <td><span class="anbg-badge anbg-badge-neutral px-3">{{ $row->code }}</span></td>
                            <td>{{ $row->libelle }}</td>
                            <td>{{ $row->actif ? 'Oui' : 'Non' }}</td>
                            <td>{{ $row->users_count }}</td>
                            <td>{{ $row->ptas_count }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="btn btn-warning" href="{{ route('workspace.referentiel.services.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.referentiel.services.destroy', $row) }}" data-confirm-message="Supprimer ce service ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
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
                            <td colspan="{{ $canWrite ? 8 : 7 }}">
                                <x-ui.empty-state
                                    title="Aucun service trouvé"
                                    message="Aucun service ne correspond aux filtres courants."
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
