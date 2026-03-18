@extends('layouts.workspace')

@section('content')
    @php
        $currentUser = auth()->user();
        $visibleRows = collect($rows->items());
        $draftCount = $visibleRows->where('statut', 'brouillon')->count();
        $validatedCount = $visibleRows->filter(fn ($item) => in_array((string) $item->statut, ['valide', 'verrouille'], true))->count();
        $ptaTotal = (int) $visibleRows->sum('ptas_count');
        $directionsCovered = $visibleRows->pluck('direction_id')->filter()->unique()->count();
    @endphp

    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">Declinaison annuelle</span>
                <h1 class="showcase-title">PAO</h1>
                <p class="showcase-subtitle">
                    Declinaison annuelle des objectifs strategiques du PAS par direction avec couverture attendue sur chaque OS.
                </p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        Rattachement obligatoire a un OS
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-indigo-500"></span>
                        Axe et PAS derives
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-emerald-500"></span>
                        Alimente les PTA
                    </span>
                </div>
            </div>
            <div class="showcase-action-row">
                @if ($canWrite)
                    <a class="btn btn-green" href="{{ route('workspace.pao.create') }}">Nouveau PAO</a>
                @endif
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Total</p>
            <p class="showcase-kpi-number">{{ $rows->total() }}</p>
            <p class="showcase-kpi-meta">PAO dans le perimetre courant</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Brouillons visibles</p>
            <p class="showcase-kpi-number">{{ $draftCount }}</p>
            <p class="showcase-kpi-meta">Elements encore en construction</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Valides / verrouilles</p>
            <p class="showcase-kpi-number">{{ $validatedCount }}</p>
            <p class="showcase-kpi-meta">PAO prets a alimenter les services</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">PTA / directions</p>
            <p class="showcase-kpi-number">{{ $ptaTotal }} / {{ $directionsCovered }}</p>
            <p class="showcase-kpi-meta">PTA visibles et directions impliquees</p>
        </article>
    </section>

    <section class="showcase-toolbar mb-4">
        <div>
            <h2 class="showcase-panel-title">Filtres de lecture</h2>
            <p class="showcase-panel-subtitle">Recherche par titre, PAS parent, OS, direction, annee ou statut.</p>
        </div>
        <form method="GET" action="{{ route('workspace.pao.index') }}" class="mt-4">
            <div class="showcase-filter-grid">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Titre ou objectif">
                </div>
                <div>
                    <label for="pas_id">PAS</label>
                    <select id="pas_id" name="pas_id">
                        <option value="">Tous</option>
                        @foreach ($pasOptions as $pas)
                            <option value="{{ $pas->id }}" @selected($filters['pas_id'] === $pas->id)>
                                #{{ $pas->id }} - {{ $pas->titre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="pas_objectif_id">Objectif strategique</label>
                    <select id="pas_objectif_id" name="pas_objectif_id">
                        <option value="">Tous</option>
                        @foreach ($objectifOptions as $objectif)
                            <option value="{{ $objectif->id }}" @selected($filters['pas_objectif_id'] === $objectif->id)>
                                {{ $objectif->code }} - {{ $objectif->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="direction_id">Direction</label>
                    <select id="direction_id" name="direction_id">
                        <option value="">Toutes</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected($filters['direction_id'] === $direction->id)>
                                {{ $direction->code }} - {{ $direction->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="annee">Annee</label>
                    <input id="annee" name="annee" type="number" value="{{ $filters['annee'] }}" min="2000">
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
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-blue" href="{{ route('workspace.pao.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="showcase-panel mb-4">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des PAO</h2>
                <p class="showcase-panel-subtitle">Vue consolidee des echeances, OS strategiques et volumes PTA rattaches a chaque direction.</p>
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
                        <th>PAO</th>
                        <th>PAS</th>
                        <th>Axe</th>
                        <th>OS</th>
                        <th>Direction</th>
                        <th>Annee</th>
                        <th>Statut</th>
                        <th>PTA</th>
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
                            $canSubmit = $row->statut === 'brouillon'
                                && ($currentUser->hasGlobalWriteAccess()
                                    || ($currentUser->hasRole(\App\Models\User::ROLE_DIRECTION)
                                        && (int) $currentUser->direction_id === (int) $row->direction_id));
                            $canApprove = $row->statut === 'soumis'
                                && $currentUser->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_DG);
                            $canLock = $row->statut === 'valide'
                                && $currentUser->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_DG);
                            $canReopen = in_array($row->statut, ['soumis', 'valide'], true)
                                && (
                                    $currentUser->hasRole(\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_DG)
                                    || ($row->statut === 'soumis'
                                        && ($currentUser->hasGlobalWriteAccess()
                                            || ($currentUser->hasRole(\App\Models\User::ROLE_DIRECTION)
                                                && (int) $currentUser->direction_id === (int) $row->direction_id)))
                                );
                        @endphp
                        <tr>
                            <td>#{{ $row->id }}</td>
                            <td>
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ $row->titre }}</div>
                                <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $row->echeance ?? '-' }}</div>
                            </td>
                            <td>{{ $row->pas?->titre ?? '-' }}</td>
                            <td>{{ $row->pasObjectif?->pasAxe?->code ? $row->pasObjectif->pasAxe->code.' - '.$row->pasObjectif->pasAxe->libelle : '-' }}</td>
                            <td>{{ $row->pasObjectif?->code ? $row->pasObjectif->code.' - '.$row->pasObjectif->libelle : '-' }}</td>
                            <td>{{ $row->direction?->code }} {{ $row->direction?->libelle ? '- '.$row->direction->libelle : '' }}</td>
                            <td>{{ $row->annee }}</td>
                            <td>
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses }}">
                                    {{ $row->statut }}
                                </span>
                            </td>
                            <td>{{ $row->ptas_count }}</td>
                            <td>{{ $row->validateur?->name ?? '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-amber" href="{{ route('workspace.pao.edit', $row) }}">Modifier</a>
                                        @if ($canSubmit)
                                            <form method="POST" action="{{ route('workspace.pao.submit', $row) }}">
                                                @csrf
                                                <button class="btn btn-blue" type="submit">Soumettre</button>
                                            </form>
                                        @endif
                                        @if ($canApprove)
                                            <form method="POST" action="{{ route('workspace.pao.approve', $row) }}">
                                                @csrf
                                                <button class="btn btn-green" type="submit">Valider</button>
                                            </form>
                                        @endif
                                        @if ($canLock)
                                            <form method="POST" action="{{ route('workspace.pao.lock', $row) }}" onsubmit="return confirm('Verrouiller ce PAO ?')">
                                                @csrf
                                                <button class="btn btn-primary" type="submit">Verrouiller</button>
                                            </form>
                                        @endif
                                        @if ($canReopen)
                                            <form method="POST" action="{{ route('workspace.pao.reopen', $row) }}" onsubmit="return requireWorkflowReason(this, 'PAO')">
                                                @csrf
                                                <input type="hidden" name="motif_retour" value="">
                                                <button class="btn btn-blue" type="submit">Retour brouillon</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('workspace.pao.destroy', $row) }}" onsubmit="return confirm('Supprimer ce PAO ?')">
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
                            <td colspan="{{ $canWrite ? 11 : 10 }}" class="text-slate-500 dark:text-slate-400">Aucun PAO trouve.</td>
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

