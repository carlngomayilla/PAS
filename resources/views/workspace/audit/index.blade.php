@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>Journal d'audit</h1>
        <p class="text-slate-600">Trace des operations effectuees dans l'application.</p>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.audit.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="module">Module</label>
                    <input id="module" name="module" type="text" value="{{ $filters['module'] }}">
                </div>
                <div>
                    <label for="action">Action</label>
                    <input id="action" name="action" type="text" value="{{ $filters['action'] }}">
                </div>
                <div>
                    <label for="user_id">User ID</label>
                    <input id="user_id" name="user_id" type="number" value="{{ $filters['user_id'] }}">
                </div>
                <div>
                    <label for="entite_type">Type d'entite</label>
                    <input id="entite_type" name="entite_type" type="text" value="{{ $filters['entite_type'] }}">
                </div>
                <div>
                    <label for="entite_id">ID entite</label>
                    <input id="entite_id" name="entite_id" type="number" value="{{ $filters['entite_id'] }}">
                </div>
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}">
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-blue" href="{{ route('workspace.audit.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Historique</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Utilisateur</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Entite</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td>{{ $log->id }}</td>
                            <td>{{ $log->created_at }}</td>
                            <td>{{ $log->user?->email ?? '-' }}</td>
                            <td>{{ $log->module }}</td>
                            <td><span class="anbg-badge anbg-badge-neutral px-3">{{ $log->action }}</span></td>
                            <td>{{ class_basename($log->entite_type) }} #{{ $log->entite_id }}</td>
                            <td>{{ $log->adresse_ip ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-slate-600">Aucune entree d'audit.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $logs->links() }}</div>
    </section>
@endsection
