@extends('layouts.workspace')

@section('content')
    @php
        $currentUser = auth()->user();
        $lockService = app(\App\Services\PlanningModificationLockService::class);
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $ps = is_array($pasStats ?? null) ? $pasStats : [];
        $summaryCards = [
            ['label' => 'Actifs',                   'value' => $ps['actifs'] ?? 0,                'meta' => null, 'href' => route('workspace.pas.index', ['statut' => 'actif']), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Clotures',                 'value' => $ps['clotures'] ?? 0,              'meta' => null, 'href' => route('workspace.pas.index', ['statut' => 'cloture']),  'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Objectifs stratégiques',   'value' => $ps['objectifs_total'] ?? 0,       'meta' => null, 'href' => route('workspace.pao.index'),                             'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Sans PAO associé',         'value' => $ps['sans_pao'] ?? 0,              'meta' => null, 'href' => route('workspace.pas.index', ['without_pao' => 1]),       'badge' => null, 'badge_tone' => ($ps['sans_pao'] ?? 0) > 0 ? 'warning' : 'neutral'],
        ];
    @endphp

    <div class="app-screen-flow">
    <x-ui.page-title
        eyebrow="Pilotage institutionnel"
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
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Titre</th>
                        <th>Période</th>
                        <th>Statut</th>
                        <th>Nb Axes</th>
                        <th>Nb Obj. Strat.</th>
                        <th>Nb PAO</th>
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
                                'actif' => 'anbg-badge anbg-badge-success',
                                'cloture' => 'anbg-badge anbg-badge-info',
                                'archive' => 'anbg-badge anbg-badge-neutral',
                                default => 'anbg-badge anbg-badge-neutral',
                            };
                            $isModificationLocked = $lockService->isLocked($row);
                            $canRequestUnlock = $currentUser && $lockService->canRequestUnlock($currentUser, $row);
                            $objectifsStrategiquesCount = $row->axes->sum(fn ($axe) => $axe->objectifs->count());
                        @endphp
                        <tr>
                            <td class="font-mono text-xs text-slate-600">{{ $row->id }}</td>
                            <td class="font-mono text-xs font-semibold text-slate-800">PAS-{{ $row->periode_debut }}-{{ $row->periode_fin }}</td>
                            <td class="font-semibold text-slate-900">{{ $row->titre }}</td>
                            <td class="whitespace-nowrap">{{ $row->periode_debut }} → {{ $row->periode_fin }}</td>
                            <td>
                                <span class="{{ $statusClasses }}">
                                    {{ $workflowStatusLabel($row->statut) }}
                                </span>
                                @if ($isModificationLocked)
                                    <p class="mt-2"><span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-xs">Modification verrouillee</span></p>
                                @endif
                            </td>
                            <td class="text-center"><span class="anbg-badge anbg-badge-neutral px-3">{{ $row->axes_count }}</span></td>
                            <td class="text-center"><span class="anbg-badge anbg-badge-info px-3">{{ $objectifsStrategiquesCount }}</span></td>
                            <td class="text-center"><span class="anbg-badge anbg-badge-success px-3">{{ $row->paos_count }}</span></td>
                            <td>{{ $row->validateur?->name ?? '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="row-actions">
                                        @if (! $isModificationLocked)
                                            <a class="btn btn-warning" href="{{ route('workspace.pas.edit', $row) }}">Modifier</a>
                                        @elseif ($canRequestUnlock)
                                            @include('workspace.planning-unlocks._request-inline', [
                                                'target' => $row,
                                                'route' => route('workspace.pas.unlock-requests.store', $row),
                                                'context' => 'Modification PAS demandee par '.$currentUser->name,
                                            ])
                                        @endif
                                        @if ($row->statut === 'actif')
                                            <form method="POST" action="{{ route('workspace.pas.close', $row) }}" data-confirm-message="Cloturer ce PAS apres controle des anomalies ?" data-confirm-tone="warning" data-confirm-label="Cloturer">
                                                @csrf
                                                <input type="hidden" name="motif" value="Cloture PAS demandee depuis la liste">
                                                <button class="btn btn-primary" type="submit">Cloturer</button>
                                            </form>
                                        @endif
                                        @if ($row->statut === 'cloture')
                                            <form method="POST" action="{{ route('workspace.pas.archive', $row) }}" data-confirm-message="Archiver ce PAS cloture ?" data-confirm-tone="warning" data-confirm-label="Archiver">
                                                @csrf
                                                <input type="hidden" name="motif" value="Archivage PAS cloture depuis la liste">
                                                <button class="btn btn-secondary" type="submit">Archiver</button>
                                            </form>
                                        @endif
                                            <form method="POST" action="{{ route('workspace.pas.destroy', $row) }}" data-confirm-message="Supprimer ce PAS ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="motif" value="Demande de suppression PAS depuis le module PAS">
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
