@extends('layouts.workspace')

@section('content')
    <div class="app-screen-flow">
    <section class="ui-card mb-3.5 app-screen-block">
        <h1>Referentiel - Services</h1>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="btn btn-green" href="{{ route('workspace.referentiel.services.create') }}">Nouveau service</a>
            </p>
        @endif
    </section>

    <section class="ui-card mb-3.5 app-screen-block">
        <h2>Navigation</h2>
        <div class="flex flex-wrap gap-1.5">
            <a class="btn btn-blue" href="{{ route('workspace.referentiel.directions.index') }}">Directions</a>
            <a class="btn btn-primary" href="{{ route('workspace.referentiel.services.index') }}">Services</a>
            @if ($canManageRoles)
                <a class="btn btn-blue" href="{{ route('workspace.referentiel.utilisateurs.index') }}">Utilisateurs</a>
            @endif
        </div>
    </section>

    <section class="ui-card mb-3.5 app-screen-block">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.referentiel.services.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Code ou libelle">
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
                <a class="btn btn-blue" href="{{ route('workspace.referentiel.services.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5 app-screen-block">
        <h2>Liste des services</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Direction</th>
                        <th>Code</th>
                        <th>Libelle</th>
                        <th>Actif</th>
                        <th>Utilisateurs</th>
                        <th>PTA</th>
                        @if ($canWrite)
                            <th>Operations</th>
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
                                        <a class="btn btn-amber" href="{{ route('workspace.referentiel.services.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.referentiel.services.destroy', $row) }}" data-confirm-message="Supprimer ce service ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-red" type="submit">Supprimer</button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canWrite ? 8 : 7 }}" class="text-slate-600">Aucun service trouve.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $rows->links() }}</div>
    </section>
    </div>
@endsection
