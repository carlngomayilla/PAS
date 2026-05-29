@extends('layouts.workspace')

@section('content')
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">Nouvel import</h1>
                <p class="text-sm text-slate-500">Une feuille, un tableau, une ligne par action planifiee.</p>
            </div>
            <a class="btn btn-secondary" href="{{ route('workspace.imports.template') }}">Telecharger le modele Excel</a>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.imports.preview') }}" class="form-shell">
            @csrf
            <div class="form-section">
                <h2 class="form-section-title">Fichier a importer</h2>
                <div class="rounded-lg border-2 border-dashed border-[#3996d3]/40 bg-[#f8fbfe] p-5">
                    <div>
                        <label for="file" class="text-base font-extrabold text-[#1c203d]">Choisir le fichier Excel</label>
                        <input id="file" name="file" type="file" accept=".xlsx,.csv" required class="mt-3 w-full rounded-lg border border-[#d8ecf8] bg-white p-3">
                        <p class="mt-2 text-sm text-slate-500">Apres validation, vous verrez la previsualisation des lignes valides, erreurs et avertissements.</p>
                        @error('file') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Verifier le fichier</button>
                <a class="btn btn-secondary" href="{{ route('workspace.imports.index') }}">Annuler</a>
            </div>
        </form>
    </section>
</div>
@endsection
