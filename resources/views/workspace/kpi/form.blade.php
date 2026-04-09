@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <section class="ui-card mb-3.5">
        <h1>{{ $isEdit ? 'Modifier indicateur' : 'Nouvel indicateur' }}</h1>
        <p class="text-slate-600">Configurer l indicateur principal de suivi.</p>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.kpi.update', $row) : route('workspace.kpi.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Parametrage de l indicateur</h2>
                <p class="form-section-subtitle">Parametres de suivi de l indicateur.</p>
                <div class="form-grid">
                    <div>
                        <label for="action_id">Action</label>
                        <select id="action_id" name="action_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($actionOptions as $action)
                                <option value="{{ $action->id }}" @selected((int) old('action_id', $row->action_id) === $action->id)>
                                    #{{ $action->id }} - {{ $action->libelle }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="periodicite">Periodicite</label>
                        <select id="periodicite" name="periodicite" required>
                            @foreach ($periodiciteOptions as $periodicite)
                                <option value="{{ $periodicite }}" @selected(old('periodicite', $row->periodicite ?: 'mensuel') === $periodicite)>{{ $periodicite }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="est_a_renseigner">Mode de saisie</label>
                        <select id="est_a_renseigner" name="est_a_renseigner" required>
                            @foreach ($modeSaisieOptions as $value => $label)
                                <option value="{{ $value }}" @selected((string) (int) old('est_a_renseigner', $row->est_a_renseigner ?? true) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="unite">Unite</label>
                        <input id="unite" name="unite" type="text" list="kpi-unit-suggestions" value="{{ old('unite', $row->unite) }}">
                        <datalist id="kpi-unit-suggestions">
                            @foreach (($unitSuggestions ?? []) as $suggestion)
                                <option value="{{ $suggestion }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div>
                        <label for="cible">Cible</label>
                        <input id="cible" name="cible" type="number" step="0.0001" min="0" value="{{ old('cible', $row->cible) }}">
                    </div>
                    <div id="seuil_block" class="conditional-block">
                        <label for="seuil_alerte">Seuil alerte</label>
                        <input id="seuil_alerte" name="seuil_alerte" type="number" step="0.0001" min="0" value="{{ old('seuil_alerte', $row->seuil_alerte) }}">
                    </div>
                </div>
                <div class="mt-3">
                    <label for="libelle">Libelle indicateur</label>
                    <input id="libelle" name="libelle" type="text" value="{{ old('libelle', $row->libelle) }}" required>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                <a class="btn btn-secondary" href="{{ route('workspace.kpi.index') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection

@push('scripts')
    <script>
        (function () {
            var cibleInput = document.getElementById('cible');
            var seuilInput = document.getElementById('seuil_alerte');
            var seuilBlock = document.getElementById('seuil_block');

            function syncSeuilState() {
                if (!cibleInput || !seuilInput || !seuilBlock) {
                    return;
                }

                var hasCible = (cibleInput.value || '').trim() !== '';
                seuilInput.disabled = !hasCible;
                seuilBlock.classList.toggle('is-frozen', !hasCible);

                if (!hasCible) {
                    seuilInput.value = '';
                    seuilInput.removeAttribute('max');
                    return;
                }

                seuilInput.max = cibleInput.value;
                if (seuilInput.value && parseFloat(seuilInput.value) > parseFloat(cibleInput.value)) {
                    seuilInput.value = cibleInput.value;
                }
            }

            if (cibleInput) {
                cibleInput.addEventListener('input', syncSeuilState);
            }

            syncSeuilState();
        })();
    </script>
@endpush
