@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <div class="app-screen-flow">
    <section class="ui-card mb-3.5 app-screen-block">
        <h1>{{ $isEdit ? 'Modifier axe PAO' : 'Nouvel axe PAO' }}</h1>
    </section>

    <section class="ui-card mb-3.5 app-screen-block">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.pao-axes.update', $row) : route('workspace.pao-axes.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Definition de l axe</h2>
                <div class="form-grid">
                    <div>
                        <label for="pao_id">PAO</label>
                        <select id="pao_id" name="pao_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($paoOptions as $pao)
                                <option value="{{ $pao->id }}" @selected((int) old('pao_id', $row->pao_id) === $pao->id)>
                                    #{{ $pao->id }} - {{ $pao->titre }} ({{ $pao->annee }})
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
                        <label for="ordre">Ordre</label>
                        <input id="ordre" name="ordre" type="number" min="1" value="{{ old('ordre', $row->ordre ?: 1) }}">
                    </div>
                </div>

                <div class="mt-3">
                    <label for="description">Description</label>
                    <textarea id="description" name="description">{{ old('description', $row->description) }}</textarea>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-green" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                <a class="btn btn-blue" href="{{ route('workspace.pao-axes.index') }}">Retour</a>
            </div>
        </form>
    </section>
    </div>
@endsection
