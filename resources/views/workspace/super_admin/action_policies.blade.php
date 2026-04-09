@extends('layouts.workspace')

@section('title', 'Parametres metier des actions')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Parametres metier des actions</h1>
                <p class="mt-2 text-slate-600">Regles fonctionnelles qui encadrent la creation, la suspension, la cloture et l auto-achievement des actions.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.workflow.edit') }}">Workflow</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.calculation.edit') }}">Calcul officiel</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Plan de risque</p><p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['risk_plan_required'] ? 'Obligatoire' : 'Optionnel' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Suspension manuelle</p><p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['manual_suspend_enabled'] ? 'Activee' : 'Desactivee' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Auto-cloture</p><p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['auto_complete_when_target_reached'] ? 'Activee' : 'Inactive' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Seuil minimal de cloture</p><p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['min_progress_for_closure'] }}%</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Justificatif final</p><p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['final_justificatif_required'] ? 'Obligatoire' : 'Optionnel' }}</p></article>
    </section>

    <section class="ui-card">
        <form method="POST" action="{{ route('workspace.super-admin.action-policies.update') }}" class="form-shell">
            @csrf
            @method('PUT')

            <div class="form-section">
                <h2 class="form-section-title">Regles de creation et d edition</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200">
                        <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_risk_plan_required" value="1" @checked(($settings['actions_risk_plan_required'] ?? '0') === '1')>
                        <span>
                            <strong class="block text-slate-900 dark:text-slate-100">Rendre le plan de risque obligatoire</strong>
                            <span class="mt-1 block text-slate-500 dark:text-slate-400">Les champs `risques` et `mesures preventives` deviennent requis a la creation et a la mise a jour.</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200">
                        <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_manual_suspend_enabled" value="1" @checked(($settings['actions_manual_suspend_enabled'] ?? '1') === '1')>
                        <span>
                            <strong class="block text-slate-900 dark:text-slate-100">Autoriser le statut manuel `Suspendu`</strong>
                            <span class="mt-1 block text-slate-500 dark:text-slate-400">Si desactive, le statut `suspendu` est refuse en web et en API lors de la saisie d action.</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Regles de cloture</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="actions_min_progress_for_closure">Progression minimale pour soumettre la cloture (%)</label>
                        <input id="actions_min_progress_for_closure" name="actions_min_progress_for_closure" type="number" min="0" max="100" value="{{ old('actions_min_progress_for_closure', $settings['actions_min_progress_for_closure'] ?? '0') }}" required>
                        <p class="field-hint">`0` laisse le comportement actuel. Toute valeur superieure bloque la cloture si la progression reelle est inferieure.</p>
                    </div>

                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200">
                        <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_final_justificatif_required" value="1" @checked(($settings['actions_final_justificatif_required'] ?? '0') === '1')>
                        <span>
                            <strong class="block text-slate-900 dark:text-slate-100">Exiger un justificatif final</strong>
                            <span class="mt-1 block text-slate-500 dark:text-slate-400">La soumission de cloture est refusee si aucun document final n est fourni ou deja rattache.</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Automatisation du pilotage</h2>
                <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200">
                    <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_auto_complete_when_target_reached" value="1" @checked(($settings['actions_auto_complete_when_target_reached'] ?? '0') === '1')>
                    <span>
                        <strong class="block text-slate-900 dark:text-slate-100">Auto-cloturer une action quand la cible est atteinte</strong>
                        <span class="mt-1 block text-slate-500 dark:text-slate-400">Quand la progression reelle atteint `100%`, la date de fin reelle est alimentee automatiquement lors du recalcul du suivi.</span>
                    </span>
                </label>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Enregistrer les parametres</button>
            </div>
        </form>
    </section>
@endsection

