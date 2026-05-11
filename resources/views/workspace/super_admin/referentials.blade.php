@extends('layouts.workspace')

@section('title', 'Référentiels dynamiques')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Référentiels dynamiques</h1>
                <p class="mt-2 text-slate-600">Pilotage des listes métier déjà branchees dans les formulaires et validations existants. Ce lot reste volontairement borne aux referentiels effectivement supportes par le moteur.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.roles.edit') }}">Rôles et permissions</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.notifications.edit') }}">Alertes et notifications</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Priorités opérationnelles</p>
            <p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['priority_count'] }}</p>
            <p class="mt-2 text-sm text-slate-600">Liste utilisée par les objectifs opérationnels PAO.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Types de cible action</p>
            <p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['target_type_count'] }}</p>
            <p class="mt-2 text-sm text-slate-600">Le moteur reste binaire : quantitatif / qualitatif. Seuls les libellés sont pilotables ici.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Suggestions d'unités</p>
            <p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['unit_count'] }}</p>
            <p class="mt-2 text-sm text-slate-600">Liste proposée dans la saisie des actions quantitatives.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Unites Indicateur de performance</p>
            <p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['kpi_unit_count'] ?? 0 }}</p>
            <p class="mt-2 text-sm text-slate-600">Suggestions utilisees dans la création des indicateurs.</p>
        </article>
    </section>

    <section class="showcase-panel mb-4">
        <form method="POST" action="{{ route('workspace.super-admin.referentials.update') }}" class="form-shell">
            @csrf
            @method('PUT')

            <div class="form-section">
                <h2 class="form-section-title">Types de cible action</h2>
                <p class="form-section-subtitle">Les codes techniques restent fixes pour conserver la compatibilité des calculs. Seuls les libellés de l'interface sont pilotables.</p>
                <div class="form-grid">
                    <div>
                        <label for="action_target_type_label_quantitative">Libellé du type `quantitative`</label>
                        <input
                            id="action_target_type_label_quantitative"
                            name="action_target_type_label_quantitative"
                            type="text"
                            maxlength="40"
                            value="{{ old('action_target_type_label_quantitative', $settings['action_target_type_labels']['quantitative'] ?? 'Quantitative') }}"
                            required
                        >
                    </div>
                    <div>
                        <label for="action_target_type_label_qualitative">Libellé du type `qualitative`</label>
                        <input
                            id="action_target_type_label_qualitative"
                            name="action_target_type_label_qualitative"
                            type="text"
                            maxlength="40"
                            value="{{ old('action_target_type_label_qualitative', $settings['action_target_type_labels']['qualitative'] ?? 'Qualitative') }}"
                            required
                        >
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Suggestions d'unités</h2>
                <p class="form-section-subtitle">Une valeur par ligne. Ces suggestions alimentent le champ Unite dans les actions quantitatives sans imposer une liste fermee.</p>
                <label for="action_unit_suggestions">Suggestions proposées</label>
                <textarea id="action_unit_suggestions" name="action_unit_suggestions" rows="8" required>{{ old('action_unit_suggestions', implode("\n", $settings['action_unit_suggestions'] ?? [])) }}</textarea>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Priorités des objectifs opérationnels</h2>
                <p class="form-section-subtitle">Une priorité par ligne. Cette liste pilote le formulaire et la validation des objectifs opérationnels PAO.</p>
                <label for="pao_operational_priorities">Priorités autorisées</label>
                <textarea id="pao_operational_priorities" name="pao_operational_priorities" rows="6" required>{{ old('pao_operational_priorities', implode("\n", $settings['pao_operational_priorities'] ?? [])) }}</textarea>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Suggestions d'unités Indicateur de performance</h2>
                <p class="form-section-subtitle">Ces valeurs alimentent le champ Unite lors de la création manuelle d'un indicateur.</p>
                <label for="kpi_unit_suggestions">Suggestions proposées</label>
                <textarea id="kpi_unit_suggestions" name="kpi_unit_suggestions" rows="6" required>{{ old('kpi_unit_suggestions', implode("\n", $settings['kpi_unit_suggestions'] ?? [])) }}</textarea>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Libellés documentaires et alertes</h2>
                <p class="form-section-subtitle">Pilotage des libellés visibles dans le suivi d action et le centre d alertes sans changer les codes internes.</p>
                <div class="form-grid">
                    <div><label for="justificatif_category_label_hebdomadaire">Justificatif `hebdomadaire`</label><input id="justificatif_category_label_hebdomadaire" name="justificatif_category_label_hebdomadaire" type="text" maxlength="60" value="{{ old('justificatif_category_label_hebdomadaire', $settings['justificatif_category_labels']['hebdomadaire'] ?? 'Justificatif hebdomadaire') }}" required></div>
                    <div><label for="justificatif_category_label_final">Justificatif `final`</label><input id="justificatif_category_label_final" name="justificatif_category_label_final" type="text" maxlength="60" value="{{ old('justificatif_category_label_final', $settings['justificatif_category_labels']['final'] ?? 'Justificatif final') }}" required></div>
                    <div><label for="justificatif_category_label_evaluation_chef">Justificatif `evaluation_chef`</label><input id="justificatif_category_label_evaluation_chef" name="justificatif_category_label_evaluation_chef" type="text" maxlength="60" value="{{ old('justificatif_category_label_evaluation_chef', $settings['justificatif_category_labels']['evaluation_chef'] ?? 'Evaluation chef') }}" required></div>
                    <div><label for="justificatif_category_label_evaluation_direction">Justificatif `evaluation_direction`</label><input id="justificatif_category_label_evaluation_direction" name="justificatif_category_label_evaluation_direction" type="text" maxlength="60" value="{{ old('justificatif_category_label_evaluation_direction', $settings['justificatif_category_labels']['evaluation_direction'] ?? 'Evaluation direction') }}" required></div>
                    <div><label for="justificatif_category_label_financement">Justificatif `financement`</label><input id="justificatif_category_label_financement" name="justificatif_category_label_financement" type="text" maxlength="60" value="{{ old('justificatif_category_label_financement', $settings['justificatif_category_labels']['financement'] ?? 'Pièce financement') }}" required></div>
                    <div><label for="alert_level_label_warning">Niveau `warning`</label><input id="alert_level_label_warning" name="alert_level_label_warning" type="text" maxlength="60" value="{{ old('alert_level_label_warning', $settings['alert_level_labels']['warning'] ?? 'Attention') }}" required></div>
                    <div><label for="alert_level_label_critical">Niveau `critical`</label><input id="alert_level_label_critical" name="alert_level_label_critical" type="text" maxlength="60" value="{{ old('alert_level_label_critical', $settings['alert_level_labels']['critical'] ?? 'Critique') }}" required></div>
                    <div><label for="alert_level_label_urgence">Niveau `urgence`</label><input id="alert_level_label_urgence" name="alert_level_label_urgence" type="text" maxlength="60" value="{{ old('alert_level_label_urgence', $settings['alert_level_labels']['urgence'] ?? 'Urgence') }}" required></div>
                    <div><label for="alert_level_label_info">Niveau `info`</label><input id="alert_level_label_info" name="alert_level_label_info" type="text" maxlength="60" value="{{ old('alert_level_label_info', $settings['alert_level_labels']['info'] ?? 'Info') }}" required></div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Libellés des statuts de validation</h2>
                <p class="form-section-subtitle">Ces textes remappent les libellés visibles dans le suivi et les vues analytiques sans modifier le workflow effectif.</p>
                <div class="form-grid">
                    <div><label for="validation_status_label_non_soumise">Statut `non_soumise`</label><input id="validation_status_label_non_soumise" name="validation_status_label_non_soumise" type="text" maxlength="60" value="{{ old('validation_status_label_non_soumise', $settings['validation_status_labels']['non_soumise'] ?? 'Non soumise') }}" required></div>
                    <div><label for="validation_status_label_soumise_chef">Statut `soumise_chef`</label><input id="validation_status_label_soumise_chef" name="validation_status_label_soumise_chef" type="text" maxlength="60" value="{{ old('validation_status_label_soumise_chef', $settings['validation_status_labels']['soumise_chef'] ?? 'Soumise au chef') }}" required></div>
                    <div><label for="validation_status_label_rejetee_chef">Statut `rejetee_chef`</label><input id="validation_status_label_rejetee_chef" name="validation_status_label_rejetee_chef" type="text" maxlength="60" value="{{ old('validation_status_label_rejetee_chef', $settings['validation_status_labels']['rejetee_chef'] ?? 'Rejetée par le chef') }}" required></div>
                    <div><label for="validation_status_label_validee_chef">Statut `validee_chef`</label><input id="validation_status_label_validee_chef" name="validation_status_label_validee_chef" type="text" maxlength="60" value="{{ old('validation_status_label_validee_chef', $settings['validation_status_labels']['validee_chef'] ?? 'Validée chef') }}" required></div>
                    <div><label for="validation_status_label_rejetee_direction">Statut `rejetee_direction`</label><input id="validation_status_label_rejetee_direction" name="validation_status_label_rejetee_direction" type="text" maxlength="60" value="{{ old('validation_status_label_rejetee_direction', $settings['validation_status_labels']['rejetee_direction'] ?? 'Rejetée direction') }}" required></div>
                    <div><label for="validation_status_label_validee_direction">Statut `validee_direction`</label><input id="validation_status_label_validee_direction" name="validation_status_label_validee_direction" type="text" maxlength="60" value="{{ old('validation_status_label_validee_direction', $settings['validation_status_labels']['validee_direction'] ?? 'Validée direction') }}" required></div>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Enregistrer les referentiels</button>
            </div>
        </form>
    </section>
@endsection
