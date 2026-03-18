@extends('layouts.workspace')

@section('content')
    @php
        $currentUser = auth()->user();
        $visibleRows = collect($rows->items());
        $draftCount = $visibleRows->where('statut', 'brouillon')->count();
        $validatedCount = $visibleRows->filter(fn ($item) => in_array((string) $item->statut, ['valide', 'verrouille'], true))->count();
        $actionsTotal = (int) $visibleRows->sum('actions_count');
        $servicesCovered = $visibleRows->pluck('service_id')->filter()->unique()->count();
    @endphp

    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">Execution par service</span>
                <h1 class="showcase-title">PTA</h1>
                <p class="showcase-subtitle">
                    Plan de travail annuel par service, coherent avec le PAO rattache et la structure direction -> service.
                </p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        Un PAO parent
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-indigo-500"></span>
                        Un service cible
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-emerald-500"></span>
                        Porte les actions operationnelles
                    </span>
                </div>
            </div>
            <div class="showcase-action-row">
                @if ($canWrite)
                    <a class="btn btn-green" href="{{ route('workspace.pta.create') }}">Nouveau PTA</a>
                @endif
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Total</p>
            <p class="showcase-kpi-number">{{ $rows->total() }}</p>
            <p class="showcase-kpi-meta">PTA dans le perimetre courant</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Brouillons visibles</p>
            <p class="showcase-kpi-number">{{ $draftCount }}</p>
            <p class="showcase-kpi-meta">Plans en construction</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Valides / verrouilles</p>
            <p class="showcase-kpi-number">{{ $validatedCount }}</p>
            <p class="showcase-kpi-meta">PTA prets pour l execution</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Actions / services</p>
            <p class="showcase-kpi-number">{{ $actionsTotal }} / {{ $servicesCovered }}</p>
            <p class="showcase-kpi-meta">Actions visibles et services couverts</p>
        </article>
    </section>

    <section class="showcase-toolbar mb-4">
        <div>
            <h2 class="showcase-panel-title">Filtres de lecture</h2>
            <p class="showcase-panel-subtitle">Recherche par titre, PAO, direction, service ou statut.</p>
        </div>
        <form method="GET" action="{{ route('workspace.pta.index') }}" class="mt-4">
            <div class="showcase-filter-grid">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Titre ou description">
                </div>
                <div>
                    <label for="pao_id">PAO</label>
                    <select id="pao_id" name="pao_id">
                        <option value="">Tous</option>
                        @foreach ($paoOptions as $pao)
                            <option value="{{ $pao->id }}" @selected($filters['pao_id'] === $pao->id)>
                                #{{ $pao->id }} - {{ $pao->titre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="direction_id">Direction ID</label>
                    <input id="direction_id" name="direction_id" type="number" value="{{ $filters['direction_id'] }}" placeholder="ID direction">
                </div>
                <div>
                    <label for="service_id">Service</label>
                    <select id="service_id" name="service_id">
                        <option value="">Tous</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" @selected($filters['service_id'] === $service->id)>
                                {{ $service->code }} - {{ $service->libelle }}
                            </option>
                        @endforeach
                    </select>
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
                <a class="btn btn-blue" href="{{ route('workspace.pta.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="showcase-panel mb-4">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des PTA</h2>
                <p class="showcase-panel-subtitle">Lecture du service porteur, du PAO parent et du volume d actions par plan.</p>
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
                        <th>PAO</th>
                        <th>Direction</th>
                        <th>Service</th>
                        <th>Statut</th>
                        <th>Actions</th>
                        <th>Validateur</th>
                        @if ($canWrite)
                            <th>Operations</th>
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
                                        && (int) $currentUser->direction_id === (int) $row->direction_id)
                                    || ($currentUser->hasRole(\App\Models\User::ROLE_SERVICE)
                                        && (int) $currentUser->direction_id === (int) $row->direction_id
                                        && (int) $currentUser->service_id === (int) $row->service_id));
                            $canApprove = $row->statut === 'soumis'
                                && ($currentUser->hasGlobalWriteAccess()
                                    || ($currentUser->hasRole(\App\Models\User::ROLE_DIRECTION)
                                        && (int) $currentUser->direction_id === (int) $row->direction_id));
                            $canLock = $row->statut === 'valide'
                                && $currentUser->hasGlobalWriteAccess();
                            $canReopen = in_array($row->statut, ['soumis', 'valide'], true)
                                && ($canApprove || ($row->statut === 'soumis' && $canSubmit));
                        @endphp
                        <tr>
                            <td>#{{ $row->id }}</td>
                            <td>
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ $row->titre }}</div>
                            </td>
                            <td>{{ $row->pao?->titre ?? '-' }}</td>
                            <td>{{ $row->direction?->code }} {{ $row->direction?->libelle ? '- '.$row->direction->libelle : '' }}</td>
                            <td>{{ $row->service?->code }} {{ $row->service?->libelle ? '- '.$row->service->libelle : '' }}</td>
                            <td>
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses }}">
                                    {{ $row->statut }}
                                </span>
                            </td>
                            <td>{{ $row->actions_count }}</td>
                            <td>{{ $row->validateur?->name ?? '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-amber" href="{{ route('workspace.pta.edit', $row) }}">Modifier</a>
                                        @if ($canSubmit)
                                            <form method="POST" action="{{ route('workspace.pta.submit', $row) }}">
                                                @csrf
                                                <button class="btn btn-blue" type="submit">Soumettre</button>
                                            </form>
                                        @endif
                                        @if ($canApprove)
                                            <form method="POST" action="{{ route('workspace.pta.approve', $row) }}">
                                                @csrf
                                                <button class="btn btn-green" type="submit">Valider</button>
                                            </form>
                                        @endif
                                        @if ($canLock)
                                            <form method="POST" action="{{ route('workspace.pta.lock', $row) }}" onsubmit="return confirm('Verrouiller ce PTA ?')">
                                                @csrf
                                                <button class="btn btn-primary" type="submit">Verrouiller</button>
                                            </form>
                                        @endif
                                        @if ($canReopen)
                                            <form method="POST" action="{{ route('workspace.pta.reopen', $row) }}" onsubmit="return requireWorkflowReason(this, 'PTA')">
                                                @csrf
                                                <input type="hidden" name="motif_retour" value="">
                                                <button class="btn btn-blue" type="submit">Retour brouillon</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('workspace.pta.destroy', $row) }}" onsubmit="return confirm('Supprimer ce PTA ?')">
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
                            <td colspan="{{ $canWrite ? 9 : 8 }}" class="text-slate-500 dark:text-slate-400">Aucun PTA trouve.</td>
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

