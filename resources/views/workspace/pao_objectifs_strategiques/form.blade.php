@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <section class="ui-card mb-3.5">
        <h1>{{ $isEdit ? 'Modifier objectif strategique PAO' : 'Nouvel objectif strategique PAO' }}</h1>
        <p class="text-slate-600">Declinaison strategique annuelle par axe PAO.</p>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.pao-objectifs-strategiques.update', $row) : route('workspace.pao-objectifs-strategiques.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Objectif strategique</h2>
                <p class="form-section-subtitle">Organisation claire et uniforme des champs de saisie.</p>
                <div class="form-grid">
                    <div>
                        <label for="pao_axe_id">Axe PAO</label>
                        <select id="pao_axe_id" name="pao_axe_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($paoAxeOptions as $axe)
                                <option value="{{ $axe->id }}" @selected((int) old('pao_axe_id', $row->pao_axe_id) === $axe->id)>
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
                        <label for="echeance">Echeance</label>
                        <input id="echeance" name="echeance" type="date" value="{{ old('echeance', $row->echeance) }}">
                    </div>
                </div>

                <div class="mt-3">
                    <label for="description">Description</label>
                    <textarea id="description" name="description">{{ old('description', $row->description) }}</textarea>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-green" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                <a class="btn btn-blue" href="{{ route('workspace.pao-objectifs-strategiques.index') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection
