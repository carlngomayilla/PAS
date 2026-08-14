@extends('layouts.workspace')

@section('title', 'Workflow et validations')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Workflow et validations</h1>
                <p class="mt-2 text-slate-600">Paramétrage du circuit de validation des actions. Le chef de service vise le dossier, SCIQ le contrôle et Planification réalise la validation finale.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                @if (auth()->user()?->isSuperAdmin())
                    <a class="btn btn-secondary" href="{{ route('workspace.super-admin.settings.edit') }}">Paramètres généraux</a>
                @endif
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))] mb-3.5">
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Circuit actif</p>
            <p class="mt-2 text-xl font-semibold">{{ $summary['chain_label'] }}</p>
            <p class="mt-2 text-sm text-slate-600">{{ $summary['submission_help_text'] }}</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Étape finale</p>
            <p class="mt-2 text-xl font-semibold">
                @if ($summary['final_stage'] === 'direction')
                    Direction
                @elseif ($summary['final_stage'] === 'service')
                    Chef de service
                @elseif ($summary['final_stage'] === 'control')
                    Contrôle SCIQ
                @elseif ($summary['final_stage'] === 'planification')
                    Planification
                @else
                    Clôture directe
                @endif
            </p>
            <p class="mt-2 text-sm text-slate-600">{{ $summary['final_statistics_hint'] }}</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Motif de rejet</p>
            <p class="mt-2 text-xl font-semibold">{{ $summary['rejection_comment_required'] ? 'Obligatoire' : 'Optionnel' }}</p>
            <p class="mt-2 text-sm text-slate-600">Règle appliquée à chaque décision de rejet du circuit.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Workflow PAS</p>
            <p class="mt-2 text-xl font-semibold">{{ $planningWorkflows['pas']['mode_label'] }}</p>
            <p class="mt-2 text-sm text-slate-600">{{ $planningWorkflows['pas']['chain_label'] }}</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Workflow PAO</p>
            <p class="mt-2 text-xl font-semibold">{{ $planningWorkflows['pao']['mode_label'] }}</p>
            <p class="mt-2 text-sm text-slate-600">{{ $planningWorkflows['pao']['chain_label'] }}</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Workflow PTA</p>
            <p class="mt-2 text-xl font-semibold">{{ $planningWorkflows['pta']['mode_label'] }}</p>
            <p class="mt-2 text-sm text-slate-600">{{ $planningWorkflows['pta']['chain_label'] }}</p>
        </article>
    </section>

    <section class="showcase-panel mb-4">
        <form method="POST" action="{{ route('workspace.super-admin.workflow.update') }}" class="form-shell">
            @csrf
            @method('PUT')

            <div class="form-section">
                <h2 class="form-section-title">Circuit des actions</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700">
                        <input type="hidden" name="actions_service_validation_enabled" value="1">
                        <input
                            class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-400"
                            type="checkbox"
                            checked
                            disabled
                        >
                        <span>
                            <strong class="block text-slate-900">Visa du chef de service obligatoire</strong>
                            <span class="mt-1 block text-slate-500">Le chef vise l'action et la transmet au contrôleur. Cette étape canonique ne peut pas être désactivée.</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                        <input type="hidden" name="actions_direction_validation_enabled" value="0">
                        <input
                            class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-400"
                            type="checkbox"
                            name="actions_direction_validation_enabled_disabled"
                            value="0"
                            disabled
                        >
                        <span>
                            <strong class="block text-slate-900">Validation direction supprimée</strong>
                            <span class="mt-1 block text-slate-500">La direction est informée et conserve une lecture du dossier sans action de validation.</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700 md:col-span-2">
                        <input
                            class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                            type="checkbox"
                            name="actions_rejection_comment_required"
                            value="1"
                            @checked(($settings['actions_rejection_comment_required'] ?? '1') === '1')
                        >
                        <span>
                            <strong class="block text-slate-900">Rendre le motif de rejet obligatoire</strong>
                            <span class="mt-1 block text-slate-500">Quand cette règle est active, une validation de rejet sans commentaire est refusée en web comme en API.</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Circuit PAS / PAO / PTA</h2>
                <p class="form-section-subtitle">Le workflow PAS/PAO/PTA suit le cycle canonique valide, sans ancien circuit soumis/valide/verrouille.</p>
                <div class="grid gap-4 md:grid-cols-3">
                    @foreach (['pas' => 'PAS', 'pao' => 'PAO', 'pta' => 'PTA'] as $module => $label)
                        <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-4">
                            <label for="{{ $module }}_workflow_mode">{{ $label }}</label>
                            <select id="{{ $module }}_workflow_mode" name="{{ $module }}_workflow_mode" class="mt-2">
                                @foreach ($planningModes as $mode => $modeLabel)
                                    <option value="{{ $mode }}" @selected(($settings[$module.'_workflow_mode'] ?? 'canonical') === $mode)>{{ $modeLabel }}</option>
                                @endforeach
                            </select>
                            <p class="mt-3 text-sm text-slate-600">{{ $planningWorkflows[$module]['chain_label'] }}</p>
                            <p class="mt-2 text-xs uppercase tracking-[0.16em] text-slate-500">Hint</p>
                            <p class="mt-1 text-sm text-slate-600">{{ $planningWorkflows[$module]['final_statistics_hint'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Enregistrer le workflow</button>
            </div>
        </form>
    </section>

    <section class="ui-card">
        <h2>Résolution appliquée</h2>
        <div class="app-table-wrapper overflow-x-auto mt-4">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Configuration</th>
                        <th class="px-3 py-2 text-left">Soumission agent</th>
                        <th class="px-3 py-2 text-left">Validation finale</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="px-3 py-2">Service actif</td>
                        <td class="px-3 py-2">Chef de service</td>
                        <td class="px-3 py-2">Chef de service</td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2">Service inactif</td>
                        <td class="px-3 py-2">Clôture directe</td>
                        <td class="px-3 py-2">Aucune étape supplémentaire</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
@endsection
