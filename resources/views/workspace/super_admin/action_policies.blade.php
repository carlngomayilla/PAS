@extends('layouts.workspace')

@section('title', 'Parametres métier des actions')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Parametres métier des actions</h1>
                <p class="mt-2 text-slate-600">Règles fonctionnelles qui encadrent la création, la suspension, la clôture et l'auto-achievement des actions.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.workflow.edit') }}">Workflow</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.calculation.edit') }}">Calcul statistique</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Suspension manuelle</p><p class="mt-2 text-2xl font-bold text-slate-900">{{ $summary['manual_suspend_enabled'] ? 'Activée' : 'Désactivée' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Auto-clôture</p><p class="mt-2 text-2xl font-bold text-slate-900">{{ $summary['auto_complete_when_target_reached'] ? 'Activée' : 'Inactive' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Seuil minimal de clôture</p><p class="mt-2 text-2xl font-bold text-slate-900">{{ $summary['min_progress_for_closure'] }}%</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Justificatif final</p><p class="mt-2 text-2xl font-bold text-slate-900">{{ $summary['final_justificatif_required'] ? 'Obligatoire' : 'Optionnel' }}</p></article>
    </section>

    <section class="ui-card">
        <form method="POST" action="{{ route('workspace.super-admin.action-policies.update') }}" class="form-shell">
            @csrf
            @method('PUT')

            <div class="form-section">
                <h2 class="form-section-title">Règles de creation et d edition</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700">
                        <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_manual_suspend_enabled" value="1" @checked(($settings['actions_manual_suspend_enabled'] ?? '1') === '1')>
                        <span>
                            <strong class="block text-slate-900">Autoriser le statut manuel `Suspendu`</strong>
                            <span class="mt-1 block text-slate-500">Si désactivé, le statut `suspendu` est refusé en web et en API lors de la saisie d'action.</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Règles de clôture</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="actions_min_progress_for_closure">Progression minimale pour soumettre la clôture (%)</label>
                        <input id="actions_min_progress_for_closure" name="actions_min_progress_for_closure" type="number" min="0" max="100" value="{{ old('actions_min_progress_for_closure', $settings['actions_min_progress_for_closure'] ?? '0') }}" required>
                        <p class="field-hint">`0` laisse le comportement actuel. Toute valeur supérieure bloque la clôture si la progression réelle est inférieure.</p>
                    </div>

                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700">
                        <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_final_justificatif_required" value="1" @checked(($settings['actions_final_justificatif_required'] ?? '0') === '1')>
                        <span>
                            <strong class="block text-slate-900">Exiger un justificatif final</strong>
                            <span class="mt-1 block text-slate-500">La soumission de clôture est refusée si aucun document final n'est fourni ou déjà rattaché.</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Automatisation du pilotage</h2>
                <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700">
                    <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_auto_complete_when_target_reached" value="1" @checked(($settings['actions_auto_complete_when_target_reached'] ?? '0') === '1')>
                    <span>
                        <strong class="block text-slate-900">Auto-clôturer une action quand la cible est atteinte</strong>
                        <span class="mt-1 block text-slate-500">Quand la progression réelle atteint `100%`, la date de fin réelle est alimentée automatiquement lors du recalcul du suivi.</span>
                    </span>
                </label>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Enregistrer les parametres</button>
            </div>
        </form>
    </section>
@endsection
