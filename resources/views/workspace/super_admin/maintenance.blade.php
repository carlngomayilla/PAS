@extends('layouts.workspace')

@section('title', 'Maintenance')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Maintenance légère</h1>
                <p class="mt-2 text-slate-600">Opérations techniques encadrées : caches, vues compilées et mode maintenance. Aucun accès shell ni paramètre critique n'est exposé.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.appearance.edit') }}">Apparence</a>
                <a class="btn btn-primary" href="{{ route('workspace.audit.index') }}">Audit</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Mode maintenance</p><p class="mt-2 text-2xl font-bold {{ $status['maintenance_active'] ? 'text-amber-600' : 'text-emerald-600' }}">{{ $status['maintenance_active'] ? 'Actif' : 'Inactif' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Cache configuration</p><p class="mt-2 text-2xl font-bold text-slate-900">{{ $status['config_cached'] ? 'Présent' : 'Absent' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Cache routes</p><p class="mt-2 text-2xl font-bold text-slate-900">{{ $status['routes_cached'] ? 'Présent' : 'Absent' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Cache événements</p><p class="mt-2 text-2xl font-bold text-slate-900">{{ $status['events_cached'] ? 'Présent' : 'Absent' }}</p></article>
    </section>

    <section class="showcase-panel mb-4">
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
            @foreach ($actions as $action => $label)
                <article class="ui-card !mb-0">
                    <p class="text-sm font-semibold text-slate-900">{{ $label }}</p>
                    <p class="mt-2 text-sm text-slate-600">Action journalisée automatiquement dans le module `Super Administration`.</p>
                    <form method="POST" action="{{ route('workspace.super-admin.maintenance.run', $action) }}" class="mt-4">
                        @csrf
                        <button
                            class="btn {{ str_contains($action, 'maintenance_') ? 'btn-warning' : 'btn-primary' }}"
                            type="submit"
                            onclick="return confirm('Confirmer cette opération de maintenance ?');"
                        >
                            Exécuter
                        </button>
                    </form>
                </article>
            @endforeach
        </div>
    </section>

    <section class="ui-card">
        <h2>Historique récent</h2>
        <div class="app-table-wrapper mt-4">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Utilisateur</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentAudits as $audit)
                        <tr>
                            <td>{{ $audit->created_at?->format('Y-m-d H:i') }}</td>
                            <td>{{ $audit->user?->name ?? 'Système' }}</td>
                            <td>{{ $audit->action }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">
                                <x-ui.empty-state
                                    title="Aucune action de maintenance"
                                    message="Les opérations journalisées apparaîtront ici."
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
