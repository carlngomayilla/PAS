@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <section class="ui-card mb-3.5">
        <h1>{{ $isEdit ? 'Modifier axe PAS' : 'Nouvel axe PAS' }}</h1>
        <p class="text-slate-600">Un axe strategique est rattache a un PAS valide.</p>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.pas-axes.update', $row) : route('workspace.pas-axes.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Definition de l axe PAS</h2>
                <p class="form-section-subtitle">Grille homogene pour garder des champs de longueur identique.</p>
                <div class="form-grid">
                    <div>
                        <label for="pas_id">PAS</label>
                        <select id="pas_id" name="pas_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($pasOptions as $pas)
                                <option value="{{ $pas->id }}" @selected((int) old('pas_id', $row->pas_id) === $pas->id)>
                                    #{{ $pas->id }} - {{ $pas->titre }} ({{ $pas->periode_debut }}-{{ $pas->periode_fin }})
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
                <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.pas-axes.index') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection
