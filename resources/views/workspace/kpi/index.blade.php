@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>KPI</h1>
        <p class="text-slate-600">Indicateurs de performance rattaches aux actions.</p>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" href="{{ route('workspace.kpi.create') }}">Nouveau KPI</a>
            </p>
        @endif
    </section>

    <section class="ui-card mb-3.5">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.kpi.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Libelle ou unite">
                </div>
                <div>
                    <label for="action_id">Action</label>
                    <select id="action_id" name="action_id">
                        <option value="">Toutes</option>
                        @foreach ($actionOptions as $action)
                            <option value="{{ $action->id }}" @selected($filters['action_id'] === $action->id)>
                                #{{ $action->id }} - {{ $action->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="periodicite">Periodicite</label>
                    <select id="periodicite" name="periodicite">
                        <option value="">Toutes</option>
                        @foreach ($periodiciteOptions as $periodicite)
                            <option value="{{ $periodicite }}" @selected($filters['periodicite'] === $periodicite)>{{ $periodicite }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-slate-900 text-white hover:bg-slate-800" type="submit">Appliquer</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.kpi.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Liste des KPI</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>KPI</th>
                        <th>Action</th>
                        <th>Periodicite</th>
                        <th>Cible</th>
                        <th>Seuil alerte</th>
                        <th>Mesures</th>
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
                                <strong>{{ $row->libelle }}</strong><br>
                                <span class="text-slate-600">{{ $row->unite ?: '-' }}</span>
                            </td>
                            <td>{{ $row->action?->libelle ?? '-' }}</td>
                            <td><span class="inline-block rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800">{{ $row->periodicite }}</span></td>
                            <td>{{ $row->cible ?? '-' }}</td>
                            <td>{{ $row->seuil_alerte ?? '-' }}</td>
                            <td>{{ $row->mesures_count }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-amber-700 text-white hover:bg-amber-600" href="{{ route('workspace.kpi.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.kpi.destroy', $row) }}" onsubmit="return confirm('Supprimer ce KPI ?')">
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
                            <td colspan="{{ $canWrite ? 8 : 7 }}" class="text-slate-600">Aucun KPI trouve.</td>
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

