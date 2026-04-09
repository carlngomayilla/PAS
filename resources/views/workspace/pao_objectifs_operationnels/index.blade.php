@extends('layouts.workspace')

@section('content')
    @php
        $operationalStatusLabel = static fn (string $status): string => \App\Support\UiLabel::actionStatus($status);
        $legacyOperationalStatusBadges = [
            'non_demarre' => 'anbg-badge anbg-badge-neutral',
            'en_cours' => 'anbg-badge anbg-badge-info',
            'en_retard' => 'anbg-badge anbg-badge-danger',
            'bloque' => 'anbg-badge anbg-badge-warning',
            'termine' => 'anbg-badge anbg-badge-success',
            'annule' => 'anbg-badge anbg-badge-neutral',
        ];
    @endphp
    <div class="app-screen-flow">
    <section class="ui-card mb-3.5 app-screen-block">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h1>PAO - Objectifs operationnels</h1>
            @if ($canWrite)
                <a class="btn btn-green" href="{{ route('workspace.pao-objectifs-operationnels.create') }}">Nouvel objectif operationnel</a>
            @endif
        </div>
    </section>

    <section class="ui-card mb-3.5 app-screen-block">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.pao-objectifs-operationnels.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Code, libelle, action detaillee">
                </div>
                <div>
                    <label for="pao_objectif_strategique_id">Objectif strategique</label>
                    <select id="pao_objectif_strategique_id" name="pao_objectif_strategique_id">
                        <option value="">Tous</option>
                        @foreach ($objectifStrategiqueOptions as $objectif)
                            <option value="{{ $objectif->id }}" @selected($filters['pao_objectif_strategique_id'] === $objectif->id)>
                                #{{ $objectif->id }} - {{ $objectif->code }} | {{ $objectif->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="responsable_id">Responsable</label>
                    <select id="responsable_id" name="responsable_id">
                        <option value="">Tous</option>
                        @foreach ($responsableOptions as $responsable)
                            <option value="{{ $responsable->id }}" @selected($filters['responsable_id'] === $responsable->id)>
                                {{ $responsable->name }} ({{ $responsable->email }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="statut_realisation">Statut</label>
                    <select id="statut_realisation" name="statut_realisation">
                        <option value="">Tous</option>
                        @foreach ($statusOptions as $status)
                            <option value="{{ $status }}" @selected($filters['statut_realisation'] === $status)>{{ $operationalStatusLabel($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="priorite">Priorite</label>
                    <select id="priorite" name="priorite">
                        <option value="">Toutes</option>
                        @foreach ($prioriteOptions as $priorite)
                            <option value="{{ $priorite }}" @selected($filters['priorite'] === $priorite)>{{ $priorite }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-blue" href="{{ route('workspace.pao-objectifs-operationnels.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5 app-screen-block">
        <h2>Liste des objectifs operationnels</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code / Libelle</th>
                        <th>Objectif strategique</th>
                        <th>Responsable</th>
                        <th>Periode</th>
                        <th>Cible / Prog.</th>
                        <th>Statut / Priorite</th>
                        <th>Echeance</th>
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
                                <span class="anbg-badge anbg-badge-neutral px-3">{{ $row->code }}</span><br>
                                <strong>{{ $row->libelle }}</strong><br>
                                <span class="text-slate-600">{{ $row->description_action_detaillee }}</span>
                            </td>
                            <td>
                                {{ $row->objectifStrategique?->code ?? '-' }} - {{ $row->objectifStrategique?->libelle ?? '-' }}<br>
                                <span class="text-slate-600">{{ $row->objectifStrategique?->paoAxe?->pao?->titre ?? '-' }}</span>
                            </td>
                            <td>{{ $row->responsable?->name ?? '-' }}</td>
                            <td>
                                {{ $row->date_debut }}<br>
                                <span class="text-slate-600">au {{ $row->date_fin }}</span>
                            </td>
                            <td>
                                Cible: {{ $row->cible_pourcentage }}%<br>
                                <span class="text-slate-600">Prog: {{ $row->progression_pourcentage }}%</span>
                            </td>
                            <td>
                                <span class="{{ $legacyOperationalStatusBadges[$row->statut_realisation] ?? 'anbg-badge anbg-badge-neutral' }} px-3">{{ $operationalStatusLabel($row->statut_realisation) }}</span><br>
                                <span class="text-slate-600">{{ $row->priorite }}</span>
                            </td>
                            <td>{{ $row->echeance ?? '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="btn btn-amber" href="{{ route('workspace.pao-objectifs-operationnels.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.pao-objectifs-operationnels.destroy', $row) }}" data-confirm-message="Supprimer cet objectif operationnel ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
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
                            <td colspan="{{ $canWrite ? 9 : 8 }}" class="text-slate-600">Aucun objectif operationnel trouve.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $rows->links() }}</div>
    </section>
    </div>
@endsection
