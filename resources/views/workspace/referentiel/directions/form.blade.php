@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <section class="ui-card mb-3.5">
        <h1>{{ $isEdit ? 'Modifier direction' : 'Nouvelle direction' }}</h1>
        <p class="text-slate-600">Parametrage du referentiel direction.</p>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.referentiel.directions.update', $row) : route('workspace.referentiel.directions.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Parametrage direction</h2>
                <p class="form-section-subtitle">Meme format visuel que les autres ecrans CRUD.</p>
                <div class="form-grid">
                    <div>
                        <label for="code">Code</label>
                        <input id="code" name="code" type="text" maxlength="30" value="{{ old('code', $row->code) }}" required>
                    </div>
                    <div>
                        <label for="libelle">Libelle</label>
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

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.directions.index') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection
