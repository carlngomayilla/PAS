@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <section class="ui-card mb-3.5">
        <h1>{{ $isEdit ? 'Modifier service' : 'Nouveau service' }}</h1>
        <p class="text-slate-600">Chaque service est rattache a une direction.</p>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.referentiel.services.update', $row) : route('workspace.referentiel.services.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Parametrage service</h2>
                <p class="form-section-subtitle">Structure alignee au standard de saisie moderne.</p>
                <div class="form-grid">
                    <div>
                        <label for="direction_id">Direction</label>
                        <select id="direction_id" name="direction_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($directionOptions as $direction)
                                <option value="{{ $direction->id }}" @selected((int) old('direction_id', $row->direction_id) === $direction->id)>
                                    {{ $direction->code }} - {{ $direction->libelle }}
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
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.services.index') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection
