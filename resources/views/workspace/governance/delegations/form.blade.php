@extends('layouts.workspace')

@section('title', 'Nouvelle delegation')

@section('content')
    <section class="ui-card">
        <h1>Nouvelle delegation</h1>
        <p class="text-slate-600">Configurer une suppleance temporaire avec perimetre et permissions limites.</p>

        <form method="POST" action="{{ route('workspace.delegations.store') }}" class="form-shell mt-4">
            @csrf

            <div class="form-section">
                <div class="form-grid">
                    <div>
                        <label for="delegant_id">Delegant</label>
                        <select id="delegant_id" name="delegant_id" required>
                            <option value="">Selectionner...</option>
                            @foreach ($delegantOptions as $option)
                                <option value="{{ $option->id }}" @selected(old('delegant_id') == $option->id)>
                                    {{ $option->name }} - {{ $option->roleLabel() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="delegue_id">Delegue</label>
                        <select id="delegue_id" name="delegue_id" required>
                            <option value="">Selectionner...</option>
                            @foreach ($delegateOptions as $option)
                                <option value="{{ $option->id }}" @selected(old('delegue_id') == $option->id)>
                                    {{ $option->name }} - {{ $option->roleLabel() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="role_scope">Portee</label>
                        <select id="role_scope" name="role_scope" required>
                            <option value="service" @selected(old('role_scope', $delegation->role_scope) === 'service')>Service</option>
                            <option value="direction" @selected(old('role_scope', $delegation->role_scope) === 'direction')>Direction</option>
                        </select>
                    </div>
                    <div>
                        <label for="direction_id">Direction</label>
                        <select id="direction_id" name="direction_id" required>
                            <option value="">Selectionner...</option>
                            @foreach ($directionOptions as $option)
                                <option value="{{ $option->id }}" @selected(old('direction_id') == $option->id)>
                                    {{ $option->code }} - {{ $option->libelle }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="service_id">Service</label>
                        <select id="service_id" name="service_id">
                            <option value="">Selectionner...</option>
                            @foreach ($serviceOptions as $option)
                                <option value="{{ $option->id }}" @selected(old('service_id') == $option->id)>
                                    {{ $option->code }} - {{ $option->libelle }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="date_debut">Date debut</label>
                        <input id="date_debut" name="date_debut" type="datetime-local" value="{{ old('date_debut', $delegation->date_debut) }}" required>
                    </div>
                    <div>
                        <label for="date_fin">Date fin</label>
                        <input id="date_fin" name="date_fin" type="datetime-local" value="{{ old('date_fin', $delegation->date_fin) }}" required>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Permissions</h2>
                <div class="flex flex-wrap gap-3">
                    @php($oldPermissions = old('permissions', $delegation->permissions ?? []))
                    @foreach (['planning_read' => 'Lecture planning', 'planning_write' => 'Ecriture planning', 'action_review' => 'Validation actions'] as $value => $label)
                        <label class="checkbox-pill">
                            <input type="checkbox" name="permissions[]" value="{{ $value }}" @checked(in_array($value, $oldPermissions, true))>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="form-section">
                <label for="motif">Motif</label>
                <textarea id="motif" name="motif" required>{{ old('motif') }}</textarea>
            </div>

            <div class="form-actions">
                <button class="btn btn-blue" type="submit">Enregistrer delegation</button>
                <a class="btn btn-secondary" href="{{ route('workspace.delegations.index') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection
