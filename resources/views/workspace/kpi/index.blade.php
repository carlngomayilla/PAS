@extends('layouts.workspace')

@section('content')
    @php
        $summary = is_array($summary ?? null) ? $summary : [];
        $summaryCards = [
            ['label' => 'Indicateurs', 'value' => $summary['total'] ?? $rows->total(), 'href' => route('workspace.kpi.index')],
            ['label' => 'Saisie manuelle', 'value' => $summary['manual_total'] ?? 0, 'href' => route('workspace.kpi.index', ['est_a_renseigner' => 1])],
            ['label' => 'Calcules', 'value' => $summary['automatic_total'] ?? 0, 'href' => route('workspace.kpi.index', ['est_a_renseigner' => 0])],
            ['label' => 'Avec seuil', 'value' => $summary['with_threshold_total'] ?? 0, 'href' => route('workspace.kpi.index')],
            ['label' => 'Sans mesure', 'value' => $summary['without_measure_total'] ?? 0, 'href' => route('workspace.kpi.index', ['without_mesure' => 1])],
            ['label' => 'Actions liees', 'value' => $summary['actions_total'] ?? 0, 'href' => route('workspace.actions.index')],
        ];
    @endphp
    <div class="app-screen-flow">
    <section class="showcase-hero mb-4 app-screen-block">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">Suivi & mesure</span>
                <h1 class="showcase-title">Indicateurs</h1>
            </div>
            <div class="showcase-action-row">
                @if ($canWrite)
                    <a class="btn btn-green" href="{{ route('workspace.kpi.create') }}">Nouvel indicateur</a>
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
        <form method="GET" action="{{ route('workspace.kpi.index') }}">
            <div class="showcase-filter-grid">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Libelle ou unite">
                </div>
                <div>
                    <label for="action_id">Action</label>
                    <select id="action_id" name="action_id">
                        <option value="">Toutes</option>
                        @foreach ($actionOptions as $action)
                            <option value="{{ $action->id }}" @selected($filters['action_id'] === $action->id)>
                                #{{ $action->id }} - {{ $action->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="periodicite">Periodicite</label>
                    <select id="periodicite" name="periodicite">
                        <option value="">Toutes</option>
                        @foreach ($periodiciteOptions as $periodicite)
                            <option value="{{ $periodicite }}" @selected($filters['periodicite'] === $periodicite)>{{ $periodicite }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="est_a_renseigner">Mode de saisie</label>
                    <select id="est_a_renseigner" name="est_a_renseigner">
                        <option value="">Tous</option>
                        @foreach ($modeSaisieOptions as $value => $label)
                            <option value="{{ $value }}" @selected((string) $filters['est_a_renseigner'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-blue" href="{{ route('workspace.kpi.index') }}">Réinitialiser</a>
            </div>
        </form>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <h2 class="showcase-panel-title">Liste des indicateurs</h2>
        </div>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Indicateur</th>
                        <th>Action</th>
                        <th>Periodicite</th>
                        <th>Mode saisie</th>
                        <th>Cible</th>
                        <th>Seuil alerte</th>
                        <th>Mesures</th>
                        @if ($canWrite)
                            <th>Operations</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->id }}</td>
                            <td>
                                <strong>{{ $row->libelle }}</strong><br>
                                <span class="text-slate-600">{{ $row->unite ?: '-' }}</span>
                            </td>
                            <td>{{ $row->action?->libelle ?? '-' }}</td>
                            <td><span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-xs">{{ $row->periodicite }}</span></td>
                            <td>
                                <span class="anbg-badge {{ $row->est_a_renseigner ? 'anbg-badge-success' : 'anbg-badge-neutral' }} px-2 py-0.5 text-xs">
                                    {{ $row->mode_saisie_label }}
                                </span>
                            </td>
                            <td>{{ $row->cible ?? '-' }}</td>
                            <td>{{ $row->seuil_alerte ?? '-' }}</td>
                            <td>{{ $row->est_a_renseigner ? $row->mesures_count : '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-amber" href="{{ route('workspace.kpi.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.kpi.destroy', $row) }}" data-confirm-message="Supprimer cet indicateur ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
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
                            <td colspan="{{ $canWrite ? 9 : 8 }}" class="text-slate-500">Aucun indicateur trouve.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $rows->links() }}</div>
    </section>
    </div>
@endsection
