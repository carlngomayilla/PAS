@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <div class="app-screen-flow">
    <x-ui.page-title class="mb-4 app-screen-block" eyebrow="Administration organisationnelle" :title="$isEdit ? 'Modifier direction' : 'Nouvelle direction'" />

    <section class="showcase-panel mb-4 app-screen-block">
        <form method="POST" class="form-shell reco-form-shell" action="{{ $isEdit ? route('workspace.referentiel.directions.update', $row) : route('workspace.referentiel.directions.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section reco-form-section">
                <h2 class="form-section-title">Paramétrage direction</h2>
                <div class="form-grid reco-form-grid">
                    <div>
                        <label for="code">Code</label>
                        <input id="code" name="code" type="text" maxlength="30" value="{{ old('code', $row->code) }}" required>
                    </div>
                    <div>
                        <label for="libelle">Libellé</label>
                        <input id="libelle" name="libelle" type="text" value="{{ old('libelle', $row->libelle) }}" required>
                    </div>
                    <div>
                        <label for="actif">Actif</label>
                        <select id="actif" name="actif" required>
                            <option value="1" @selected((int) old('actif', $row->actif ? 1 : 1) === 1)>Oui</option>
                            <option value="0" @selected((int) old('actif', $row->actif ? 1 : 0) === 0)>Non</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-actions reco-form-footer">
                <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Mettre à jour' : 'Créer' }}</button>
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.directions.index') }}">Retour</a>
            </div>
        </form>
    </section>
    </div>
@endsection
