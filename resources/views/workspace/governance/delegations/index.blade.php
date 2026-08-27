@extends('layouts.workspace')

@section('title', 'Délégations')

@section('content')
    @php
        $summary = is_array($summary ?? null) ? $summary : [];
        $filters = is_array($filters ?? null) ? $filters : [];
        $statusLabels = [
            'active' => 'Active',
            'scheduled' => 'Planifiée',
            'expired' => 'Échue',
            'cancelled' => 'Annulée',
        ];
        $statusClasses = [
            'active' => 'anbg-badge anbg-badge-success',
            'scheduled' => 'anbg-badge anbg-badge-info',
            'expired' => 'anbg-badge anbg-badge-warning',
            'cancelled' => 'anbg-badge anbg-badge-neutral',
        ];
        $permissionLabels = [
            'planning_read' => 'Lecture planning',
            'planning_write' => 'Écriture planning',
            'action_review' => 'Validation actions',
        ];
        $summaryCards = [
            ['label' => 'Total', 'value' => $summary['total'] ?? 0, 'status' => 'all'],
            ['label' => 'Actives', 'value' => $summary['active'] ?? 0, 'status' => 'active'],
            ['label' => 'Planifiées', 'value' => $summary['scheduled'] ?? 0, 'status' => 'scheduled'],
            ['label' => 'Fin sous 7 jours', 'value' => $summary['expires_soon'] ?? 0, 'status' => 'expiring'],
            ['label' => 'Échues', 'value' => $summary['expired'] ?? 0, 'status' => 'expired'],
            ['label' => 'Annulées', 'value' => $summary['cancelled'] ?? 0, 'status' => 'cancelled'],
        ];
    @endphp

    <div class="app-screen-flow">
        <section class="app-screen-block border-b border-slate-200 pb-4 dark:border-slate-700">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Référentiel / Gouvernance</p>
                    <h1 class="mt-1 text-2xl font-bold text-slate-950 dark:text-white">Délégations temporaires</h1>
                </div>
                <a class="btn btn-primary" href="{{ route('workspace.delegations.create') }}">Nouvelle délégation</a>
            </div>

            <nav class="mt-4 flex flex-wrap gap-2" aria-label="Gouvernance">
                <a class="btn btn-primary btn-sm" href="{{ route('workspace.delegations.index') }}" aria-current="page">Délégations</a>
                <a class="btn btn-secondary btn-sm" href="{{ route('workspace.deletion-requests.index') }}">Demandes de suppression</a>
            </nav>
        </section>

        <section class="showcase-summary-grid app-screen-kpis">
            @foreach ($summaryCards as $card)
                <x-stat-card-link
                    :href="route('workspace.delegations.index', ['status' => $card['status']])"
                    :label="$card['label']"
                    :value="$card['value']"
                    :meta="null"
                />
            @endforeach
        </section>

        <section class="ui-card app-screen-block">
            <form method="GET" action="{{ route('workspace.delegations.index') }}" class="grid gap-3 lg:grid-cols-[minmax(15rem,1fr)_repeat(4,minmax(9rem,auto))_auto] lg:items-end" data-auto-filter-form>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Recherche
                    <input name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Nom, email, périmètre, motif">
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Statut
                    <select name="status">
                        @foreach (['all' => 'Tous', 'active' => 'Actives', 'scheduled' => 'Planifiées', 'expiring' => 'Fin sous 7 jours', 'expired' => 'Échues', 'cancelled' => 'Annulées'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? 'all') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Portée
                    <select name="scope">
                        <option value="all">Toutes</option>
                        <option value="direction" @selected(($filters['scope'] ?? 'all') === 'direction')>Direction</option>
                        <option value="service" @selected(($filters['scope'] ?? 'all') === 'service')>Service</option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Permission
                    <select name="permission">
                        <option value="all">Toutes</option>
                        @foreach ($permissionLabels as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['permission'] ?? 'all') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Tri
                    <select name="sort">
                        <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>Plus récentes</option>
                        <option value="end_soon" @selected(($filters['sort'] ?? '') === 'end_soon')>Échéance proche</option>
                        <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Plus anciennes</option>
                    </select>
                </label>
                <div class="flex gap-2">
                    <a class="btn btn-secondary" href="{{ route('workspace.delegations.index') }}" title="Réinitialiser les filtres" aria-label="Réinitialiser les filtres">×</a>
                </div>
            </form>
        </section>

        <section class="ui-card app-screen-block">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-bold text-slate-950 dark:text-white">Registre des délégations</h2>
                <span class="text-sm text-slate-500">{{ $rows->total() }} résultat{{ $rows->total() > 1 ? 's' : '' }}</span>
            </div>

            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table min-w-[78rem]">
                    <thead>
                        <tr>
                            <th>Bénéficiaire</th>
                            <th>Délégant</th>
                            <th>Périmètre</th>
                            <th>Permissions</th>
                            <th>Période</th>
                            <th>Statut</th>
                            <th>Motif</th>
                            <th class="text-right">Opérations</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php($displayStatus = $row->displayStatus())
                            <tr id="delegation-{{ $row->id }}">
                                <td>
                                    <strong>{{ $row->delegue?->name ?? '-' }}</strong><br>
                                    <span class="text-xs text-slate-500">{{ $row->delegue?->email ?? '-' }}</span>
                                </td>
                                <td>
                                    <strong>{{ $row->delegant?->name ?? '-' }}</strong><br>
                                    <span class="text-xs text-slate-500">{{ $row->delegant?->roleLabel() ?? '-' }}</span>
                                </td>
                                <td>
                                    <span class="font-semibold">{{ $row->role_scope === 'service' ? 'Service' : 'Direction' }}</span><br>
                                    <span class="text-xs text-slate-500">
                                        {{ $row->direction?->code ?? '-' }}
                                        @if ($row->service)
                                            / {{ $row->service->code }}
                                        @endif
                                    </span>
                                </td>
                                <td>
                                    <div class="flex max-w-64 flex-wrap gap-1">
                                        @foreach ($row->permissions ?? [] as $permission)
                                            <span class="anbg-badge anbg-badge-neutral">{{ $permissionLabels[$permission] ?? $permission }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">
                                    <strong>{{ optional($row->date_debut)->format('d/m/Y H:i') ?: '-' }}</strong><br>
                                    <span class="text-xs text-slate-500">au {{ optional($row->date_fin)->format('d/m/Y H:i') ?: '-' }}</span>
                                </td>
                                <td>
                                    <span class="{{ $statusClasses[$displayStatus] ?? 'anbg-badge anbg-badge-neutral' }}">
                                        {{ $statusLabels[$displayStatus] ?? ucfirst($displayStatus) }}
                                    </span>
                                    @if ($row->annule_le)
                                        <div class="mt-1 text-xs text-slate-500">{{ $row->cancelledBy?->name ?? 'Système' }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="line-clamp-2 max-w-64" title="{{ $row->motif }}">{{ $row->motif }}</span>
                                    @if ($row->motif_annulation)
                                        <div class="mt-1 text-xs text-rose-700 dark:text-rose-300">{{ $row->motif_annulation }}</div>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if ($row->canBeCancelled())
                                        <details class="relative inline-block text-left">
                                            <summary class="btn btn-danger btn-sm cursor-pointer list-none">Annuler</summary>
                                            <form method="POST" action="{{ route('workspace.delegations.cancel', $row) }}" class="absolute right-0 z-20 mt-2 grid w-80 gap-2 rounded-lg border border-slate-200 bg-white p-3 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                                                @csrf
                                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                                                    Motif d’annulation
                                                    <textarea name="motif_annulation" rows="3" minlength="5" maxlength="1000" required></textarea>
                                                </label>
                                                <button class="btn btn-danger btn-sm" type="submit">Confirmer l’annulation</button>
                                            </form>
                                        </details>
                                    @else
                                        <span class="text-slate-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <x-ui.empty-state
                                        title="Aucune délégation trouvée"
                                        message="Aucun enregistrement ne correspond aux critères sélectionnés."
                                        icon="users"
                                        tone="info"
                                        class="my-4"
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination">{{ $rows->links() }}</div>
        </section>
    </div>
@endsection
