@extends('layouts.workspace')

@section('content')
    @php
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $legacyWorkflowBadges = [
            'brouillon' => 'anbg-badge anbg-badge-neutral',
            'soumis' => 'anbg-badge anbg-badge-warning',
            'valide' => 'anbg-badge anbg-badge-success',
            'verrouille' => 'anbg-badge anbg-badge-info',
        ];
    @endphp
    <div class="app-screen-flow">
    <section class="ui-card mb-3.5 app-screen-block">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h1>PAO - Axes strategiques</h1>
            @if ($canWrite)
                <a class="btn btn-green" href="{{ route('workspace.pao-axes.create') }}">Nouvel axe PAO</a>
            @endif
        </div>
    </section>

    <section class="ui-card mb-3.5 app-screen-block">
        <h2>Filtres</h2>
        <form method="GET" action="{{ route('workspace.pao-axes.index') }}">
            <div class="form-grid-compact mb-2">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Code, libelle, description">
                </div>
                <div>
                    <label for="pao_id">PAO</label>
                    <select id="pao_id" name="pao_id">
                        <option value="">Tous</option>
                        @foreach ($paoOptions as $pao)
                            <option value="{{ $pao->id }}" @selected($filters['pao_id'] === $pao->id)>
                                #{{ $pao->id }} - {{ $pao->titre }} ({{ $pao->annee }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button class="btn btn-primary" type="submit">Appliquer</button>
                <a class="btn btn-blue" href="{{ route('workspace.pao-axes.index') }}">Reinitialiser</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5 app-screen-block">
        <h2>Liste des axes PAO</h2>
        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Libelle</th>
                        <th>PAO</th>
                        <th>Ordre</th>
                        <th>Obj. strategiques</th>
                        @if ($canWrite)
                            <th>Operations</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->id }}</td>
                            <td><span class="anbg-badge anbg-badge-neutral px-3">{{ $row->code }}</span></td>
                            <td>
                                <strong>{{ $row->libelle }}</strong><br>
                                <span class="text-slate-600">{{ $row->description ?: '-' }}</span>
                            </td>
                            <td>
                                {{ $row->pao?->titre ?? '-' }}<br>
                                <span class="text-slate-600">{{ $row->pao?->annee ?? '-' }}</span>
                                @if ($row->pao?->statut)
                                <span class="{{ $legacyWorkflowBadges[$row->pao->statut] ?? 'anbg-badge anbg-badge-neutral' }} px-3 ml-2">
                                        {{ $workflowStatusLabel($row->pao->statut) }}
                                    </span>
                                @endif
                            </td>
                            <td>{{ $row->ordre }}</td>
                            <td>{{ $row->objectifs_strategiques_count }}</td>
                            @if ($canWrite)
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <a class="btn btn-amber" href="{{ route('workspace.pao-axes.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.pao-axes.destroy', $row) }}" data-confirm-message="Supprimer cet axe ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-red btn-sm" type="submit">Supprimer</button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canWrite ? 7 : 6 }}" class="text-slate-600">Aucun axe PAO trouve.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $rows->links() }}</div>
    </section>
    </div>
@endsection
