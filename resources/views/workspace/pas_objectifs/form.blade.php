@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <section class="ui-card mb-3.5">
        <h1>{{ $isEdit ? 'Modifier objectif PAS' : 'Nouvel objectif PAS' }}</h1>
        <p class="text-slate-600">Definition des objectifs strategiques rattaches a un axe PAS.</p>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.pas-objectifs.update', $row) : route('workspace.pas-objectifs.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Parametrage de l objectif</h2>
                <p class="form-section-subtitle">Champs homogenes, plus lisibles et plus aeres.</p>
                <div class="form-grid">
                    <div>
                        <label for="pas_axe_id">Axe PAS</label>
                        <select id="pas_axe_id" name="pas_axe_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($pasAxeOptions as $axe)
                                <option value="{{ $axe->id }}" @selected((int) old('pas_axe_id', $row->pas_axe_id) === $axe->id)>
                                    #{{ $axe->id }} - {{ $axe->code }} | {{ $axe->libelle }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="code">Code</label>
                        <input id="code" name="code" type="text" maxlength="30" value="{{ old('code', $row->code) }}" required>
                    </div>
                    <div>
                        <label for="libelle">Libelle</label>
                        <input id="libelle" name="libelle" type="text" value="{{ old('libelle', $row->libelle) }}" required>
                    </div>
                    <div>
                        <label for="indicateur_global">Indicateur global</label>
                        <input id="indicateur_global" name="indicateur_global" type="text" value="{{ old('indicateur_global', $row->indicateur_global) }}">
                    </div>
                    <div>
                        <label for="valeur_cible">Valeur cible</label>
                        <input id="valeur_cible" name="valeur_cible" type="text" value="{{ old('valeur_cible', $row->valeur_cible) }}">
                    </div>
                </div>

                <div class="mt-3">
                    <label for="description">Description</label>
                    <textarea id="description" name="description">{{ old('description', $row->description) }}</textarea>
                </div>
            </div>

            <div class="form-actions">
                <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.pas-objectifs.index') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection
