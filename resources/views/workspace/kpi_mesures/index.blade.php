@extends('layouts.workspace')

@section('content')
    @php
        $summary = is_array($summary ?? null) ? $summary : [];
        $summaryCards = [
            ['label' => 'Mesures', 'value' => $summary['total'] ?? $rows->total(), 'href' => route('workspace.kpi-mesures.index')],
            ['label' => 'Sous seuil', 'value' => $summary['below_threshold_total'] ?? 0, 'href' => route('workspace.kpi-mesures.index', ['below_threshold' => 1])],
            ['label' => 'Indicateurs suivis', 'value' => $summary['kpis_total'] ?? 0, 'href' => route('workspace.kpi.index')],
            ['label' => 'Contributeurs', 'value' => $summary['contributors_total'] ?? 0, 'href' => route('workspace.kpi-mesures.index')],
            ['label' => 'Avec commentaire', 'value' => $summary['commented_total'] ?? 0, 'href' => route('workspace.kpi-mesures.index')],
        ];
    @endphp
    <div class="app-screen-flow">
    <section class="showcase-hero mb-4 app-screen-block">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">Suivi & mesure</span>
                <h1 class="showcase-title">Mesures d indicateur</h1>
            </div>
            <div class="showcase-action-row">
                @if ($canWrite)
                    <a class="btn btn-green" href="{{ route('workspace.kpi-mesures.create') }}">Nouvelle mesure</a>
                @endif
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4 app-screen-kpis">
        @foreach ($summaryCards as $card)
            <x-stat-card-link :href="$card['href']" :label="$card['label']" :value="$card['value']" :meta="null" />
        @endforeach
    </section>

    <section class="showcase-toolbar mb-4 app-screen-block">
        <div><h2 class="showcase-panel-title">Filtres</h2></div>
        <form method="GET" action="{{ route('workspace.kpi-mesures.index') }}">
            <div class="showcase-filter-grid">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Période ou commentaire">
                </div>
                <div>
                    <label for="kpi_id">Indicateur</label>
                    <select id="kpi_id" name="kpi_id">
                        <option value="">Tous</option>
                        @foreach ($kpiOptions as $kpi)
                            <option value="{{ $kpi->id }}" @selected($filters['kpi_id'] === $kpi->id)>
                                #{{ $kpi->id }} - {{ $kpi->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="periode">Période</label>
                    <input id="periode" name="periode" type="text" value="{{ $filters['periode'] }}" placeholder="Ex: 2026-01">
                </div>
                <div>
                    <label for="saisi_par">Saisi par</label>
                    <select id="saisi_par" name="saisi_par">
                        <option value="">Tous</option>
                        @foreach ($saisiParOptions as $user)
                            <option value="{{ $user->id }}" @selected($filters['saisi_par'] === $user->id)>
                                {{ $user->name }} ({{ $user->email }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-blue" href="{{ route('workspace.kpi-mesures.index') }}">Réinitialiser</a>
            </div>
        </form>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <h2 class="showcase-panel-title">Liste des mesures</h2>
        </div>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Indicateur</th>
                        <th>Période</th>
                        <th>Valeur</th>
                        <th>Saisi par</th>
                        <th>Commentaire</th>
                        @if ($canWrite)
                            <th>Opérations</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->id }}</td>
                            <td>
                                <strong>{{ $row->kpi?->libelle ?? '-' }}</strong><br>
                                <span class="text-slate-600">{{ $row->kpi?->periodicite ?? '-' }}</span>
                            </td>
                            <td>{{ $row->periode }}</td>
                            <td>{{ $row->valeur }}</td>
                            <td>{{ $row->saisiPar?->name ?? '-' }}</td>
                            <td>{{ $row->commentaire ?: '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-amber" href="{{ route('workspace.kpi-mesures.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.kpi-mesures.destroy', $row) }}" data-confirm-message="Supprimer cette mesure ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
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
                            <td colspan="{{ $canWrite ? 7 : 6 }}" class="text-slate-500">Aucune mesure trouvée.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $rows->links() }}</div>
    </section>
    </div>
@endsection
