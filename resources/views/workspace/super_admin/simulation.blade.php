@extends('layouts.workspace')

@section('title', 'Simulation')

@section('content')
    @php
        $result = session('simulation_result', $simulation);
    @endphp

    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Mode simulation</h1>
                <p class="mt-2 text-slate-600">Comparer l impact d un changement de workflow action et de regles de cloture avant application reelle. La base statistique reste fixe sur toutes les actions visibles.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.calculation.edit') }}">Politique de calcul</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.workflow.edit') }}">Workflow</a>
            </div>
        </div>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" action="{{ route('workspace.super-admin.simulation.run') }}" class="form-shell">
            @csrf
            <div class="form-section">
                <h2 class="form-section-title">Hypotheses a comparer</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200">
                        <strong class="block text-slate-900 dark:text-slate-100">Base statistique</strong>
                        <span class="mt-1 block text-slate-500 dark:text-slate-400">{{ $officialBasisLabel }}</span>
                        <span class="mt-1 block text-slate-500 dark:text-slate-400">La validation chef/direction n entre plus dans le calcul des KPI ni des statistiques.</span>
                    </div>
                    <div>
                        <label for="actions_min_progress_for_closure">Progression minimale de cloture (%)</label>
                        <input id="actions_min_progress_for_closure" name="actions_min_progress_for_closure" type="number" min="0" max="100" value="{{ old('actions_min_progress_for_closure', $defaults['actions_min_progress_for_closure']) }}" required>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-3">
                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200">
                        <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_service_validation_enabled" value="1" @checked(old('actions_service_validation_enabled', $defaults['actions_service_validation_enabled']) === '1')>
                        <span><strong class="block text-slate-900 dark:text-slate-100">Validation service</strong><span class="mt-1 block text-slate-500 dark:text-slate-400">Inclure l etape chef de service.</span></span>
                    </label>
                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200">
                        <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_direction_validation_enabled" value="1" @checked(old('actions_direction_validation_enabled', $defaults['actions_direction_validation_enabled']) === '1')>
                        <span><strong class="block text-slate-900 dark:text-slate-100">Validation direction</strong><span class="mt-1 block text-slate-500 dark:text-slate-400">Maintenir l etape finale direction.</span></span>
                    </label>
                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200">
                        <input class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="actions_auto_complete_when_target_reached" value="1" @checked(old('actions_auto_complete_when_target_reached', $defaults['actions_auto_complete_when_target_reached']) === '1')>
                        <span><strong class="block text-slate-900 dark:text-slate-100">Auto-cloture</strong><span class="mt-1 block text-slate-500 dark:text-slate-400">Completer automatiquement les actions a 100%.</span></span>
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
            <section class="ui-card mb-3.5">
                <h2>Vigilances de simulation</h2>
                <div class="mt-4 grid gap-2">
                    @foreach (($result['warnings'] ?? []) as $warning)
                        <div class="rounded-2xl border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                            {{ $warning }}
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Actions consolidees</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $result['current']['official_actions_total'] }} -> {{ $result['simulated']['official_actions_total'] }}</p><p class="mt-2 text-sm text-slate-600">Delta {{ $result['impact']['official_actions_delta'] >= 0 ? '+' : '' }}{{ $result['impact']['official_actions_delta'] }}</p></article>
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Taux execution consolide</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ number_format((float) $result['current']['official_completion_rate'], 2) }}% -> {{ number_format((float) $result['simulated']['official_completion_rate'], 2) }}%</p><p class="mt-2 text-sm text-slate-600">Delta {{ $result['impact']['official_completion_rate_delta'] >= 0 ? '+' : '' }}{{ number_format((float) $result['impact']['official_completion_rate_delta'], 2) }} pts</p></article>
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Score moyen consolide</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ number_format((float) $result['current']['official_average_score'], 2) }} -> {{ number_format((float) $result['simulated']['official_average_score'], 2) }}</p><p class="mt-2 text-sm text-slate-600">Delta {{ $result['impact']['official_average_score_delta'] >= 0 ? '+' : '' }}{{ number_format((float) $result['impact']['official_average_score_delta'], 2) }}</p></article>
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Clotures eligibles</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $result['current']['closure_eligible_actions'] }} -> {{ $result['simulated']['closure_eligible_actions'] }}</p><p class="mt-2 text-sm text-slate-600">Delta {{ $result['impact']['closure_eligible_actions_delta'] >= 0 ? '+' : '' }}{{ $result['impact']['closure_eligible_actions_delta'] }}</p></article>
            <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Auto-cloture potentielle</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $result['impact']['auto_complete_candidates'] }}</p><p class="mt-2 text-sm text-slate-600">Actions deja a 100% sans date de fin reelle.</p></article>
        </section>

        <section class="ui-card">
            <h2>Comparaison detaillee</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
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
                        <tr><td class="px-3 py-2">Seuil cloture</td><td class="px-3 py-2">{{ $result['current']['min_progress_for_closure'] }}%</td><td class="px-3 py-2">{{ $result['simulated']['min_progress_for_closure'] }}%</td></tr>
                        <tr><td class="px-3 py-2">Auto-cloture</td><td class="px-3 py-2">{{ $result['current']['auto_complete_when_target_reached'] ? 'Activee' : 'Inactive' }}</td><td class="px-3 py-2">{{ $result['simulated']['auto_complete_when_target_reached'] ? 'Activee' : 'Inactive' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-2 mt-3.5">
            <article class="ui-card !mb-0">
                <h2>Apercu dashboard</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    @foreach (($result['dashboard_preview'] ?? []) as $role => $preview)
                        <div class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700/80">
                            <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ strtoupper($role) }}</h3>
                            <p class="mt-1 text-sm text-slate-600">Cartes visibles apres application de la simulation.</p>
                            <ul class="mt-3 space-y-2 text-sm text-slate-700 dark:text-slate-200">
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
                <h2>Apercu exports</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="dashboard-table">
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
                                <tr><td colspan="4" class="text-slate-500">Aucun template publie disponible.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    @endif
@endsection

