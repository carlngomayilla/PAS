@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>PAO - Objectifs operationnels</h1>
        <p class="text-slate-600">Pilotage detaille: action, responsable, cible, ressources, risques, statut et echeances.</p>
        @if ($canWrite)
            <p class="mt-2.5">
                <a class="btn btn-green" href="{{ route('workspace.pao-objectifs-operationnels.create') }}">Nouvel objectif operationnel</a>
            </p>
        @endif
    </section>

    <section class="ui-card mb-3.5">
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
                            <option value="{{ $status }}" @selected($filters['statut_realisation'] === $status)>{{ $status }}</option>
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
                <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-slate-900 text-white hover:bg-slate-800" type="submit">Appliquer</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.pao-objectifs-operationnels.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
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
                                <span class="inline-block rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800">{{ $row->code }}</span><br>
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
                                <span class="inline-block rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800">{{ $row->statut_realisation }}</span><br>
                                <span class="text-slate-600">{{ $row->priorite }}</span>
                            </td>
                            <td>{{ $row->echeance ?? '-' }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-amber-700 text-white hover:bg-amber-600" href="{{ route('workspace.pao-objectifs-operationnels.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.pao-objectifs-operationnels.destroy', $row) }}" onsubmit="return confirm('Supprimer cet objectif operationnel ?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-red-700 text-white hover:bg-red-600" type="submit">Supprimer</button>
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
        <div class="mt-3">
            {{ $rows->links() }}
        </div>
    </section>
@endsection
