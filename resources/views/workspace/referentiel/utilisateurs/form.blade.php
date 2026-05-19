@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <div class="app-screen-flow">
    <section class="showcase-panel mb-4 app-screen-block">
        <h1>{{ $isEdit ? 'Modifier utilisateur' : 'Nouvel utilisateur' }}</h1>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <form method="POST" enctype="multipart/form-data" class="form-shell" action="{{ $isEdit ? route('workspace.referentiel.utilisateurs.update', $row) : route('workspace.referentiel.utilisateurs.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="form-section">
                <h2 class="form-section-title">Profil et rattachement</h2>
                <div class="grid gap-4">
                    <div>
                        <label for="profile_photo">Photo de profil</label>
                        <input id="profile_photo" name="profile_photo" type="file" accept=".jpg,.jpeg,.png,.webp">
                        <p class="field-hint">JPG, PNG ou WEBP. Max 3 Mo.</p>
                        @if ($isEdit && $row->profile_photo_url)
                            <div class="mt-2 flex items-center gap-2">
                                <img src="{{ $row->profile_photo_url }}" alt="Photo actuelle de {{ $row->name }}" class="h-12 w-12 rounded-full object-cover ring-2 ring-white shadow-sm">
                                <label class="!mb-0 inline-flex items-center gap-2 text-sm text-slate-600">
                                    <input type="checkbox" name="remove_profile_photo" value="1" @checked(old('remove_profile_photo'))>
                                    Supprimer la photo actuelle
                                </label>
                            </div>
                        @endif
                    </div>
                    <div>
                        <label for="name">Nom</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $row->name) }}" required>
                    </div>
                    <div>
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email', $row->email) }}" required>
                    </div>
                    <div>
                        <label for="role">Rôle</label>
                        <select id="role" name="role" required>
                            <option value="">Sélectionner</option>
                            @foreach ($roleOptions as $role)
                                <option value="{{ $role }}" @selected(old('role', $row->role) === $role)>{{ $role }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="!mb-2 block" for="is_active">État du compte</label>
                        <label class="checkbox-pill !mb-0">
                            <input id="is_active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $isEdit ? (int) $row->is_active : 1))>
                            Compte actif
                        </label>
                    </div>
                    <div>
                        <label for="direction_id">Direction</label>
                        <select id="direction_id" name="direction_id" data-direction-selector>
                            <option value="">Aucune</option>
                            @foreach ($directionOptions as $direction)
                                <option
                                    value="{{ $direction->id }}"
                                    data-direction-code="{{ $direction->code }}"
                                    @selected((int) old('direction_id', $row->direction_id) === $direction->id)
                                >
                                    {{ $direction->code }} - {{ $direction->libelle }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div data-service-field>
                        <label for="service_id">Service</label>
                        <select id="service_id" name="service_id" data-service-selector>
                            <option value="">Aucun</option>
                            @foreach ($serviceOptions as $service)
                                <option
                                    value="{{ $service->id }}"
                                    data-direction-id="{{ $service->direction_id }}"
                                    @selected((int) old('service_id', $row->service_id) === $service->id)
                                >
                                    {{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div data-unite-dg-field>
                        <label for="unite_dg_id">Unité DG</label>
                        <select id="unite_dg_id" name="unite_dg_id">
                            <option value="">Aucune</option>
                            @foreach ($uniteDgOptions ?? [] as $unite)
                                <option value="{{ $unite->id }}" @selected((int) old('unite_dg_id', $row->unite_dg_id) === $unite->id)>
                                    {{ $unite->code }} - {{ $unite->libelle }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Adaptation dynamique : DG → Unité DG visible / autre → Service visible.
                     Script inline (hors @push) pour garantir l'exécution quel que soit le layout. --}}
                <script @cspNonce>
                    (function () {
                        function initDirectionFieldsToggle() {
                            var directionSelect = document.querySelector('[data-direction-selector]');
                            var serviceField = document.querySelector('[data-service-field]');
                            var serviceSelect = document.querySelector('[data-service-selector]');
                            var uniteField = document.querySelector('[data-unite-dg-field]');
                            var uniteSelect = document.getElementById('unite_dg_id');
                            if (! directionSelect || ! serviceField || ! uniteField) return;

                            function selectedDirectionCode() {
                                var opt = directionSelect.options[directionSelect.selectedIndex];
                                return opt ? (opt.getAttribute('data-direction-code') || '') : '';
                            }

                            function filterServiceOptions(directionId) {
                                if (! serviceSelect) return;
                                Array.prototype.forEach.call(serviceSelect.options, function (opt) {
                                    if (opt.value === '') { opt.hidden = false; return; }
                                    var optDir = opt.getAttribute('data-direction-id');
                                    opt.hidden = directionId !== '' && optDir !== String(directionId);
                                });
                                if (serviceSelect.options[serviceSelect.selectedIndex] && serviceSelect.options[serviceSelect.selectedIndex].hidden) {
                                    serviceSelect.value = '';
                                }
                            }

                            function syncFields() {
                                var code = selectedDirectionCode();
                                var directionId = directionSelect.value;

                                if (code === 'DG') {
                                    serviceField.style.display = 'none';
                                    if (serviceSelect) serviceSelect.value = '';
                                    uniteField.style.display = '';
                                } else {
                                    serviceField.style.display = '';
                                    uniteField.style.display = 'none';
                                    if (uniteSelect) uniteSelect.value = '';
                                    filterServiceOptions(directionId);
                                }
                            }

                            directionSelect.addEventListener('change', syncFields);
                            syncFields();
                        }

                        if (document.readyState === 'loading') {
                            document.addEventListener('DOMContentLoaded', initDirectionFieldsToggle);
                        } else {
                            initDirectionFieldsToggle();
                        }
                    })();
                </script>

                <div id="agent-fields" class="conditional-block mt-4">
                    <div class="form-grid-compact mt-2">
                        <div>
                            <label for="agent_matricule">Matricule agent</label>
                            <input id="agent_matricule" name="agent_matricule" type="text" value="{{ old('agent_matricule', $row->agent_matricule) }}">
                        </div>
                        <div>
                            <label for="agent_fonction">Fonction</label>
                            <input id="agent_fonction" name="agent_fonction" type="text" value="{{ old('agent_fonction', $row->agent_fonction) }}" placeholder="Ex: Charge du suivi hebdomadaire">
                        </div>
                        <div>
                            <label for="agent_telephone">Telephone</label>
                            <input id="agent_telephone" name="agent_telephone" type="text" value="{{ old('agent_telephone', $row->agent_telephone) }}" placeholder="+241 ...">
                        </div>
                    </div>
                </div>

            </div>

            <div class="form-section">
                <h2 class="form-section-title">Sécurité du compte</h2>
                <div class="grid gap-4">
                    <div>
                        <label for="password">{{ $isEdit ? 'Nouveau mot de passe (optionnel)' : 'Mot de passe' }}</label>
                        <input id="password" name="password" type="password" {{ $isEdit ? '' : 'required' }}>
                    </div>
                    <div>
                        <label for="password_confirmation">Confirmation mot de passe</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" {{ $isEdit ? '' : 'required' }}>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Mettre à jour' : 'Créer' }}</button>
                <a class="btn btn-secondary" href="{{ route('workspace.referentiel.utilisateurs.index') }}">Retour</a>
            </div>
        </form>
    </section>
    </div>
@endsection

@push('scripts')
    <script @cspNonce>
        (function () {
            var rôle = document.getElementById('role');
            var fields = document.getElementById('agent-fields');

            function syncAgentFields() {
                if (!rôle || !fields) {
                    return;
                }

                var isAgent = role.value === 'agent';
                fields.classList.toggle('hidden', !isAgent);
                fields.classList.toggle('is-frozen', !isAgent);

                var inputs = fields.querySelectorAll('input, select, textarea');
                inputs.forEach(function (input) {
                    input.disabled = !isAgent;
                });
            }

            if (role) {
                role.addEventListener('change', syncAgentFields);
            }

            syncAgentFields();
        })();
    </script>
@endpush
