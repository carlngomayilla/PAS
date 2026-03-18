@extends('layouts.workspace')

@section('content')
    @php
        $currentUser = auth()->user();
        $visibleRows = collect($rows->items());
        $draftCount = $visibleRows->where('statut', 'brouillon')->count();
        $validatedCount = $visibleRows->filter(fn ($item) => in_array((string) $item->statut, ['valide', 'verrouille'], true))->count();
        $axesTotal = (int) $visibleRows->sum('axes_count');
        $paoTotal = (int) $visibleRows->sum('paos_count');
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
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        Periode pluriannuelle
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-indigo-500"></span>
                        Axes strategiques
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-emerald-500"></span>
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

    <section class="showcase-summary-grid mb-4">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Total</p>
            <p class="showcase-kpi-number">{{ $rows->total() }}</p>
            <p class="showcase-kpi-meta">PAS dans le perimetre courant</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Brouillons visibles</p>
            <p class="showcase-kpi-number">{{ $draftCount }}</p>
            <p class="showcase-kpi-meta">Elements encore modifiables</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Valides / verrouilles</p>
            <p class="showcase-kpi-number">{{ $validatedCount }}</p>
            <p class="showcase-kpi-meta">Prets a alimenter les PAO</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Axes / PAO</p>
            <p class="showcase-kpi-number">{{ $axesTotal }} / {{ $paoTotal }}</p>
            <p class="showcase-kpi-meta">Axes visibles et declinaisons detectees</p>
        </article>
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
                            <option value="{{ $status }}" @selected($filters['statut'] === $status)>{{ $status }}</option>
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
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-blue" href="{{ route('workspace.pas.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="showcase-panel mb-4">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des PAS</h2>
                <p class="showcase-panel-subtitle">Lecture consolidee des periodes, axes, objectifs strategiques et volumes PAO par PAS.</p>
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
                                'verrouille' => 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900',
                                'valide' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
                                'soumis' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
                                default => 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
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
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses }}">
                                    {{ $row->statut }}
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
                                            <form method="POST" action="{{ route('workspace.pas.lock', $row) }}" onsubmit="return confirm('Verrouiller ce PAS ?')">
                                                @csrf
                                                <button class="btn btn-primary" type="submit">Verrouiller</button>
                                            </form>
                                        @endif
                                        @if (in_array($row->statut, ['soumis', 'valide'], true) && $currentUser->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_DG))
                                            <form method="POST" action="{{ route('workspace.pas.reopen', $row) }}" onsubmit="return requireWorkflowReason(this, 'PAS')">
                                                @csrf
                                                <input type="hidden" name="motif_retour" value="">
                                                <button class="btn btn-blue" type="submit">Retour brouillon</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('workspace.pas.destroy', $row) }}" onsubmit="return confirm('Supprimer ce PAS ?')">
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
        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    </section>
@endsection
