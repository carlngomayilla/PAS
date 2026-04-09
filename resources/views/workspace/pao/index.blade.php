@extends('layouts.workspace')

@section('content')
    @php
        $currentUser = auth()->user();
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $visibleRows = collect($rows->items());
        $draftCount = $visibleRows->where('statut', 'brouillon')->count();
        $validatedCount = $visibleRows->filter(fn ($item) => in_array((string) $item->statut, ['valide', 'verrouille'], true))->count();
        $ptaTotal = (int) $visibleRows->sum('ptas_count');
        $directionsCovered = $visibleRows->pluck('direction_id')->filter()->unique()->count();
        $summaryCards = [
            ['label' => 'Total', 'value' => $rows->total(), 'meta' => 'PAO dans le perimetre courant', 'href' => route('workspace.pao.index'), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Brouillons visibles', 'value' => $draftCount, 'meta' => 'Elements encore en construction', 'href' => route('workspace.pao.index', ['statut' => 'brouillon']), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Valides / verrouilles', 'value' => $validatedCount, 'meta' => 'PAO prets a alimenter les services', 'href' => route('workspace.pao.index', ['statut' => 'valide_ou_verrouille']), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'PTA / directions', 'value' => $ptaTotal.' / '.$directionsCovered, 'meta' => 'PTA visibles et directions impliquees', 'href' => route('workspace.pta.index'), 'badge' => null, 'badge_tone' => 'neutral'],
        ];
    @endphp

    <div class="app-screen-flow">
    <section class="showcase-hero mb-4 app-screen-block">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">Declinaison annuelle</span>
                <h1 class="showcase-title">PAO</h1>
            </div>
            <div class="showcase-action-row">
                @if ($canWrite)
                    <a class="btn btn-green" href="{{ route('workspace.pao.create') }}">Nouveau PAO</a>
                @endif
            </div>
        </div>
    </section>

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
                            <option value="{{ $status }}" @selected($filters['statut'] === $status)>{{ $workflowStatusLabel($status) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if ($filters['without_pta'])
                <input type="hidden" name="without_pta" value="1">
            @endif
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-blue" href="{{ route('workspace.pao.index') }}">Reinitialiser</a>
            </div>
            @if ($filters['without_pta'])
                <div class="mt-4 rounded-[1rem] border border-amber-200/80 bg-amber-50/90 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                    PAO sans PTA
                </div>
            @endif
        </form>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des PAO</h2>
            </div>
            <span class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $rows->count() }} ligne(s)</span>
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
                                'brouillon' => 'anbg-badge anbg-badge-neutral',
                                'soumis' => 'anbg-badge anbg-badge-warning',
                                'valide' => 'anbg-badge anbg-badge-success',
                                'verrouille' => 'anbg-badge anbg-badge-info',
                                default => 'anbg-badge anbg-badge-neutral',
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
                                <span class="{{ $statusClasses }}">
                                    {{ $workflowStatusLabel($row->statut) }}
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
                                            <form method="POST" action="{{ route('workspace.pao.lock', $row) }}" data-confirm-message="Verrouiller ce PAO ?" data-confirm-tone="warning" data-confirm-label="Verrouiller">
                                                @csrf
                                                <button class="btn btn-primary" type="submit">Verrouiller</button>
                                            </form>
                                        @endif
                                        @if ($canReopen)
                                            <form method="POST" action="{{ route('workspace.pao.reopen', $row) }}" data-prompt-title="Retour brouillon" data-prompt-message="Saisir le motif de retour brouillon (PAO)." data-prompt-label="Motif de retour" data-prompt-placeholder="Minimum 5 caracteres" data-prompt-target="motif_retour" data-prompt-minlength="5" data-prompt-confirm="Confirmer">
                                                @csrf
                                                <input type="hidden" name="motif_retour" value="">
                                                <button class="btn btn-blue" type="submit">Retour brouillon</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('workspace.pao.destroy', $row) }}" data-confirm-message="Supprimer ce PAO ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
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
        <div class="pagination">{{ $rows->links() }}</div>
    </section>
    </div>
@endsection

