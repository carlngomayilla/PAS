@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>PAS - Objectifs strategiques</h1>
        <p class="text-slate-600">Chaque axe strategique du PAS peut contenir plusieurs objectifs strategiques.</p>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" href="{{ route('workspace.pas-objectifs.create') }}">Nouvel objectif PAS</a>
            </p>
        @endif
    </section>

    <section class="ui-card mb-3.5">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.pas-objectifs.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Code, libelle, indicateur">
                </div>
                <div>
                    <label for="pas_axe_id">Axe PAS</label>
                    <select id="pas_axe_id" name="pas_axe_id">
                        <option value="">Tous</option>
                        @foreach ($pasAxeOptions as $axe)
                            <option value="{{ $axe->id }}" @selected($filters['pas_axe_id'] === $axe->id)>
                                #{{ $axe->id }} - {{ $axe->code }} | {{ $axe->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-slate-900 text-white hover:bg-slate-800" type="submit">Appliquer</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.pas-objectifs.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Liste des objectifs PAS</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Objectif strategique</th>
                        <th>Axe / PAS</th>
                        <th>Indicateur</th>
                        <th>Valeur cible</th>
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
                            <td>
                                <strong>{{ $row->libelle }}</strong><br>
                                <span class="text-slate-600">{{ $row->description ?: '-' }}</span>
                            </td>
                            <td>
                                {{ $row->pasAxe?->code ?? '-' }} - {{ $row->pasAxe?->libelle ?? '-' }}<br>
                                <span class="text-slate-600">{{ $row->pasAxe?->pas?->titre ?? '-' }}</span>
                            </td>
                            <td>{{ $row->indicateur_global ?: '-' }}</td>
                            <td>{{ $row->valeur_cible ?: '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-amber-700 text-white hover:bg-amber-600" href="{{ route('workspace.pas-objectifs.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.pas-objectifs.destroy', $row) }}" onsubmit="return confirm('Supprimer cet objectif ?')">
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
                            <td colspan="{{ $canWrite ? 7 : 6 }}" class="text-slate-600">Aucun objectif PAS trouve.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $rows->links() }}
        </div>
    </section>
@endsection
