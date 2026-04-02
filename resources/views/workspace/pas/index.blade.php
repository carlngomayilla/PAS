@extends('layouts.workspace')

@section('content')
    @php
        $currentUser = auth()->user();
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $visibleRows = collect($rows->items());
        $draftCount = $visibleRows->where('statut', 'brouillon')->count();
        $validatedCount = $visibleRows->filter(fn ($item) => in_array((string) $item->statut, ['valide', 'verrouille'], true))->count();
        $axesTotal = (int) $visibleRows->sum('axes_count');
        $paoTotal = (int) $visibleRows->sum('paos_count');
        $tableLevel = in_array($filters['statut'] ?? null, ['valide', 'verrouille', 'valide_ou_verrouille'], true)
            ? ['label' => 'Valide', 'tone' => 'warning']
            : ['label' => 'Provisoire', 'tone' => 'info'];
        $tableLevelClass = $tableLevel['tone'] === 'warning' ? 'anbg-badge anbg-badge-warning' : 'anbg-badge anbg-badge-info';
        $summaryCards = [
            ['label' => 'Total', 'value' => $rows->total(), 'meta' => 'PAS dans le perimetre courant', 'href' => route('workspace.pas.index'), 'badge' => 'Provisoire', 'badge_tone' => 'info'],
            ['label' => 'Brouillons visibles', 'value' => $draftCount, 'meta' => 'Elements encore modifiables', 'href' => route('workspace.pas.index', ['statut' => 'brouillon']), 'badge' => 'Provisoire', 'badge_tone' => 'info'],
            ['label' => 'Valides / verrouilles', 'value' => $validatedCount, 'meta' => 'Prets a alimenter les PAO', 'href' => route('workspace.pas.index', ['statut' => 'valide_ou_verrouille']), 'badge' => 'Valide', 'badge_tone' => 'warning'],
            ['label' => 'Axes / PAO', 'value' => $axesTotal.' / '.$paoTotal, 'meta' => 'Axes visibles et declinaisons detectees', 'href' => route('workspace.pao.index'), 'badge' => 'Provisoire', 'badge_tone' => 'info'],
        ];
    @endphp

    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">Planification strategique</span>
                <h1 class="showcase-title">PAS</h1>
                <p class="showcase-subtitle">
                    Gestion de la vision strategique pluriannuelle, des axes, des objectifs strategiques et de la chaine PAS -> PAO.
                </p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#1E3A8A]"></span>
                        Periode pluriannuelle
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#3B82F6]"></span>
                        Axes strategiques
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#10B981]"></span>
                        Couverture par direction sur chaque OS
                    </span>
                </div>
            </div>
            <div class="showcase-action-row">
                @if ($canWrite)
                    <a class="btn btn-green" href="{{ route('workspace.pas.create') }}">Nouveau PAS</a>
                @endif
            </div>
        </div>
    </section>

    <div class="mb-4 flex flex-wrap gap-2">
        <span class="anbg-badge anbg-badge-info px-3 py-1">Provisoire</span>
        <span class="anbg-badge anbg-badge-warning px-3 py-1">Valide</span>
        <span class="anbg-badge anbg-badge-success px-3 py-1">Officiel</span>
    </div>

    <section class="showcase-summary-grid mb-4">
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

    <section class="showcase-toolbar mb-4">
        <div>
            <h2 class="showcase-panel-title">Filtres de lecture</h2>
            <p class="showcase-panel-subtitle">Recherche par titre, statut ou direction concernee.</p>
        </div>
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
                <div>
                    <label for="direction_id">Direction concernee</label>
                    <select id="direction_id" name="direction_id">
                        <option value="">Toutes</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected(($filters['direction_id'] ?? null) === (int) $direction->id)>
                                {{ $direction->code }} - {{ $direction->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if ($filters['without_pao'])
                <input type="hidden" name="without_pao" value="1">
            @endif
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-blue" href="{{ route('workspace.pas.index') }}">Reinitialiser</a>
            </div>
            @if ($filters['without_pao'])
                <div class="mt-4 showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#F59E0B]"></span>
                        Drill-down actif : PAS sans PAO
                    </span>
                </div>
            @endif
        </form>
    </section>

    <section class="showcase-panel mb-4">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des PAS</h2>
                <p class="showcase-panel-subtitle">Lecture consolidee des periodes, axes, objectifs strategiques et volumes PAO par PAS.</p>
                <div class="mt-2">
                    <span class="{{ $tableLevelClass }} px-3 py-1">{{ $tableLevel['label'] }}</span>
                </div>
            </div>
            <span class="showcase-chip">
                <span class="showcase-chip-dot bg-slate-500"></span>
                {{ $rows->count() }} ligne(s) sur cette page
            </span>
        </div>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Titre</th>
                        <th>Periode</th>
                        <th>Statut</th>
                        <th>Axes / OS / Directions</th>
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
                            <td>#{{ $row->id }}</td>
                            <td>
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ $row->titre }}</div>
                                <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $row->axes_count }} axe(s) strategique(s)</div>
                            </td>
                            <td>{{ $row->periode_debut }} - {{ $row->periode_fin }}</td>
                            <td>
                                <span class="{{ $statusClasses }}">
                                    {{ $workflowStatusLabel($row->statut) }}
                                </span>
                            </td>
                            <td>
                                <div class="mb-1">
                                    <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                        {{ $row->axes_count }} axe(s) · {{ $row->directions_count }} direction(s)
                                    </span>
                                </div>
                                @forelse ($row->axes as $axe)
                                    <div class="mb-2 rounded-xl border border-slate-200/85 bg-slate-50/80 px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-800/70">
                                        <div class="font-semibold text-slate-900 dark:text-slate-100">
                                            {{ $axe->code }} - {{ $axe->libelle }}
                                        </div>
                                        <div class="mt-1 text-slate-600 dark:text-slate-300">
                                            {{ $axe->objectifs->count() }} objectif(s) strategique(s)
                                        </div>
                                        <div class="mt-1 text-slate-500 dark:text-slate-400">
                                            @forelse ($axe->objectifs as $objectif)
                                                <span class="inline-flex rounded-full bg-white px-2 py-0.5 text-[11px] font-medium dark:bg-slate-900">
                                                    {{ $objectif->code ?: 'OS' }} - {{ $objectif->libelle }}
                                                </span>
                                            @empty
                                                -
                                            @endforelse
                                        </div>
                                    </div>
                                @empty
                                    <span class="text-slate-500 dark:text-slate-400">-</span>
                                @endforelse
                                @if ($row->directions->isNotEmpty())
                                    <div class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                        Directions attendues:
                                        {{ $row->directions->map(fn ($direction) => $direction->code)->implode(', ') }}
                                    </div>
                                @endif
                            </td>
                            <td>{{ $row->paos_count }}</td>
                            <td>{{ $row->validateur?->name ?? '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-amber" href="{{ route('workspace.pas.edit', $row) }}">Modifier</a>
                                        @if ($row->statut === 'brouillon')
                                            <form method="POST" action="{{ route('workspace.pas.submit', $row) }}">
                                                @csrf
                                                <button class="btn btn-blue" type="submit">Soumettre</button>
                                            </form>
                                        @endif
                                        @if ($row->statut === 'soumis' && $currentUser->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_DG))
                                            <form method="POST" action="{{ route('workspace.pas.approve', $row) }}">
                                                @csrf
                                                <button class="btn btn-green" type="submit">Valider</button>
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
                                                <button class="btn btn-blue" type="submit">Retour brouillon</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('workspace.pas.destroy', $row) }}" data-confirm-message="Supprimer ce PAS ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-red" type="submit">Supprimer</button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canWrite ? 8 : 7 }}" class="text-slate-500 dark:text-slate-400">Aucun PAS trouve.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $rows->links() }}</div>
    </section>
@endsection
