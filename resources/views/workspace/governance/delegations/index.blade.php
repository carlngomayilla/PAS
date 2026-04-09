@extends('layouts.workspace')

@section('title', 'Delegations')

@section('content')
    @php
        $delegationStatusLabel = static fn (string $status): string => \App\Support\UiLabel::delegationStatus($status);
        $delegationStatusBadges = [
            'active' => 'anbg-badge anbg-badge-success',
            'cancelled' => 'anbg-badge anbg-badge-neutral',
            'expired' => 'anbg-badge anbg-badge-warning',
        ];
    @endphp
    <div class="app-screen-flow">
    <section class="ui-card mb-3.5 app-screen-block">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1>Delegations temporaires</h1>
            </div>
            <a class="btn btn-blue" href="{{ route('workspace.delegations.create') }}">Nouvelle delegation</a>
        </div>
    </section>

    <section class="ui-card app-screen-block">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Delegant</th>
                        <th>Delegue</th>
                        <th>Portee</th>
                        <th>Permissions</th>
                        <th>Periode</th>
                        <th>Statut</th>
                        <th>Action</th>
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
                            <td colspan="8" class="text-slate-600">Aucune delegation enregistree.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pagination">{{ $rows->links() }}</div>
    </section>
    </div>
@endsection
