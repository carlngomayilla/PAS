@extends('layouts.workspace')

@section('content')
    @php
        $currentUser = auth()->user();
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $ps = is_array($pasStats ?? null) ? $pasStats : [];
        $summaryCards = [
            ['label' => 'Actifs',                   'value' => $ps['actifs'] ?? 0,                'meta' => null, 'href' => route('workspace.pas.index', ['statut' => 'valide_ou_verrouille']), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Brouillons',               'value' => $ps['brouillons'] ?? 0,            'meta' => null, 'href' => route('workspace.pas.index', ['statut' => 'brouillon']),  'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Objectifs stratégiques',   'value' => $ps['objectifs_total'] ?? 0,       'meta' => null, 'href' => route('workspace.pao.index'),                             'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Sans PAO associé',         'value' => $ps['sans_pao'] ?? 0,              'meta' => null, 'href' => route('workspace.pas.index', ['without_pao' => 1]),       'badge' => null, 'badge_tone' => ($ps['sans_pao'] ?? 0) > 0 ? 'warning' : 'neutral'],
        ];
    @endphp

    <div class="app-screen-flow">
    <x-ui.page-title
        title="Pilotage stratégique"
        subtitle="Suivi des plans stratégiques, des axes et des objectifs institutionnels."
    >
        <x-slot:actions>
            @if ($canWrite)
                <a class="btn btn-primary" href="{{ route('workspace.pas.create') }}">Nouveau PAS</a>
            @endif
        </x-slot:actions>
    </x-ui.page-title>

    <section class="showcase-summary-grid mb-4 app-screen-kpis">
        @foreach ($summaryCards as $card)
            <x-stat-card-link
                :href="$card['href']"
                :label="$card['label']"
                :value="$card['value']"
                :meta="$card['meta']"
                :badge="$card['badge']"
                :badge-tone="$card['badge_tone']"
            />
        @endforeach
    </section>

    <section class="showcase-toolbar mb-4 app-screen-block">
        <div><h2 class="showcase-panel-title">Filtres</h2></div>
        <form method="GET" action="{{ route('workspace.pas.index') }}" class="mt-4">
            <div class="showcase-filter-grid">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Titre du PAS">
                </div>
                <div>
                    <label for="statut">Statut</label>
                    <select id="statut" name="statut">
                        <option value="">Tous</option>
                        @foreach ($statusOptions as $status)
                            <option value="{{ $status }}" @selected($filters['statut'] === $status)>{{ $workflowStatusLabel($status) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if ($filters['without_pao'])
                <input type="hidden" name="without_pao" value="1">
            @endif
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-secondary" href="{{ route('workspace.pas.index') }}">Réinitialiser</a>
            </div>
            @if ($filters['without_pao'])
                <div class="mt-4 rounded-[1rem] border border-amber-200/80 bg-amber-50/90 px-4 py-3 text-sm font-medium text-amber-900">
                    PAS sans PAO
                </div>
            @endif
        </form>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des PAS</h2>
            </div>
            <span class="text-sm font-medium text-slate-500">{{ $rows->count() }} ligne(s)</span>
        </div>
        <div class="app-table-wrapper">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>Titre</th>
                        <th>Période</th>
                        <th>Statut</th>
                        <th>Axes</th>
                        <th>PAO</th>
                        <th>Validateur</th>
                        @if ($canWrite)
                            <th>Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $statusClasses = match ((string) $row->statut) {
                                'brouillon' => 'anbg-badge anbg-badge-neutral',
                                'soumis' => 'anbg-badge anbg-badge-warning',
                                'valide' => 'anbg-badge anbg-badge-success',
                                'verrouille' => 'anbg-badge anbg-badge-info',
                                default => 'anbg-badge anbg-badge-neutral',
                            };
                        @endphp
                        <tr>
                            <td>
                                <div class="font-semibold text-slate-900">{{ $row->titre }}</div>
                                <div class="mt-1 text-xs text-slate-500">PAS #{{ $row->id }}</div>
                            </td>
                            <td>{{ $row->periode_debut }} - {{ $row->periode_fin }}</td>
                            <td>
                                <span class="{{ $statusClasses }}">
                                    {{ $workflowStatusLabel($row->statut) }}
                                </span>
                            </td>
                            <td>
                                <span class="anbg-badge anbg-badge-neutral px-3">
                                    {{ $row->axes_count }} axe(s)
                                </span>
                                <p class="mt-2 text-xs text-slate-500">
                                    {{ $row->axes->sum(fn ($axe) => $axe->objectifs->count()) }} objectif(s) stratégique(s)
                                </p>
                            </td>
                            <td>{{ $row->paos_count }}</td>
                            <td>{{ $row->validateur?->name ?? '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-warning" href="{{ route('workspace.pas.edit', $row) }}">Modifier</a>
                                        @if ($row->statut === 'brouillon')
                                            <form method="POST" action="{{ route('workspace.pas.submit', $row) }}">
                                                @csrf
                                                <button class="btn btn-primary" type="submit">Soumettre</button>
                                            </form>
                                        @endif
                                        @if ($row->statut === 'soumis' && $currentUser->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_DG))
                                            <form method="POST" action="{{ route('workspace.pas.approve', $row) }}">
                                                @csrf
                                                <button class="btn btn-success" type="submit">Valider</button>
                                            </form>
                                        @endif
                                        @if ($row->statut === 'valide' && $currentUser->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_DG))
                                            <form method="POST" action="{{ route('workspace.pas.lock', $row) }}" data-confirm-message="Verrouiller ce PAS ?" data-confirm-tone="warning" data-confirm-label="Verrouiller">
                                                @csrf
                                                <button class="btn btn-primary" type="submit">Verrouiller</button>
                                            </form>
                                        @endif
                                        @if (in_array($row->statut, ['soumis', 'valide'], true) && $currentUser->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_DG))
                                            <form method="POST" action="{{ route('workspace.pas.reopen', $row) }}" data-prompt-title="Retour brouillon" data-prompt-message="Saisir le motif de retour brouillon (PAS)." data-prompt-label="Motif de retour" data-prompt-placeholder="Minimum 5 caracteres" data-prompt-target="motif_retour" data-prompt-minlength="5" data-prompt-confirm="Confirmer">
                                                @csrf
                                                <input type="hidden" name="motif_retour" value="">
                                                <button class="btn btn-warning" type="submit">Retour brouillon</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('workspace.pas.destroy', $row) }}" data-confirm-message="Supprimer ce PAS ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-danger" type="submit">Supprimer</button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canWrite ? 7 : 6 }}">
                                <x-ui.empty-state
                                    title="Aucun PAS trouvé"
                                    message="Aucun plan stratégique ne correspond aux filtres courants."
                                    icon="filter"
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
