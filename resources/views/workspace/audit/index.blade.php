@extends('layouts.workspace')

@section('content')
    <div class="app-screen-flow">
    <section class="ui-card mb-3.5 app-screen-block">
        <h1>Journal d'audit</h1>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5 app-screen-kpis">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Entrees filtrees</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['total'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Utilisateurs distincts</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['distinct_users'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Actions Super Admin</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['super_admin_actions'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Actions sensibles</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['sensitive_actions'] }}</p></article>
    </section>

    <section class="ui-card mb-3.5 app-screen-block">
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
                <div>
                    <label for="date_from">Date debut</label>
                    <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}">
                </div>
                <div>
                    <label for="date_to">Date fin</label>
                    <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}">
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-blue" href="{{ route('workspace.audit.index') }}">Reinitialiser</a>
                <a class="btn btn-secondary" href="{{ route('workspace.audit.export', request()->query()) }}">Exporter CSV</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5 app-screen-block">
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
                        <th>Delta</th>
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
                            <td>
                                @php
                                    $before = is_array($log->ancienne_valeur) ? count($log->ancienne_valeur) : 0;
                                    $after = is_array($log->nouvelle_valeur) ? count($log->nouvelle_valeur) : 0;
                                @endphp
                                <span class="text-xs text-slate-500">{{ $before }} -> {{ $after }}</span>
                            </td>
                            <td>{{ $log->adresse_ip ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-slate-600">Aucune entree d'audit.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $logs->links() }}</div>
    </section>
    </div>
@endsection
