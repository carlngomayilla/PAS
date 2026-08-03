@props([
    'prefix',
    'action',
    'subActions' => null,
    'selectedSubActionId' => null,
    'changes' => [],
    'responsableOptions' => null,
    'showTarget' => false,
])

@php
    $subActions = collect($subActions ?? []);
    $responsableOptions = collect($responsableOptions ?? []);
    $changes = is_array($changes) ? $changes : [];
    $selectedFields = collect(old('change_fields', array_keys($changes)))
        ->map(fn ($field) => (string) $field)
        ->all();
    $responsableIds = collect(old('requested_responsable_ids', $changes['responsables'] ?? []))
        ->map(fn ($id) => (int) $id)
        ->all();
    $fieldValue = fn (string $input, string $field, mixed $fallback = null) => old($input, $changes[$field] ?? $fallback);
@endphp

<div class="space-y-4" data-deadline-change-fields data-target-kind="{{ $selectedSubActionId ? 'subaction' : 'action' }}">
    @if ($showTarget)
        <div>
            <label for="{{ $prefix }}_target">Élément à modifier</label>
            <select id="{{ $prefix }}_target" name="sous_action_id">
                <option value="">Action principale</option>
                @foreach ($subActions as $sousAction)
                    <option value="{{ $sousAction->id }}" @selected((string) old('sous_action_id', $selectedSubActionId) === (string) $sousAction->id)>
                        Sous-action · {{ $sousAction->libelle }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif

    <fieldset class="space-y-3">
        <legend class="text-sm font-bold text-slate-900 dark:text-white">Paramètres demandés</legend>

        <div class="grid gap-3 md:grid-cols-2">
            <label class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    <input class="size-4" type="checkbox" name="change_fields[]" value="deadline" @checked(in_array('deadline', $selectedFields, true))>
                    Échéance
                </span>
                <input class="mt-2" name="requested_deadline" type="date" value="{{ $fieldValue('requested_deadline', 'deadline') }}">
            </label>

            <label class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    <input class="size-4" type="checkbox" name="change_fields[]" value="libelle" @checked(in_array('libelle', $selectedFields, true))>
                    Intitulé
                </span>
                <input class="mt-2" name="requested_libelle" type="text" maxlength="255" value="{{ $fieldValue('requested_libelle', 'libelle') }}" placeholder="Nouvel intitulé">
            </label>

            <label class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-700 dark:bg-slate-900 md:col-span-2">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    <input class="size-4" type="checkbox" name="change_fields[]" value="responsables" @checked(in_array('responsables', $selectedFields, true))>
                    RMO / responsables
                </span>
                <select class="mt-2 min-h-28" name="requested_responsable_ids[]" multiple>
                    @foreach ($responsableOptions as $responsable)
                        <option value="{{ $responsable->id }}" @selected(in_array((int) $responsable->id, $responsableIds, true))>
                            {{ $responsable->name }} · {{ $responsable->email }}
                        </option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">Action : plusieurs RMO. Sous-action : un seul RMO.</span>
            </label>

            <label class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    <input class="size-4" type="checkbox" name="change_fields[]" value="date_debut" @checked(in_array('date_debut', $selectedFields, true))>
                    Date de début
                </span>
                <input class="mt-2" name="requested_date_debut" type="date" value="{{ $fieldValue('requested_date_debut', 'date_debut') }}">
            </label>

            <label class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-700 dark:bg-slate-900" data-change-scope="action">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    <input class="size-4" type="checkbox" name="change_fields[]" value="priorite" @checked(in_array('priorite', $selectedFields, true))>
                    Priorité de l’action
                </span>
                <input class="mt-2" name="requested_priorite" type="text" maxlength="30" value="{{ $fieldValue('requested_priorite', 'priorite') }}" placeholder="Normale, haute…">
            </label>

            @foreach ([
                'description' => ['Description', 'requested_description', 'all'],
                'resultat_attendu' => ['Résultat attendu', 'requested_resultat_attendu', 'all'],
                'indicateurs_attendus' => ['Indicateurs attendus', 'requested_indicateurs_attendus', 'action'],
                'observations' => ['Observations', 'requested_observations', 'action'],
                'livrable_attendu' => ['Livrable attendu', 'requested_livrable_attendu', 'all'],
            ] as $field => [$label, $input, $scope])
                <label class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-700 dark:bg-slate-900 md:col-span-2" data-change-scope="{{ $scope }}">
                    <span class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                        <input class="size-4" type="checkbox" name="change_fields[]" value="{{ $field }}" @checked(in_array($field, $selectedFields, true))>
                        {{ $label }}
                    </span>
                    <textarea class="mt-2" name="{{ $input }}" rows="3" placeholder="Nouvelle valeur">{{ $fieldValue($input, $field) }}</textarea>
                </label>
            @endforeach

            <label class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-700 dark:bg-slate-900" data-change-scope="subaction">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    <input class="size-4" type="checkbox" name="change_fields[]" value="unite" @checked(in_array('unite', $selectedFields, true))>
                    Unité de la sous-action
                </span>
                <input class="mt-2" name="requested_unite" type="text" maxlength="100" value="{{ $fieldValue('requested_unite', 'unite') }}">
            </label>

            <label class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-700 dark:bg-slate-900" data-change-scope="subaction">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    <input class="size-4" type="checkbox" name="change_fields[]" value="cible_prevue" @checked(in_array('cible_prevue', $selectedFields, true))>
                    Cible prévue de la sous-action
                </span>
                <input class="mt-2" name="requested_cible_prevue" type="number" min="0" step="0.0001" value="{{ $fieldValue('requested_cible_prevue', 'cible_prevue') }}">
            </label>
        </div>
    </fieldset>
</div>
