@extends('layouts.workspace')

@section('title', 'Simulation')

@section('content')
    @php
        $result = session('simulation_result', $simulation);
    @endphp

    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Mode simulation</h1>
                <p class="mt-2 text-slate-600">Comparer l'impact d'un changement de workflow action et de règles de clôture avant application réelle. La base statistique reste fixe sur toutes les actions visibles.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.calculation.edit') }}">Politique de calcul</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.workflow.edit') }}">Workflow</a>
            </div>
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <form method="POST" action="{{ route('workspace.super-admin.simulation.run') }}" class="form-shell">
            @csrf
            <div class="form-section">
                <h2 class="form-section-title">Hypotheses a comparer</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700">
                        <strong class="block text-slate-900">Base statistique</strong>
                        <span class="mt-1 block text-slate-500">{{ $officialBasisLabel }}</span>
                        <span class="mt-1 block text-slate-500">La validation chef/direction n'entre plus dans le calcul des Indicateur de performance ni des statistiques.</span>
                    </div>
                    <div>
                        <label for="actions_min_progress_for_closure">Progression minimale de clôture (%)</label>
                        <input id="actions_min_progress_for_closure" name="actions_min_progress_for_closure" type="number" min="0" max="100" value="{{ old('actions_min_progress_for_closure', $defaults['actions_min_progress_for_closure']) }}" required>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-3">
                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700">
                        <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_service_validation_enabled" value="1" @checked(old('actions_service_validation_enabled', $defaults['actions_service_validation_enabled']) === '1')>
                        <span><strong class="block text-slate-900">Validation service</strong><span class="mt-1 block text-slate-500">Inclure l'étape chef de service.</span></span>
                    </label>
                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                        <input type="hidden" name="actions_direction_validation_enabled" value="0">
                        <input class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-400" type="checkbox" value="0" disabled>
                        <span><strong class="block text-slate-900">Validation direction supprimee</strong><span class="mt-1 block text-slate-500">La simulation conserve le circuit cible : validation finale par le chef, direction en lecture.</span></span>
                    </label>
                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700">
                        <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_auto_complete_when_target_reached" value="1" @checked(old('actions_auto_complete_when_target_reached', $defaults['actions_auto_complete_when_target_reached']) === '1')>
                        <span><strong class="block text-slate-900">Auto-clôture</strong><span class="mt-1 block text-slate-500">Compléter automatiquement les actions à 100%.</span></span>
                    </label>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Lancer la simulation</button>
            </div>
        </form>
    </section>

    @if (is_array($result))
        @if (($result['warnings'] ?? []) !== [])
            <section class="showcase-panel mb-4">
                <h2>Vigilances de simulation</h2>
                <div class="mt-4 grid gap-2">
                    @foreach (($result['warnings'] ?? []) as $warning)
                        <div class="rounded-2xl border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            {{ $warning }}
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Actions consolidées</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $result['current']['official_actions_total'] }} -> {{ $result['simulated']['official_actions_total'] }}</p><p class="mt-2 text-sm text-slate-600">Delta {{ $result['impact']['official_actions_delta'] >= 0 ? '+' : '' }}{{ $result['impact']['official_actions_delta'] }}</p></article>
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Taux d'exécution consolidé</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ number_format((float) $result['current']['official_completion_rate'], 2) }}% -> {{ number_format((float) $result['simulated']['official_completion_rate'], 2) }}%</p><p class="mt-2 text-sm text-slate-600">Delta {{ $result['impact']['official_completion_rate_delta'] >= 0 ? '+' : '' }}{{ number_format((float) $result['impact']['official_completion_rate_delta'], 2) }} pts</p></article>
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Score moyen consolidé</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ number_format((float) $result['current']['official_average_score'], 2) }} -> {{ number_format((float) $result['simulated']['official_average_score'], 2) }}</p><p class="mt-2 text-sm text-slate-600">Delta {{ $result['impact']['official_average_score_delta'] >= 0 ? '+' : '' }}{{ number_format((float) $result['impact']['official_average_score_delta'], 2) }}</p></article>
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Clôtures éligibles</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $result['current']['closure_eligible_actions'] }} -> {{ $result['simulated']['closure_eligible_actions'] }}</p><p class="mt-2 text-sm text-slate-600">Delta {{ $result['impact']['closure_eligible_actions_delta'] >= 0 ? '+' : '' }}{{ $result['impact']['closure_eligible_actions_delta'] }}</p></article>
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Auto-clôture potentielle</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $result['impact']['auto_complete_candidates'] }}</p><p class="mt-2 text-sm text-slate-600">Actions déjà à 100% sans date de fin réelle.</p></article>
        </section>

        <section class="ui-card">
            <h2>Comparaison détaillée</h2>
            <div class="app-table-wrapper overflow-x-auto mt-4">
                <table class="app-table data-table">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left">Critere</th>
                            <th class="px-3 py-2 text-left">Actuel</th>
                            <th class="px-3 py-2 text-left">Simule</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td class="px-3 py-2">Base statistique</td><td class="px-3 py-2">{{ $result['current']['official_basis_label'] }}</td><td class="px-3 py-2">{{ $result['simulated']['official_basis_label'] }}</td></tr>
                        <tr><td class="px-3 py-2">Workflow action</td><td class="px-3 py-2">{{ $result['current']['workflow_chain_label'] }}</td><td class="px-3 py-2">{{ $result['simulated']['workflow_chain_label'] }}</td></tr>
                        <tr><td class="px-3 py-2">Seuil clôture</td><td class="px-3 py-2">{{ $result['current']['min_progress_for_closure'] }}%</td><td class="px-3 py-2">{{ $result['simulated']['min_progress_for_closure'] }}%</td></tr>
                        <tr><td class="px-3 py-2">Auto-clôture</td><td class="px-3 py-2">{{ $result['current']['auto_complete_when_target_reached'] ? 'Activée' : 'Inactive' }}</td><td class="px-3 py-2">{{ $result['simulated']['auto_complete_when_target_reached'] ? 'Activée' : 'Inactive' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-2 mt-3.5">
            <article class="ui-card !mb-0">
                <h2>Aperçu dashboard</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    @foreach (($result['dashboard_preview'] ?? []) as $role => $preview)
                        <div class="rounded-2xl border border-slate-200/80 p-4">
                            <h3 class="font-semibold text-slate-900">{{ strtoupper($role) }}</h3>
                            <p class="mt-1 text-sm text-slate-600">Cartes visibles après application de la simulation.</p>
                            <ul class="mt-3 space-y-2 text-sm text-slate-700">
                                @forelse (($preview['cards'] ?? []) as $card)
                                    <li>{{ $card['label'] }} · {{ strtoupper($card['size']) }} · {{ $card['tone'] !== 'auto' ? $card['tone'] : 'auto' }}</li>
                                @empty
                                    <li class="text-slate-500">Aucune carte active.</li>
                                @endforelse
                            </ul>
                        </div>
                    @endforeach
                </div>
            </article>
            <article class="ui-card !mb-0">
                <h2>Aperçu exports</h2>
                <div class="app-table-wrapper overflow-x-auto mt-4">
                    <table class="app-table data-table">
                        <thead><tr><th>Format</th><th>Template</th><th>Niveau</th><th>Meta</th></tr></thead>
                        <tbody>
                            @forelse (($result['export_preview'] ?? []) as $row)
                                <tr>
                                    <td>{{ $row['format'] }}</td>
                                    <td>{{ $row['name'] }}</td>
                                    <td>{{ $row['reading_level'] }}</td>
                                    <td class="text-xs text-slate-500">
                                        Graphes {{ !empty($row['meta']['graphs']) ? 'oui' : 'non' }},
                                        Filigrane {{ !empty($row['meta']['watermark']) ? 'oui' : 'non' }},
                                        Signatures {{ !empty($row['meta']['signatures']) ? 'oui' : 'non' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        <x-ui.empty-state
                                            title="Aucun template publié"
                                            message="Publiez un template pour alimenter l'aperçu des exports."
                                            icon="file"
                                            tone="info"
                                            class="my-4"
                                        />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    @endif
@endsection
