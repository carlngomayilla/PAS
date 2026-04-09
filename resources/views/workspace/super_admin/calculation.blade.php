@extends('layouts.workspace')

@section('title', 'Politique de calcul des actions')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Politique de calcul des actions</h1>
                <p class="mt-2 text-slate-600">Les validations chef et direction restent dans le workflow, mais elles ne declenchent plus les KPI ni les statistiques. Le calcul consolide porte maintenant sur tout le portefeuille visible.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.workflow.edit') }}">Workflow et validations</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))] mb-3.5">
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Base statistique active</p>
            <p class="mt-2 text-xl font-semibold">{{ $summary['official_threshold_label'] }}</p>
            <p class="mt-2 text-sm text-slate-600">{{ $summary['official_scope_summary'] }}</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Filtre applique aux cartes consolidees</p>
            <p class="mt-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
                {{ $summary['official_route_filters'] === [] ? 'Aucun filtre de validation' : 'Filtre actif' }}
            </p>
            <p class="mt-2 text-sm text-slate-600">Le dashboard, le pilotage et le reporting consolident toutes les actions visibles, sans dependre de `validee_chef` ou `validee_direction`.</p>
        </article>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" action="{{ route('workspace.super-admin.calculation.update') }}" class="form-shell">
            @csrf
            @method('PUT')

            <div class="form-section">
                <h2 class="form-section-title">Base statistique consolidee</h2>
                <div class="grid gap-4">
                    <input type="hidden" name="actions_official_validation_status" value="{{ \App\Services\ActionCalculationSettings::OFFICIAL_SCOPE_ALL_VISIBLE }}">
                    <article class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200">
                        <strong class="block text-slate-900 dark:text-slate-100">{{ $summary['official_threshold_label'] }}</strong>
                        <span class="mt-1 block text-slate-500 dark:text-slate-400">
                            Les scores, moyennes, cartes et exports se basent sur toutes les actions visibles. Les validations chef et direction restent uniquement des etapes de cloture et d arbitrage.
                        </span>
                    </article>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Confirmer cette politique</button>
            </div>
        </form>
    </section>

    <section class="ui-card">
        <h2>Regle appliquee</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Lecture</th>
                        <th class="px-3 py-2 text-left">Base retenue</th>
                        <th class="px-3 py-2 text-left">Impact</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="px-3 py-2">Provisoire</td>
                        <td class="px-3 py-2">Toutes les actions visibles dans le perimetre</td>
                        <td class="px-3 py-2">Suivi terrain, retards, charge et progression courante</td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2">Consolidee</td>
                        <td class="px-3 py-2">{{ $summary['official_threshold_label'] }}</td>
                        <td class="px-3 py-2">Scores, graphiques et exports calcules sur le portefeuille visible, sans seuil de validation</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
@endsection

