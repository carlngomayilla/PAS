@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <section class="ui-card mb-3.5">
        <h1>{{ $isEdit ? 'Modifier mesure KPI' : 'Nouvelle mesure KPI' }}</h1>
        <p class="text-slate-600">Saisie de la valeur mesuree pour une periode donnee.</p>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.kpi-mesures.update', $row) : route('workspace.kpi-mesures.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Saisie de mesure</h2>
                <p class="form-section-subtitle">Le format de periode se fige selon la periodicite du KPI choisi.</p>
                <div class="form-grid">
                    <div>
                        <label for="kpi_id">KPI</label>
                        <select id="kpi_id" name="kpi_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($kpiOptions as $kpi)
                                <option
                                    value="{{ $kpi->id }}"
                                    data-periodicite="{{ $kpi->periodicite }}"
                                    @selected((int) old('kpi_id', $row->kpi_id) === $kpi->id)
                                >
                                    #{{ $kpi->id }} - {{ $kpi->libelle }} ({{ $kpi->periodicite }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div id="periode_block" class="conditional-block">
                        <label for="periode">Periode</label>
                        <input id="periode" name="periode" type="text" maxlength="20" value="{{ old('periode', $row->periode) }}" required>
                        <p id="periode_hint" class="field-hint">Selectionner un KPI pour obtenir le format attendu.</p>
                    </div>
                    <div>
                        <label for="valeur">Valeur</label>
                        <input id="valeur" name="valeur" type="number" step="0.0001" min="0" value="{{ old('valeur', $row->valeur) }}" required>
                    </div>
                    <div>
                        <label for="saisi_par">Saisi par</label>
                        <select id="saisi_par" name="saisi_par">
                            <option value="">Utilisateur connecte</option>
                            @foreach ($saisiParOptions as $user)
                                <option value="{{ $user->id }}" @selected((int) old('saisi_par', $row->saisi_par) === $user->id)>
                                    {{ $user->name }} ({{ $user->email }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-3">
                    <label for="commentaire">Commentaire</label>
                    <textarea id="commentaire" name="commentaire">{{ old('commentaire', $row->commentaire) }}</textarea>
                </div>
            </div>

            <div class="form-actions">
                <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.kpi-mesures.index') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection

@push('scripts')
    <script>
        (function () {
            var kpiSelect = document.getElementById('kpi_id');
            var periodeInput = document.getElementById('periode');
            var periodeHint = document.getElementById('periode_hint');
            var periodeBlock = document.getElementById('periode_block');

            function periodiciteHint(periodicite) {
                switch (periodicite) {
                    case 'mensuel':
                        return 'Format conseille: AAAA-MM (ex: 2026-03)';
                    case 'trimestriel':
                        return 'Format conseille: AAAA-Tn (ex: 2026-T1)';
                    case 'semestriel':
                        return 'Format conseille: AAAA-Sn (ex: 2026-S1)';
                    case 'annuel':
                        return 'Format conseille: AAAA (ex: 2026)';
                    case 'ponctuel':
                        return 'Format libre: date ou libelle ponctuel.';
                    default:
                        return 'Selectionner un KPI pour obtenir le format attendu.';
                }
            }

            function syncPeriodeField() {
                if (!kpiSelect || !periodeInput || !periodeHint || !periodeBlock) {
                    return;
                }

                var option = kpiSelect.options[kpiSelect.selectedIndex];
                var periodicite = option ? (option.getAttribute('data-periodicite') || '') : '';
                var enabled = !!periodicite;

                periodeInput.disabled = !enabled;
                periodeBlock.classList.toggle('is-frozen', !enabled);
                periodeHint.textContent = periodiciteHint(periodicite);

                if (!enabled) {
                    periodeInput.value = '';
                    periodeInput.placeholder = '';
                    return;
                }

                switch (periodicite) {
                    case 'mensuel':
                        periodeInput.placeholder = 'AAAA-MM';
                        break;
                    case 'trimestriel':
                        periodeInput.placeholder = 'AAAA-T1';
                        break;
                    case 'semestriel':
                        periodeInput.placeholder = 'AAAA-S1';
                        break;
                    case 'annuel':
                        periodeInput.placeholder = 'AAAA';
                        break;
                    default:
                        periodeInput.placeholder = 'Periode';
                        break;
                }
            }

            if (kpiSelect) {
                kpiSelect.addEventListener('change', syncPeriodeField);
            }

            syncPeriodeField();
        })();
    </script>
@endpush
