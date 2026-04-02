@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>Indicateurs</h1>
        <p class="text-slate-600">Indicateurs de performance rattaches aux actions.</p>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="btn btn-primary" href="{{ route('workspace.kpi.create') }}">Nouvel indicateur</a>
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
                <div>
                    <label for="est_a_renseigner">Mode de saisie</label>
                    <select id="est_a_renseigner" name="est_a_renseigner">
                        <option value="">Tous</option>
                        @foreach ($modeSaisieOptions as $value => $label)
                            <option value="{{ $value }}" @selected((string) $filters['est_a_renseigner'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-secondary" href="{{ route('workspace.kpi.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Liste des indicateurs</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Indicateur</th>
                        <th>Action</th>
                        <th>Periodicite</th>
                        <th>Mode saisie</th>
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
                            <td>
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $row->est_a_renseigner ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' }}">
                                    {{ $row->mode_saisie_label }}
                                </span>
                            </td>
                            <td>{{ $row->cible ?? '-' }}</td>
                            <td>{{ $row->seuil_alerte ?? '-' }}</td>
                            <td>{{ $row->est_a_renseigner ? $row->mesures_count : '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-[#f9b13c] text-white hover:brightness-105" href="{{ route('workspace.kpi.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.kpi.destroy', $row) }}" data-confirm-message="Supprimer cet indicateur ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
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
                            <td colspan="{{ $canWrite ? 9 : 8 }}" class="text-slate-600">Aucun indicateur trouve.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $rows->links() }}</div>
    </section>
@endsection
