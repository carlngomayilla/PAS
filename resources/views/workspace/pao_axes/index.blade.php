@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>PAO - Axes strategiques</h1>
        <p class="text-slate-600">Declinaison annuelle des axes du PAO attribue a une direction precise.</p>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="btn btn-green" href="{{ route('workspace.pao-axes.create') }}">Nouvel axe PAO</a>
            </p>
        @endif
    </section>

    <section class="ui-card mb-3.5">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.pao-axes.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Code, libelle, description">
                </div>
                <div>
                    <label for="pao_id">PAO</label>
                    <select id="pao_id" name="pao_id">
                        <option value="">Tous</option>
                        @foreach ($paoOptions as $pao)
                            <option value="{{ $pao->id }}" @selected($filters['pao_id'] === $pao->id)>
                                #{{ $pao->id }} - {{ $pao->titre }} ({{ $pao->annee }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-blue" href="{{ route('workspace.pao-axes.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Liste des axes PAO</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Libelle</th>
                        <th>PAO</th>
                        <th>Ordre</th>
                        <th>Obj. strategiques</th>
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
                                {{ $row->pao?->titre ?? '-' }}<br>
                                <span class="text-slate-600">{{ $row->pao?->annee ?? '-' }} | {{ $row->pao?->statut ?? '' }}</span>
                            </td>
                            <td>{{ $row->ordre }}</td>
                            <td>{{ $row->objectifs_strategiques_count }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="btn btn-amber" href="{{ route('workspace.pao-axes.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.pao-axes.destroy', $row) }}" onsubmit="return confirm('Supprimer cet axe ?')">
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
                            <td colspan="{{ $canWrite ? 7 : 6 }}" class="text-slate-600">Aucun axe PAO trouve.</td>
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
