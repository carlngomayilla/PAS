@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>KPI - Mesures</h1>
        <p class="text-slate-600">Saisie periodique des valeurs mesurees pour les indicateurs.</p>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" href="{{ route('workspace.kpi-mesures.create') }}">Nouvelle mesure KPI</a>
            </p>
        @endif
    </section>

    <section class="ui-card mb-3.5">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.kpi-mesures.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Periode ou commentaire">
                </div>
                <div>
                    <label for="kpi_id">KPI</label>
                    <select id="kpi_id" name="kpi_id">
                        <option value="">Tous</option>
                        @foreach ($kpiOptions as $kpi)
                            <option value="{{ $kpi->id }}" @selected($filters['kpi_id'] === $kpi->id)>
                                #{{ $kpi->id }} - {{ $kpi->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="periode">Periode</label>
                    <input id="periode" name="periode" type="text" value="{{ $filters['periode'] }}" placeholder="Ex: 2026-01">
                </div>
                <div>
                    <label for="saisi_par">Saisi par</label>
                    <select id="saisi_par" name="saisi_par">
                        <option value="">Tous</option>
                        @foreach ($saisiParOptions as $user)
                            <option value="{{ $user->id }}" @selected($filters['saisi_par'] === $user->id)>
                                {{ $user->name }} ({{ $user->email }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.kpi-mesures.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Liste des mesures KPI</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>KPI</th>
                        <th>Periode</th>
                        <th>Valeur</th>
                        <th>Saisi par</th>
                        <th>Commentaire</th>
                        @if ($canWrite)
                            <th>Operations</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->id }}</td>
                            <td>
                                <strong>{{ $row->kpi?->libelle ?? '-' }}</strong><br>
                                <span class="text-slate-600">{{ $row->kpi?->periodicite ?? '-' }}</span>
                            </td>
                            <td>{{ $row->periode }}</td>
                            <td>{{ $row->valeur }}</td>
                            <td>{{ $row->saisiPar?->name ?? '-' }}</td>
                            <td>{{ $row->commentaire ?: '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-[#f9b13c] text-white hover:brightness-105" href="{{ route('workspace.kpi-mesures.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.kpi-mesures.destroy', $row) }}" data-confirm-message="Supprimer cette mesure KPI ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
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
                            <td colspan="{{ $canWrite ? 7 : 6 }}" class="text-slate-600">Aucune mesure KPI trouvee.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $rows->links() }}</div>
    </section>
@endsection
