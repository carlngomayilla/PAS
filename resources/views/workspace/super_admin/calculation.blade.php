@extends('layouts.workspace')

@section('title', 'Politique de calcul des actions')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Politique de calcul des actions</h1>
                <p class="mt-2 text-slate-600">Les cartes provisoires conservent la progression brute. Les statistiques officielles utilisent le niveau de validation configure ci-dessous.</p>
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
            <p class="mt-2 text-sm font-semibold text-slate-900">
                {{ $summary['official_route_filters'] === [] ? 'Aucun filtre de validation' : 'Filtre actif' }}
            </p>
            <p class="mt-2 text-sm text-slate-600">Ce filtre s'applique aux KPI valides, au dashboard officiel, au pilotage et au reporting consolide.</p>
        </article>
    </section>

    <section class="showcase-panel mb-4">
        <form method="POST" action="{{ route('workspace.super-admin.calculation.update') }}" class="form-shell">
            @csrf
            @method('PUT')

            <div class="form-section">
                <h2 class="form-section-title">Base statistique consolidee</h2>
                <div class="grid gap-4">
                    <div>
                        <label for="actions_official_validation_status">Niveau officiel d'integration statistique</label>
                        <select id="actions_official_validation_status" name="actions_official_validation_status" required>
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(($settings[\App\Services\ActionCalculationSettings::SETTING_ACTIONS_STATISTICAL_SCOPE] ?? \App\Services\ActionCalculationSettings::LEVEL_VALIDATION_DIRECTION) === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Enregistrer la politique</button>
            </div>
        </form>
    </section>

    <section class="ui-card">
        <h2>Regle appliquee</h2>
        <div class="app-table-wrapper overflow-x-auto mt-4">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Lecture</th>
                        <th class="px-3 py-2 text-left">Base retenue</th>
                        <th class="px-3 py-2 text-left">Impact</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="px-3 py-2">Brute</td>
                        <td class="px-3 py-2">Toutes les actions visibles dans le perimetre</td>
                        <td class="px-3 py-2">Suivi terrain, retards, charge et progression courante</td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2">Officielle</td>
                        <td class="px-3 py-2">{{ $summary['official_threshold_label'] }}</td>
                        <td class="px-3 py-2">Scores, graphiques et exports calcules selon le seuil de validation officiel</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
@endsection
