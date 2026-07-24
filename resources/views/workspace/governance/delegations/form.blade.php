@extends('layouts.workspace')

@section('title', 'Nouvelle délégation')

@section('content')
    @php
        $oldPermissions = old('permissions', $delegation->permissions ?? []);
        $oldPermissions = is_array($oldPermissions) ? $oldPermissions : [];
        $startValue = old('date_debut', optional($delegation->date_debut)->format('Y-m-d\TH:i'));
        $endValue = old('date_fin', optional($delegation->date_fin)->format('Y-m-d\TH:i'));
    @endphp

    <div class="app-screen-flow">
        <section class="app-screen-block border-b border-slate-200 pb-4 dark:border-slate-700">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Gouvernance / Délégations</p>
                    <h1 class="mt-1 text-2xl font-bold text-slate-950 dark:text-white">Nouvelle délégation</h1>
                </div>
                <a class="btn btn-secondary" href="{{ route('workspace.delegations.index') }}">Retour au registre</a>
            </div>
        </section>

        <form method="POST" action="{{ route('workspace.delegations.store') }}" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_19rem] xl:items-start">
            @csrf

            <div class="grid gap-4">
                <section class="ui-card app-screen-block">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h2 class="text-base font-bold text-slate-950 dark:text-white">Périmètre et acteurs</h2>
                        <span class="anbg-badge anbg-badge-info">1 / 3</span>
                    </div>

                    <div class="form-grid">
                        <label class="grid gap-1" for="role_scope">
                            <span class="text-sm font-semibold">Portée</span>
                            <select id="role_scope" name="role_scope" required>
                                <option value="service" @selected(old('role_scope', $delegation->role_scope) === 'service')>Service</option>
                                <option value="direction" @selected(old('role_scope', $delegation->role_scope) === 'direction')>Direction</option>
                            </select>
                            @error('role_scope') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="grid gap-1" for="direction_id">
                            <span class="text-sm font-semibold">Direction</span>
                            <select id="direction_id" name="direction_id" required>
                                <option value="">Sélectionner</option>
                                @foreach ($directionOptions as $option)
                                    <option value="{{ $option->id }}" @selected(old('direction_id') == $option->id)>
                                        {{ $option->code }} - {{ $option->libelle }}
                                    </option>
                                @endforeach
                            </select>
                            @error('direction_id') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </label>

                        <label id="service-field" class="grid gap-1" for="service_id">
                            <span class="text-sm font-semibold">Service</span>
                            <select id="service_id" name="service_id">
                                <option value="">Sélectionner</option>
                                @foreach ($serviceOptions as $option)
                                    <option value="{{ $option->id }}" data-direction-id="{{ $option->direction_id }}" @selected(old('service_id') == $option->id)>
                                        {{ $option->code }} - {{ $option->libelle }}
                                    </option>
                                @endforeach
                            </select>
                            @error('service_id') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="grid gap-1" for="delegant_id">
                            <span class="text-sm font-semibold">Délégant</span>
                            <select id="delegant_id" name="delegant_id" required>
                                <option value="">Sélectionner</option>
                                @foreach ($delegantOptions as $option)
                                    <option
                                        value="{{ $option->id }}"
                                        data-role="{{ $option->role }}"
                                        data-direction-id="{{ $option->direction_id }}"
                                        data-service-id="{{ $option->service_id }}"
                                        @selected(old('delegant_id') == $option->id)
                                    >
                                        {{ $option->name }} - {{ $option->roleLabel() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('delegant_id') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="grid gap-1" for="delegue_id">
                            <span class="text-sm font-semibold">Bénéficiaire</span>
                            <select id="delegue_id" name="delegue_id" required>
                                <option value="">Sélectionner</option>
                                @foreach ($delegateOptions as $option)
                                    <option value="{{ $option->id }}" @selected(old('delegue_id') == $option->id)>
                                        {{ $option->name }} - {{ $option->roleLabel() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('delegue_id') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </section>

                <section class="ui-card app-screen-block">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h2 class="text-base font-bold text-slate-950 dark:text-white">Période et habilitations</h2>
                        <span class="anbg-badge anbg-badge-info">2 / 3</span>
                    </div>

                    <div class="form-grid">
                        <label class="grid gap-1" for="date_debut">
                            <span class="text-sm font-semibold">Début</span>
                            <input id="date_debut" name="date_debut" type="datetime-local" value="{{ $startValue }}" required>
                            @error('date_debut') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="grid gap-1" for="date_fin">
                            <span class="text-sm font-semibold">Fin</span>
                            <input id="date_fin" name="date_fin" type="datetime-local" value="{{ $endValue }}" required>
                            @error('date_fin') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </label>
                    </div>

                    <fieldset class="mt-4">
                        <legend class="text-sm font-semibold">Permissions déléguées</legend>
                        <div class="mt-2 grid gap-2 md:grid-cols-3">
                            @foreach (['planning_read' => 'Lecture planning', 'planning_write' => 'Écriture planning', 'action_review' => 'Validation actions'] as $value => $label)
                                <label class="checkbox-pill min-h-11">
                                    <input type="checkbox" name="permissions[]" value="{{ $value }}" @checked(in_array($value, $oldPermissions, true))>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('permissions') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                        @error('permissions.*') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </fieldset>
                </section>

                <section class="ui-card app-screen-block">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h2 class="text-base font-bold text-slate-950 dark:text-white">Justification</h2>
                        <span class="anbg-badge anbg-badge-info">3 / 3</span>
                    </div>
                    <label class="grid gap-1" for="motif">
                        <span class="text-sm font-semibold">Motif de la délégation</span>
                        <textarea id="motif" name="motif" rows="4" minlength="5" maxlength="1000" required>{{ old('motif') }}</textarea>
                        @error('motif') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                </section>
            </div>

            <aside class="ui-card app-screen-block xl:sticky xl:top-4">
                <h2 class="text-base font-bold text-slate-950 dark:text-white">Enregistrement</h2>
                <dl class="mt-4 grid gap-3 text-sm">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-2 dark:border-slate-700">
                        <dt class="text-slate-500">Statut initial</dt>
                        <dd class="font-semibold">Actif / planifié</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-2 dark:border-slate-700">
                        <dt class="text-slate-500">Traçabilité</dt>
                        <dd class="font-semibold">Auditée</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Notification</dt>
                        <dd class="font-semibold">Bénéficiaire</dd>
                    </div>
                </dl>
                <div class="mt-5 grid gap-2">
                    <button class="btn btn-primary w-full" type="submit">Enregistrer la délégation</button>
                    <a class="btn btn-secondary w-full" href="{{ route('workspace.delegations.index') }}">Annuler</a>
                </div>
            </aside>
        </form>
    </div>
@endsection

@push('scripts')
    <script @cspNonce>
        (function () {
            var directionInput = document.getElementById('direction_id');
            var serviceInput = document.getElementById('service_id');
            var serviceField = document.getElementById('service-field');
            var scopeInput = document.getElementById('role_scope');
            var delegantInput = document.getElementById('delegant_id');
            var delegateInput = document.getElementById('delegue_id');

            if (!directionInput || !serviceInput || !scopeInput || !delegantInput || !delegateInput) {
                return;
            }

            function syncForm() {
                var directionId = String(directionInput.value || '');
                var serviceId = String(serviceInput.value || '');
                var serviceScope = scopeInput.value === 'service';
                var delegantId = String(delegantInput.value || '');
                var serviceStillVisible = false;
                var delegantStillVisible = false;

                serviceInput.required = serviceScope;
                if (serviceField) {
                    serviceField.hidden = !serviceScope;
                }

                Array.prototype.forEach.call(serviceInput.options, function (option, index) {
                    if (index === 0) return;
                    var visible = directionId === '' || option.dataset.directionId === directionId;
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (visible && option.value === serviceId) serviceStillVisible = true;
                });

                if (serviceId && !serviceStillVisible) {
                    serviceInput.value = '';
                    serviceId = '';
                }

                Array.prototype.forEach.call(delegantInput.options, function (option, index) {
                    if (index === 0) return;
                    var expectedRole = serviceScope ? 'service' : 'direction';
                    var visible = option.dataset.role === expectedRole
                        && (directionId === '' || option.dataset.directionId === directionId)
                        && (!serviceScope || serviceId === '' || option.dataset.serviceId === serviceId);
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (visible && option.value === delegantId) delegantStillVisible = true;
                });

                if (delegantId && !delegantStillVisible) {
                    delegantInput.value = '';
                    delegantId = '';
                }

                Array.prototype.forEach.call(delegateInput.options, function (option, index) {
                    if (index === 0) return;
                    option.disabled = delegantId !== '' && option.value === delegantId;
                });

                if (delegateInput.value === delegantId) {
                    delegateInput.value = '';
                }
            }

            directionInput.addEventListener('change', syncForm);
            serviceInput.addEventListener('change', syncForm);
            scopeInput.addEventListener('change', syncForm);
            delegantInput.addEventListener('change', syncForm);
            syncForm();
        })();
    </script>
@endpush
