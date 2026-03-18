@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>PAO - Objectifs strategiques</h1>
        <p class="text-slate-600">Objectifs strategiques annuels relies aux axes du PAO.</p>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" href="{{ route('workspace.pao-objectifs-strategiques.create') }}">Nouvel objectif strategique</a>
            </p>
        @endif
    </section>

    <section class="ui-card mb-3.5">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.pao-objectifs-strategiques.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Code, libelle, description">
                </div>
                <div>
                    <label for="pao_axe_id">Axe PAO</label>
                    <select id="pao_axe_id" name="pao_axe_id">
                        <option value="">Tous</option>
                        @foreach ($paoAxeOptions as $axe)
                            <option value="{{ $axe->id }}" @selected($filters['pao_axe_id'] === $axe->id)>
                                #{{ $axe->id }} - {{ $axe->code }} | {{ $axe->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.pao-objectifs-strategiques.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Liste des objectifs strategiques</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Objectif strategique</th>
                        <th>Axe / PAO</th>
                        <th>Echeance</th>
                        <th>Obj. operationnels</th>
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
                                {{ $row->paoAxe?->code ?? '-' }} - {{ $row->paoAxe?->libelle ?? '-' }}<br>
                                <span class="text-slate-600">{{ $row->paoAxe?->pao?->titre ?? '-' }}</span>
                            </td>
                            <td>{{ $row->echeance ?? '-' }}</td>
                            <td>{{ $row->objectifs_operationnels_count }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-amber-700 text-white hover:bg-amber-600" href="{{ route('workspace.pao-objectifs-strategiques.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.pao-objectifs-strategiques.destroy', $row) }}" onsubmit="return confirm('Supprimer cet objectif ?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-red btn-sm" type="submit">Supprimer</button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canWrite ? 7 : 6 }}" class="text-slate-600">Aucun objectif strategique PAO trouve.</td>
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
