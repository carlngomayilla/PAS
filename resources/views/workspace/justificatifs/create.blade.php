@extends('layouts.workspace')

@section('content')
    <div class="app-screen-flow">
    <section class="ui-card mb-3.5 app-screen-block">
        <h1>Ajouter un justificatif</h1>
        <p class="mt-2 text-sm text-slate-500">Formats autorises : {{ strtoupper(implode(', ', $documentPolicySettings['allowed_extensions'] ?? [])) }} | Taille max : {{ $documentPolicySettings['max_upload_mb'] ?? 10 }} Mo</p>
    </section>

    <section class="ui-card mb-3.5 app-screen-block">
        <form method="POST" class="form-shell" action="{{ route('workspace.justificatifs.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="form-section">
                <h2 class="form-section-title">Saisie du justificatif</h2>
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
                        <input id="fichier" name="fichier" type="file" accept="{{ $documentAccept }}" required>
                        <p class="mt-1 text-xs text-slate-500">Retention indicative : {{ $documentPolicySettings['retention_days'] ?? 365 }} jours.</p>
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

    <section class="ui-card mb-3.5 app-screen-block">
        <h2>References rapides (IDs utilisables)</h2>
        <div class="app-screen-stack">
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
    </div>
@endsection
