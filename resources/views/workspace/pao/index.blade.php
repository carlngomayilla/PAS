@extends('layouts.workspace')

@section('content')
    @php
        $currentUser = auth()->user();
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $ps = is_array($paoStats ?? null) ? $paoStats : [];
        $summaryCards = [
            ['label' => 'En cours',             'value' => $ps['en_cours'] ?? 0,           'meta' => null, 'href' => route('workspace.pao.index', ['statut' => 'en_cours']), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Valides',              'value' => $ps['valides'] ?? 0,            'meta' => 'Auto si complet', 'href' => route('workspace.pao.index', ['statut' => 'valide']),  'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Avec PTA',             'value' => $ps['avec_pta'] ?? 0,           'meta' => null, 'href' => route('workspace.pta.index'),                             'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Sans PTA',             'value' => $ps['sans_pta'] ?? 0,           'meta' => null, 'href' => route('workspace.pao.index', ['without_pta' => 1]),       'badge' => null, 'badge_tone' => ($ps['sans_pta'] ?? 0) > 0 ? 'warning' : 'neutral'],
        ];
    @endphp

    <div class="app-screen-flow">
    <x-ui.page-title
        eyebrow="Déclinaison opérationnelle"
        title="Plan d'actions opérationnel"
        subtitle="Organisation annuelle des objectifs stratégiques par direction."
    >
        <x-slot:actions>
            @if ($canWrite)
                <a class="btn btn-primary" href="{{ route('workspace.pao.create') }}">Nouveau PAO</a>
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
                                {{ $pas->titre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="pas_objectif_id">Objectif stratégique</label>
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
                    <label for="annee">Année</label>
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
                <a class="btn btn-secondary" href="{{ route('workspace.pao.index') }}">Réinitialiser</a>
            </div>
            @if ($filters['without_pta'])
                <div class="mt-4 rounded-[1rem] border border-amber-200/80 bg-amber-50/90 px-4 py-3 text-sm font-medium text-amber-900">
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
            <span class="text-sm font-medium text-slate-500">{{ $rows->count() }} ligne(s)</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Titre</th>
                        <th>PAS</th>
                        <th>Objectif stratégique</th>
                        <th>Direction</th>
                        <th>Année</th>
                        <th>Échéance</th>
                        <th>Statut</th>
                        <th>Nb OO</th>
                        <th>Nb PTA</th>
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
                                'en_cours' => 'anbg-badge anbg-badge-warning',
                                'valide' => 'anbg-badge anbg-badge-success',
                                'cloture' => 'anbg-badge anbg-badge-info',
                                'archive' => 'anbg-badge anbg-badge-neutral',
                                default => 'anbg-badge anbg-badge-neutral',
                            };
                            $canClose = in_array($row->statut, ['en_cours', 'valide'], true);
                            $canArchive = $row->statut === 'cloture';
                        @endphp
                        <tr>
                            <td class="font-mono text-xs text-slate-600">{{ $row->id }}</td>
                            <td class="font-mono text-xs font-semibold text-slate-800">{{ $row->code ?? '-' }}</td>
                            <td class="font-semibold text-slate-900">{{ $row->titre }}</td>
                            <td>{{ $row->pas?->titre ?? '-' }}</td>
                            <td class="min-w-[240px]">
                                @if ($row->pasObjectif)
                                    <div class="font-medium text-slate-900">
                                        {{ $row->pasObjectif->code }} - {{ $row->pasObjectif->libelle }}
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $row->pasObjectif->pasAxe?->code ? $row->pasObjectif->pasAxe->code.' - '.$row->pasObjectif->pasAxe->libelle : 'Axe non defini' }}
                                    </p>
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $row->direction?->code }} {{ $row->direction?->libelle ? '- '.$row->direction->libelle : '' }}</td>
                            <td class="text-center">{{ $row->annee }}</td>
                            <td class="whitespace-nowrap text-xs text-slate-700">{{ $row->echeance ?? '-' }}</td>
                            <td>
                                <span class="{{ $statusClasses }}">
                                    {{ $workflowStatusLabel($row->statut) }}
                                </span>
                            </td>
                            <td class="text-center"><span class="anbg-badge anbg-badge-info px-3">{{ $row->objectifs_operationnels_count ?? 0 }}</span></td>
                            <td class="text-center"><span class="anbg-badge anbg-badge-success px-3">{{ $row->ptas_count }}</span></td>
                            <td>{{ $row->validateur?->name ?? '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-warning" href="{{ route('workspace.pao.edit', $row) }}">Modifier</a>
                                        @if ($canClose)
                                            <form method="POST" action="{{ route('workspace.pao.close', $row) }}" data-confirm-message="Cloturer ce PAO apres controle des anomalies ?" data-confirm-tone="warning" data-confirm-label="Cloturer">
                                                @csrf
                                                <input type="hidden" name="motif" value="Cloture PAO demandee depuis la liste">
                                                <button class="btn btn-primary" type="submit">Cloturer</button>
                                            </form>
                                        @endif
                                        @if ($canArchive)
                                            <form method="POST" action="{{ route('workspace.pao.archive', $row) }}" data-confirm-message="Archiver ce PAO cloture ?" data-confirm-tone="warning" data-confirm-label="Archiver">
                                                @csrf
                                                <input type="hidden" name="motif" value="Archivage PAO cloture depuis la liste">
                                                <button class="btn btn-secondary" type="submit">Archiver</button>
                                            </form>
                                        @endif
                                            <form method="POST" action="{{ route('workspace.pao.destroy', $row) }}" data-confirm-message="Supprimer ce PAO ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="motif" value="Demande de suppression PAO depuis le module PAO">
                                                <button class="btn btn-danger" type="submit">Supprimer</button>
                                            </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canWrite ? 9 : 8 }}">
                                <x-ui.empty-state
                                    title="Aucun PAO trouvé"
                                    message="Aucun plan d'actions opérationnel ne correspond aux filtres courants."
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
