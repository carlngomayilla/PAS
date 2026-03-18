@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>PAS - Axes strategiques</h1>
        <p class="text-slate-600">Structuration des axes strategiques rattaches a un PAS pluriannuel.</p>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" href="{{ route('workspace.pas-axes.create') }}">Nouvel axe PAS</a>
            </p>
        @endif
    </section>

    <section class="ui-card mb-3.5">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.pas-axes.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Code, libelle, description">
                </div>
                <div>
                    <label for="pas_id">PAS</label>
                    <select id="pas_id" name="pas_id">
                        <option value="">Tous</option>
                        @foreach ($pasOptions as $pas)
                            <option value="{{ $pas->id }}" @selected($filters['pas_id'] === $pas->id)>
                                #{{ $pas->id }} - {{ $pas->titre }} ({{ $pas->periode_debut }}-{{ $pas->periode_fin }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-slate-900 text-white hover:bg-slate-800" type="submit">Appliquer</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.pas-axes.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Liste des axes PAS</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Libelle</th>
                        <th>PAS</th>
                        <th>Ordre</th>
                        <th>Objectifs</th>
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
                                {{ $row->pas?->titre ?? '-' }}<br>
                                <span class="text-slate-600">{{ $row->pas?->statut ?? '' }}</span>
                            </td>
                            <td>{{ $row->ordre }}</td>
                            <td>{{ $row->objectifs_count }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-amber-700 text-white hover:bg-amber-600" href="{{ route('workspace.pas-axes.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.pas-axes.destroy', $row) }}" onsubmit="return confirm('Supprimer cet axe ?')">
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
                            <td colspan="{{ $canWrite ? 7 : 6 }}" class="text-slate-600">Aucun axe PAS trouve.</td>
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
