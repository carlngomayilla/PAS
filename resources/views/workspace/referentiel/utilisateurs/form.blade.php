@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
        $roleLabels = app(\App\Services\RoleRegistryService::class)->labels();
        // Mapping rôle → contextes de direction autorisés.
        //   none = aucune direction selectionnee
        //   dg   = direction de code "DG"
        //   op   = toute autre direction operationnelle (DAF, DS, DSIC, ...)
        // L'UI cache dynamiquement les options non applicables en JS.
        $roleContextRules = [
            // Sans rattachement (aucune direction requise).
            'super_admin' => ['none'],                  // Technique
            'admin_fonctionnel' => ['none'],            // Transverse
            'auditeur' => ['none'],                     // Lecture globale

            // Transverse — disponible sur toute direction ou sans direction.
            'planification' => ['none', 'dg', 'op'],    // Typiquement DS ou DG
            'chef_planification' => ['dg', 'op'],       // Controle principal rattache a une entite

            // Réservés à la DG (Direction Générale).
            'dg' => ['dg'],
            'cabinet' => ['dg'],
            'chef_unite_cabinet' => ['dg'],
            'dga_supervision' => ['dg'],
            'chef_unite_dga' => ['dg'],
            'chef_unite_ucas' => ['dg'],
            'ucas' => ['dg'],
            'sciq' => ['dg'],
            'chef_unite_sciq' => ['dg'],

            // Opérationnels — DAF / DS / DSIC / etc. (toute direction sauf DG).
            'direction' => ['op'],
            'service' => ['op'],
            'agent' => ['op'],
        ];
        $defaultContexts = ['none', 'dg', 'op'];
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
                        <select id="role" name="role" required data-role-selector>
                            <option value="">Sélectionner</option>
                            @foreach ($roleOptions as $role)
                                @php
                                    $contexts = $roleContextRules[$role] ?? $defaultContexts;
                                @endphp
                                <option
                                    value="{{ $role }}"
                                    data-allow-contexts="{{ implode(' ', $contexts) }}"
                                    @selected(old('role', $row->role) === $role)
                                >{{ $roleLabels[$role] ?? $role }}</option>
                            @endforeach
                        </select>
                        <p class="field-hint" data-role-context-hint>Sélectionnez d'abord la direction pour filtrer les rôles applicables.</p>
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
                            var roleSelect = document.querySelector('[data-role-selector]');
                            var roleHint = document.querySelector('[data-role-context-hint]');
                            if (! directionSelect || ! serviceField || ! uniteField) return;

                            function selectedDirectionCode() {
                                var opt = directionSelect.options[directionSelect.selectedIndex];
                                return opt ? (opt.getAttribute('data-direction-code') || '') : '';
                            }

                            function currentRoleContext() {
                                if (! directionSelect.value) return 'none';
                                return selectedDirectionCode() === 'DG' ? 'dg' : 'op';
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

                            function filterRoleOptions() {
                                if (! roleSelect) return;
                                var context = currentRoleContext();
                                var visibleCount = 0;
                                Array.prototype.forEach.call(roleSelect.options, function (opt) {
                                    if (opt.value === '') { opt.hidden = false; return; }
                                    var raw = opt.getAttribute('data-allow-contexts') || '';
                                    var allowed = raw.split(/\s+/).filter(Boolean);
                                    var ok = allowed.length === 0 || allowed.indexOf(context) !== -1;
                                    opt.hidden = ! ok;
                                    opt.disabled = ! ok;
                                    if (ok) visibleCount++;
                                });
                                // Reset si le role courant n'est plus visible.
                                var current = roleSelect.options[roleSelect.selectedIndex];
                                if (current && current.value !== '' && (current.hidden || current.disabled)) {
                                    roleSelect.value = '';
                                }
                                if (roleHint) {
                                    var hintByCtx = {
                                        'none': 'Aucune direction : profils transverses (super admin, admin fonctionnel, auditeur, planification).',
                                        'dg': 'Direction DG : profils DG, planification, cabinet, chef d\'unité (Cabinet/UCAS/SCIQ), supervision DGA, UCAS, SCIQ.',
                                        'op': 'Direction opérationnelle (DAF / DS / DSIC...) : profils direction, chef de service, agent, planification.'
                                    };
                                    roleHint.textContent = hintByCtx[context] || '';
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

                                filterRoleOptions();
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
                            <label for="agent_telephone">Téléphone</label>
                            <input id="agent_telephone" name="agent_telephone" type="text" value="{{ old('agent_telephone', $row->agent_telephone) }}" placeholder="+241 ...">
                        </div>
                    </div>
                </div>

            </div>

            <div class="form-section">
                <h2 class="form-section-title">Sécurité du compte</h2>
                <div class="grid gap-4">
                    <div>
                        <label for="password">{{ $isEdit ? 'Nouveau mot de passe (optionnel)' : 'Mot de passe (optionnel)' }}</label>
                        <div class="relative">
                            <input id="password" name="password" type="password" class="pr-16">
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-[#3996d3]" data-password-toggle="password">
                                Voir
                            </button>
                        </div>
                        @unless($isEdit)
                            <p class="mt-1 text-xs text-slate-500">Laissez vide pour appliquer le mot de passe par défaut : <code>Anbg@2026!Pas</code></p>
                        @endunless
                    </div>
                    <div>
                        <label for="password_confirmation">Confirmation mot de passe</label>
                        <div class="relative">
                            <input id="password_confirmation" name="password_confirmation" type="password" class="pr-16">
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-[#3996d3]" data-password-toggle="password_confirmation">
                                Voir
                            </button>
                        </div>
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

<script @cspNonce>
    (function () {
        function initRoleAgentFieldsToggle() {
            var roleSelect = document.getElementById('role');
            var fields = document.getElementById('agent-fields');
            if (!roleSelect || !fields) {
                return;
            }

            function syncAgentFields() {
                var isAgent = roleSelect.value === 'agent';
                fields.classList.toggle('hidden', !isAgent);
                fields.classList.toggle('is-frozen', !isAgent);

                var inputs = fields.querySelectorAll('input, select, textarea');
                inputs.forEach(function (input) {
                    input.disabled = !isAgent;
                });
            }

            roleSelect.addEventListener('change', syncAgentFields);
            syncAgentFields();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initRoleAgentFieldsToggle);
        } else {
            initRoleAgentFieldsToggle();
        }

        document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.dataset.passwordToggle);
                if (! input) {
                    return;
                }

                var isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                button.textContent = isHidden ? 'Cacher' : 'Voir';
            });
        });
    })();
</script>
