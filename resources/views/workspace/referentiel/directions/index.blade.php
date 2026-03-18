@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>Referentiel - Directions</h1>
        <p class="text-slate-600">Gestion des directions institutionnelles.</p>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" href="{{ route('workspace.referentiel.directions.create') }}">Nouvelle direction</a>
            </p>
        @endif
    </section>

    <section class="ui-card mb-3.5">
        <h2>Navigation referentiel</h2>
        <div class="flex flex-wrap gap-1.5">
            <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-slate-900 text-white hover:bg-slate-800" href="{{ route('workspace.referentiel.directions.index') }}">Directions</a>
            <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.referentiel.services.index') }}">Services</a>
            @if ($canManageRoles)
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.referentiel.utilisateurs.index') }}">Utilisateurs</a>
            @endif
        </div>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.referentiel.directions.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Code ou libelle">
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
                <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-slate-900 text-white hover:bg-slate-800" type="submit">Appliquer</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.referentiel.directions.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Liste des directions</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Libelle</th>
                        <th>Actif</th>
                        <th>Services</th>
                        <th>Utilisateurs</th>
                        <th>PAO</th>
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
                            <td><span class="inline-block rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800">{{ $row->code }}</span></td>
                            <td>{{ $row->libelle }}</td>
                            <td>{{ $row->actif ? 'Oui' : 'Non' }}</td>
                            <td>{{ $row->services_count }}</td>
                            <td>{{ $row->users_count }}</td>
                            <td>{{ $row->paos_count }}</td>
                            <td>{{ $row->ptas_count }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-amber-700 text-white hover:bg-amber-600" href="{{ route('workspace.referentiel.directions.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.referentiel.directions.destroy', $row) }}" onsubmit="return confirm('Supprimer cette direction ?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-red-700 text-white hover:bg-red-600" type="submit">Supprimer</button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canWrite ? 9 : 8 }}" class="text-slate-600">Aucune direction trouvee.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $rows->links() }}</div>
    </section>
@endsection
