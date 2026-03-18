@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>Modifier justificatif #{{ $justificatif->id }}</h1>
        <p class="text-slate-600">
            Type: <strong>{{ $typeAlias }}</strong> |
            Entite: <strong>#{{ $justificatif->justifiable_id }}</strong>
        </p>
    </section>

    <section class="ui-card mb-3.5">
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
            <article class="ui-card mb-3.5 !mb-0">
                <h2>Fichier actuel</h2>
                <p><strong>{{ $justificatif->nom_original }}</strong></p>
                <p class="text-slate-600">{{ $justificatif->mime_type }} | {{ number_format(($justificatif->taille_octets ?? 0) / 1024, 1) }} Ko</p>
                <p class="mt-2">
                    <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.justificatifs.download', $justificatif) }}">Telecharger</a>
                </p>
            </article>

            <article class="ui-card mb-3.5 !mb-0">
                <h2>Mise a jour</h2>
                <form method="POST" class="form-shell" action="{{ route('workspace.justificatifs.update', $justificatif) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <div class="form-section">
                        <h3 class="form-section-title">Edition du justificatif</h3>
                        <div>
                            <label for="description">Description</label>
                            <textarea id="description" name="description">{{ old('description', $justificatif->description) }}</textarea>
                        </div>
                        <div class="mt-3">
                            <label for="fichier">Remplacer le fichier (optionnel)</label>
                            <input id="fichier" name="fichier" type="file">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-green" type="submit">Enregistrer</button>
                        <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.justificatifs.index') }}">Retour</a>
                    </div>
                </form>
            </article>
        </div>
    </section>
@endsection
