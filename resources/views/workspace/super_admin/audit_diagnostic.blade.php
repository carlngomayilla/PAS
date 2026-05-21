@extends('layouts.workspace')

@section('title', 'Audit et diagnostic')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Audit et diagnostic</h1>
                <p class="mt-2 text-slate-600">Vue renforcée des changements sensibles et contrôles de cohérence sur les données de pilotage et de suivi.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.audit.index') }}">Journal complet</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Logs total</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['logs_total'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Dernières 24h</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['logs_last_24h'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Actions Super Admin</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['super_admin_changes'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Changements sensibles</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['sensitive_changes'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Actions organisation</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['organization_actions'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Modules touchés</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['modules_touched'] }}</p></article>
    </section>

    <section class="showcase-panel mb-4">
        <h2>Contrôle de cohérence</h2>
        <div class="app-table-wrapper mt-4">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Contrôle</th>
                        <th class="px-3 py-2 text-left">Résultat</th>
                        <th class="px-3 py-2 text-left">Diagnostic</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($checks as $check)
                        <tr>
                            <td class="px-3 py-3 font-medium text-slate-900">{{ $check['label'] }}</td>
                            <td class="px-3 py-3">
                                <span class="anbg-badge {{ $check['status'] === 'ok' ? 'anbg-badge-success' : ($check['status'] === 'warning' ? 'anbg-badge-warning' : 'anbg-badge-info') }} px-3">
                                    {{ $check['count'] }}
                                </span>
                            </td>
                            <td class="px-3 py-3 text-slate-600">{{ $check['recommendation'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="ui-card">
        <h2>Dernières opérations sensibles</h2>
        <div class="app-table-wrapper mt-4">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Date</th>
                        <th class="px-3 py-2 text-left">Utilisateur</th>
                        <th class="px-3 py-2 text-left">Module</th>
                        <th class="px-3 py-2 text-left">Action</th>
                        <th class="px-3 py-2 text-left">IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentAudits as $audit)
                        <tr>
                            <td class="px-3 py-2">{{ $audit->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">{{ $audit->user?->email ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $audit->module }}</td>
                            <td class="px-3 py-2">{{ $audit->action }}</td>
                            <td class="px-3 py-2">{{ $audit->adresse_ip ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state
                                    title="Aucune opération journalisée"
                                    message="Les opérations sensibles apparaîtront ici dès qu'elles seront disponibles."
                                    icon="clock"
                                    tone="info"
                                    class="my-4"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
