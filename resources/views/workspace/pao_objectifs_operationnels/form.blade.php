@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <section class="ui-card mb-3.5">
        <h1>{{ $isEdit ? 'Modifier objectif operationnel' : 'Nouvel objectif operationnel' }}</h1>
        <p class="text-slate-600">Action detaillee, responsable, cible, dates, statut, ressources, risques et livrables.</p>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.pao-objectifs-operationnels.update', $row) : route('workspace.pao-objectifs-operationnels.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Planification et execution</h2>
                <p class="form-section-subtitle">Les champs principaux sont alignes sur la meme grille et la meme longueur.</p>
                <div class="form-grid">
                    <div>
                        <label for="pao_objectif_strategique_id">Objectif strategique PAO</label>
                        <select id="pao_objectif_strategique_id" name="pao_objectif_strategique_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($objectifStrategiqueOptions as $objectif)
                                <option value="{{ $objectif->id }}" @selected((int) old('pao_objectif_strategique_id', $row->pao_objectif_strategique_id) === $objectif->id)>
                                    #{{ $objectif->id }} - {{ $objectif->code }} | {{ $objectif->libelle }}
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
                        <label for="responsable_id">Responsable de la tache</label>
                        <select id="responsable_id" name="responsable_id" required>
                            <option value="">Selectionner</option>
                            @foreach ($responsableOptions as $responsable)
                                <option value="{{ $responsable->id }}" @selected((int) old('responsable_id', $row->responsable_id) === $responsable->id)>
                                    {{ $responsable->name }} ({{ $responsable->email }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="statut_realisation">Statut de realisation</label>
                        <select id="statut_realisation" name="statut_realisation" required>
                            @foreach ($statusOptions as $status)
                                <option value="{{ $status }}" @selected(old('statut_realisation', $row->statut_realisation ?: 'non_demarre') === $status)>
                                    {{ $status }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="priorite">Priorite</label>
                        <select id="priorite" name="priorite" required>
                            @foreach ($prioriteOptions as $priorite)
                                <option value="{{ $priorite }}" @selected(old('priorite', $row->priorite ?: 'moyenne') === $priorite)>
                                    {{ $priorite }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="cible_pourcentage">Cible (%)</label>
                        <input id="cible_pourcentage" name="cible_pourcentage" type="number" step="0.01" min="0" max="100" value="{{ old('cible_pourcentage', $row->cible_pourcentage ?? 0) }}" required>
                    </div>
                    <div>
                        <label for="progression_pourcentage">Progression (%)</label>
                        <input id="progression_pourcentage" name="progression_pourcentage" type="number" min="0" max="100" value="{{ old('progression_pourcentage', $row->progression_pourcentage ?? 0) }}" required>
                    </div>
                    <div>
                        <label for="date_debut">Date debut</label>
                        <input id="date_debut" name="date_debut" type="date" value="{{ old('date_debut', $row->date_debut) }}" required>
                    </div>
                    <div>
                        <label for="date_fin">Date fin</label>
                        <input id="date_fin" name="date_fin" type="date" value="{{ old('date_fin', $row->date_fin) }}" required>
                    </div>
                    <div>
                        <label for="echeance">Echeance</label>
                        <input id="echeance" name="echeance" type="date" value="{{ old('echeance', $row->echeance) }}">
                    </div>
                    <div>
                        <label for="date_realisation">Date realisation</label>
                        <input id="date_realisation" name="date_realisation" type="date" value="{{ old('date_realisation', $row->date_realisation) }}">
                    </div>
                    <div>
                        <label for="ordre">Ordre</label>
                        <input id="ordre" name="ordre" type="number" min="1" value="{{ old('ordre', $row->ordre ?: 1) }}">
                    </div>
                </div>

                <div class="mt-3">
                    <label for="description_action_detaillee">Description de l action detaillee</label>
                    <textarea id="description_action_detaillee" name="description_action_detaillee" required>{{ old('description_action_detaillee', $row->description_action_detaillee) }}</textarea>
                </div>

                <div class="mt-3">
                    <label for="indicateur_performance">Indicateur de performance</label>
                    <input id="indicateur_performance" name="indicateur_performance" type="text" value="{{ old('indicateur_performance', $row->indicateur_performance) }}" required>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Ressources et risques</h2>
                <div class="form-grid">
                    <div>
                        <label for="ressources_requises">Ressources requises</label>
                        <textarea id="ressources_requises" name="ressources_requises">{{ old('ressources_requises', $row->ressources_requises) }}</textarea>
                    </div>
                    <div>
                        <label for="risques_potentiels">Risques potentiels</label>
                        <textarea id="risques_potentiels" name="risques_potentiels">{{ old('risques_potentiels', $row->risques_potentiels) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Livrables et contraintes</h2>
                <div class="form-grid">
                    <div>
                        <label for="livrable_attendu">Livrable attendu</label>
                        <textarea id="livrable_attendu" name="livrable_attendu">{{ old('livrable_attendu', $row->livrable_attendu) }}</textarea>
                    </div>
                    <div>
                        <label for="contraintes">Contraintes</label>
                        <textarea id="contraintes" name="contraintes">{{ old('contraintes', $row->contraintes) }}</textarea>
                    </div>
                    <div>
                        <label for="dependances">Dependances</label>
                        <textarea id="dependances" name="dependances">{{ old('dependances', $row->dependances) }}</textarea>
                    </div>
                    <div>
                        <label for="observations">Observations</label>
                        <textarea id="observations" name="observations">{{ old('observations', $row->observations) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-green-700 text-white hover:bg-green-600" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                <a class="inline-flex items-center justify-center rounded-md px-2.5 py-1.5 text-sm font-medium no-underline bg-blue-700 text-white hover:bg-blue-600" href="{{ route('workspace.pao-objectifs-operationnels.index') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection
