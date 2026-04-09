@extends('layouts.workspace')

@section('title', 'Sauvegarde et restauration')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Sauvegarde et restauration</h1>
                <p class="mt-2 text-slate-600">Snapshots complets des configurations stockees dans `platform_settings`, avec restauration tracee et reversible.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.audit.index') }}">Audit</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Snapshots</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['snapshots_total'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Clefs configurees</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['settings_total'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Derniere restauration</p><p class="mt-2 text-xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['last_restored_at'] ? \Illuminate\Support\Carbon::parse($summary['last_restored_at'])->format('Y-m-d H:i') : 'Aucune' }}</p></article>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" action="{{ route('workspace.super-admin.snapshots.store') }}" class="form-shell">
            @csrf
            <div class="form-section">
                <h2 class="form-section-title">Nouveau snapshot</h2>
                <div class="form-grid">
                    <div>
                        <label for="label">Libelle</label>
                        <input id="label" name="label" type="text" value="{{ old('label') }}" required>
                    </div>
                    <div>
                        <label for="description">Description</label>
                        <input id="description" name="description" type="text" value="{{ old('description') }}">
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Creer le snapshot</button>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <form method="GET" action="{{ route('workspace.super-admin.snapshots.index') }}" class="form-shell">
            <div class="form-section">
                <h2 class="form-section-title">Comparer deux snapshots</h2>
                <div class="form-grid">
                    <div>
                        <label for="compare_left">Snapshot de reference</label>
                        <select id="compare_left" name="compare_left">
                            <option value="">Choisir</option>
                            @foreach ($allSnapshots as $snapshot)
                                <option value="{{ $snapshot->id }}" @selected($compareLeftId === $snapshot->id)>{{ $snapshot->label }} (#{{ $snapshot->id }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="compare_right">Snapshot a comparer</label>
                        <select id="compare_right" name="compare_right">
                            <option value="">Choisir</option>
                            @foreach ($allSnapshots as $snapshot)
                                <option value="{{ $snapshot->id }}" @selected($compareRightId === $snapshot->id)>{{ $snapshot->label }} (#{{ $snapshot->id }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Comparer</button>
            </div>
        </form>
    </section>

    @if ($compare)
        <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Modifiees</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $compare['summary']['changed'] }}</p></article>
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Ajoutees</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $compare['summary']['added'] }}</p></article>
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Supprimees</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $compare['summary']['removed'] }}</p></article>
        </section>

        <section class="ui-card mb-3.5">
            <h2>Differences detectees</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>Groupe</th><th>Cle</th><th>Reference</th><th>Comparaison</th><th>Statut</th></tr></thead>
                    <tbody>
                        @forelse ($compare['changes'] as $change)
                            <tr>
                                <td>{{ $change['group'] }}</td>
                                <td><code>{{ $change['key'] }}</code></td>
                                <td class="text-xs text-slate-500">{{ \Illuminate\Support\Str::limit((string) ($change['left_value'] ?? 'null'), 90) }}</td>
                                <td class="text-xs text-slate-500">{{ \Illuminate\Support\Str::limit((string) ($change['right_value'] ?? 'null'), 90) }}</td>
                                <td>
                                    <span class="anbg-badge {{ $change['status'] === 'changed' ? 'anbg-badge-warning' : ($change['status'] === 'added' ? 'anbg-badge-success' : 'anbg-badge-danger') }} px-3">
                                        {{ $change['status'] === 'changed' ? 'Modifiee' : ($change['status'] === 'added' ? 'Ajoutee' : 'Supprimee') }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-slate-500">Aucune difference entre les deux snapshots.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="ui-card">
        <h2>Historique des snapshots</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Date</th>
                        <th class="px-3 py-2 text-left">Libelle</th>
                        <th class="px-3 py-2 text-left">Cree par</th>
                        <th class="px-3 py-2 text-left">Derniere restauration</th>
                        <th class="px-3 py-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-3 py-2">{{ $row->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">
                                <strong class="text-slate-900 dark:text-slate-100">{{ $row->label }}</strong>
                                @if ($row->description)
                                    <div class="text-slate-500">{{ $row->description }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $row->creator?->name ?? 'Systeme' }}</td>
                            <td class="px-3 py-2">{{ $row->last_restored_at?->format('Y-m-d H:i') ?? 'Jamais' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('workspace.super-admin.snapshots.restore', $row) }}">
                                        @csrf
                                        <button class="btn btn-secondary" type="submit" onclick="return confirm('Restaurer ce snapshot de configuration ?');">Restaurer</button>
                                    </form>
                                    <details class="rounded-xl border border-slate-200/80 px-3 py-2 dark:border-slate-700">
                                        <summary class="cursor-pointer text-sm font-medium text-slate-700 dark:text-slate-200">Restauration partielle</summary>
                                        <form method="POST" action="{{ route('workspace.super-admin.snapshots.restore', $row) }}" class="mt-3 space-y-2">
                                            @csrf
                                            <input type="hidden" name="partial_restore" value="1">
                                            @foreach (collect($row->payload['settings'] ?? [])->pluck('group')->filter()->unique()->sort()->values() as $group)
                                                <label class="checkbox-pill flex !w-full justify-start !mb-0">
                                                    <input type="checkbox" name="groups[]" value="{{ $group }}">
                                                    {{ $group }}
                                                </label>
                                            @endforeach
                                            <button class="btn btn-secondary" type="submit" onclick="return confirm('Restaurer uniquement les groupes coches ?');">Restaurer groupes</button>
                                        </form>
                                    </details>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-4 text-slate-500" colspan="5">Aucun snapshot de configuration disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    </section>
@endsection

