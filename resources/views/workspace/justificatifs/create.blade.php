@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>Ajouter un justificatif</h1>
        <p class="text-slate-600">Associez une preuve documentaire a une action, un indicateur ou une mesure d indicateur.</p>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" class="form-shell" action="{{ route('workspace.justificatifs.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="form-section">
                <h2 class="form-section-title">Saisie du justificatif</h2>
                <p class="form-section-subtitle">Format uniforme avec champs alignes sur toute la largeur disponible.</p>
                <div class="form-grid">
                    <div>
                        <label for="justifiable_type">Type d'entite</label>
                        <select id="justifiable_type" name="justifiable_type" required>
                            <option value="">Selectionner</option>
                            @foreach ($typeOptions as $key => $label)
                                <option value="{{ $key }}" @selected(old('justifiable_type') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="justifiable_id">ID entite</label>
                        <input id="justifiable_id" name="justifiable_id" type="number" min="1" value="{{ old('justifiable_id') }}" required>
                    </div>
                    <div>
                        <label for="fichier">Fichier</label>
                        <input id="fichier" name="fichier" type="file" required>
                    </div>
                </div>
                <div class="mt-3">
                    <label for="description">Description</label>
                    <textarea id="description" name="description">{{ old('description') }}</textarea>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Enregistrer</button>
                <a class="btn btn-secondary" href="{{ route('workspace.justificatifs.index') }}">Retour</a>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <h2>References rapides (IDs utilisables)</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
            <article class="ui-card mb-3.5 !mb-0">
                <strong>Actions</strong>
                <div class="overflow-auto mt-2">
                    <table>
                        <thead><tr><th>ID</th><th>Libelle</th></tr></thead>
                        <tbody>
                            @forelse ($references['actions'] as $row)
                                <tr><td>{{ $row->id }}</td><td>{{ $row->libelle }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="text-slate-600">Aucune action</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="ui-card mb-3.5 !mb-0">
                <strong>Indicateurs</strong>
                <div class="overflow-auto mt-2">
                    <table>
                        <thead><tr><th>ID</th><th>Libelle</th></tr></thead>
                        <tbody>
                            @forelse ($references['kpis'] as $row)
                                <tr><td>{{ $row->id }}</td><td>{{ $row->libelle }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="text-slate-600">Aucun indicateur</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="ui-card mb-3.5 !mb-0">
                <strong>Mesures indicateur</strong>
                <div class="overflow-auto mt-2">
                    <table>
                        <thead><tr><th>ID</th><th>Periode</th></tr></thead>
                        <tbody>
                            @forelse ($references['kpi_mesures'] as $row)
                                <tr><td>{{ $row->id }}</td><td>{{ $row->periode }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="text-slate-600">Aucune mesure indicateur</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
    </section>
@endsection
