@extends('layouts.workspace')

@section('title', 'Délégations')

@section('content')
    @php
        $delegationStatusLabel = static fn (string $status): string => \App\Support\UiLabel::delegationStatus($status);
        $delegationStatusBadges = [
            'active' => 'anbg-badge anbg-badge-success',
            'cancelled' => 'anbg-badge anbg-badge-neutral',
            'expired' => 'anbg-badge anbg-badge-warning',
        ];
        $summary = is_array($summary ?? null) ? $summary : [];
        $summaryCards = [
            ['label' => 'Délégations', 'value' => $summary['total'] ?? $rows->total(), 'href' => route('workspace.delegations.index')],
            ['label' => 'Actives', 'value' => $summary['active'] ?? 0, 'href' => route('workspace.delegations.index')],
            ['label' => 'Fin < 7 jours', 'value' => $summary['expires_soon'] ?? 0, 'href' => route('workspace.delegations.index')],
            ['label' => 'Annulées', 'value' => $summary['cancelled'] ?? 0, 'href' => route('workspace.delegations.index')],
            ['label' => 'Périmètres direction', 'value' => $summary['direction_scope'] ?? 0, 'href' => route('workspace.delegations.index')],
            ['label' => 'Périmètres service', 'value' => $summary['service_scope'] ?? 0, 'href' => route('workspace.delegations.index')],
        ];
    @endphp
    <div class="app-screen-flow">
    <section class="showcase-summary-grid mb-4 app-screen-kpis">
        @foreach ($summaryCards as $card)
            <x-stat-card-link :href="$card['href']" :label="$card['label']" :value="$card['value']" :meta="null" />
        @endforeach
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1>Délégations temporaires</h1>
            </div>
            <a class="btn btn-primary" href="{{ route('workspace.delegations.create') }}">Nouvelle délégation</a>
        </div>
    </section>

    <section class="ui-card app-screen-block">
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Délégant</th>
                        <th>Délégué</th>
                        <th>Portée</th>
                        <th>Permissions</th>
                        <th>Période</th>
                        <th>Statut</th>
                        <th>Opérations</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->id }}</td>
                            <td>
                                <strong>{{ $row->delegant?->name ?? '-' }}</strong><br>
                                <span class="text-xs text-slate-500">{{ $row->delegant?->roleLabel() ?? '-' }}</span>
                            </td>
                            <td>
                                <strong>{{ $row->delegue?->name ?? '-' }}</strong><br>
                                <span class="text-xs text-slate-500">{{ $row->delegue?->roleLabel() ?? '-' }}</span>
                            </td>
                            <td>
                                {{ strtoupper($row->role_scope) }}<br>
                                <span class="text-xs text-slate-500">
                                    {{ $row->direction?->code ?? '-' }}
                                    @if ($row->service)
                                        / {{ $row->service->code }}
                                    @endif
                                </span>
                            </td>
                            <td>{{ implode(', ', $row->permissions ?? []) ?: '-' }}</td>
                            <td>
                                {{ optional($row->date_debut)->format('Y-m-d H:i') ?: '-' }}<br>
                                {{ optional($row->date_fin)->format('Y-m-d H:i') ?: '-' }}
                            </td>
                            <td>
                                <span class="{{ $delegationStatusBadges[$row->statut] ?? 'anbg-badge anbg-badge-neutral' }} px-3">
                                    {{ $delegationStatusLabel($row->statut) }}
                                </span>
                            </td>
                            <td>
                                @if ($row->statut === 'active')
                                    <form method="POST" action="{{ route('workspace.delegations.cancel', $row) }}" data-confirm-message="Annuler cette delegation ?" data-confirm-tone="danger" data-confirm-label="Annuler">
                                        @csrf
                                        <input type="hidden" name="motif_annulation" value="Annulation administrative">
                                        <button class="btn btn-primary" type="submit">Annuler</button>
                                    </form>
                                @else
                                    <span class="text-slate-500">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-ui.empty-state
                                    title="Aucune délégation enregistrée"
                                    message="Les délégations temporaires apparaîtront ici après création."
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
