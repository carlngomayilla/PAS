@extends('layouts.workspace')

@section('title', 'Maintenance')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Maintenance legere</h1>
                <p class="mt-2 text-slate-600">Operations techniques encadrees : caches, vues compilees et mode maintenance. Aucun acces shell ni parametre critique n est expose.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.appearance.edit') }}">Apparence</a>
                <a class="btn btn-primary" href="{{ route('workspace.audit.index') }}">Audit</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Mode maintenance</p><p class="mt-2 text-2xl font-bold {{ $status['maintenance_active'] ? 'text-amber-600 dark:text-amber-300' : 'text-emerald-600 dark:text-emerald-300' }}">{{ $status['maintenance_active'] ? 'Actif' : 'Inactif' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Cache configuration</p><p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $status['config_cached'] ? 'Present' : 'Absent' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Cache routes</p><p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $status['routes_cached'] ? 'Present' : 'Absent' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Cache evenements</p><p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $status['events_cached'] ? 'Present' : 'Absent' }}</p></article>
    </section>

    <section class="ui-card mb-3.5">
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
            @foreach ($actions as $action => $label)
                <article class="ui-card !mb-0">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $label }}</p>
                    <p class="mt-2 text-sm text-slate-600">Action journalisee automatiquement dans le module `Super Administration`.</p>
                    <form method="POST" action="{{ route('workspace.super-admin.maintenance.run', $action) }}" class="mt-4">
                        @csrf
                        <button
                            class="btn {{ str_contains($action, 'maintenance_') ? 'btn-amber' : 'btn-primary' }}"
                            type="submit"
                            onclick="return confirm('Confirmer cette operation de maintenance ?');"
                        >
                            Executer
                        </button>
                    </form>
                </article>
            @endforeach
        </div>
    </section>

    <section class="ui-card">
        <h2>Historique recent</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Date</th>
                        <th class="px-3 py-2 text-left">Utilisateur</th>
                        <th class="px-3 py-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentAudits as $audit)
                        <tr>
                            <td class="px-3 py-2">{{ $audit->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">{{ $audit->user?->name ?? 'Systeme' }}</td>
                            <td class="px-3 py-2">{{ $audit->action }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-4 text-slate-500" colspan="3">Aucune action de maintenance journalisee.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

