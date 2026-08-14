@extends('layouts.admin')

@section('title', 'Simulation')

@section('content')
    @php
        $result = session('simulation_result', $simulation);
        $publishedWorkflow = collect([
            'Agent',
            $defaults['actions_service_validation_enabled'] === '1' ? 'Chef de service' : null,
            $defaults['actions_direction_validation_enabled'] === '1' ? 'Direction' : null,
            'Controle SCIQ',
            'Planification',
        ])->filter()->implode(' -> ');
    @endphp

    <div class="app-screen-flow">
        <header class="showcase-toolbar">
            <div class="showcase-toolbar-copy">
                <p class="text-xs font-semibold uppercase text-cyan-700 dark:text-cyan-300">Super Administration</p>
                <h1 class="showcase-panel-title mt-1">Simulation d’impact</h1>
                <p class="showcase-toolbar-subtitle">Scénario non appliqué sur le workflow Actions et les règles de clôture.</p>
            </div>
            <div class="showcase-toolbar-actions">
                <span class="anbg-badge anbg-badge-info">Lecture seule</span>
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Centre de commandement</a>
            </div>
        </header>

        @if ($errors->any())
            <section class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200" role="alert">
                <p class="font-semibold">Le scénario n’a pas été calculé.</p>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.65fr)]">
            <form method="POST" action="{{ route('workspace.super-admin.simulation.run') }}" class="form-shell">
                @csrf
                <input type="hidden" name="actions_service_validation_enabled" value="1">
                <input type="hidden" name="actions_direction_validation_enabled" value="0">

                <div class="form-section">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Circuit cible verrouillé</p>
                            <h2 class="form-section-title mt-1">Agent → Chef de service → Contrôle SCIQ → Planification</h2>
                        </div>
                        <span class="anbg-badge anbg-badge-success">Conforme</span>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Visa métier</p>
                            <p class="mt-1 text-sm font-semibold text-slate-950 dark:text-white">Chef de service requis</p>
                        </div>
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Contrôle de conformité</p>
                            <p class="mt-1 text-sm font-semibold text-slate-950 dark:text-white">SCIQ requis</p>
                        </div>
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Décision finale</p>
                            <p class="mt-1 text-sm font-semibold text-slate-950 dark:text-white">Planification requise</p>
                        </div>
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Base statistique</p>
                            <p class="mt-1 text-sm font-semibold text-slate-950 dark:text-white">{{ $officialBasisLabel }}</p>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h2 class="form-section-title">Hypothèses métier</h2>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="actions_min_progress_for_closure">Progression minimale de clôture (%)</label>
                            <input
                                id="actions_min_progress_for_closure"
                                name="actions_min_progress_for_closure"
                                type="number"
                                min="0"
                                max="100"
                                step="1"
                                value="{{ old('actions_min_progress_for_closure', $defaults['actions_min_progress_for_closure']) }}"
                                required
                            >
                            @error('actions_min_progress_for_closure')<p class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <label class="flex min-h-24 items-start gap-3 rounded-md border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                            <input
                                class="mt-1 h-4 w-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600"
                                type="checkbox"
                                name="actions_auto_complete_when_target_reached"
                                value="1"
                                @checked(old('actions_auto_complete_when_target_reached', $defaults['actions_auto_complete_when_target_reached']) === '1')
                            >
                            <span>
                                <strong class="block text-sm text-slate-950 dark:text-white">Clôture automatique à 100 %</strong>
                                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">Renseigne la date de fin réelle des actions éligibles.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Calculer l’impact</button>
                    <a class="btn btn-secondary" href="{{ route('workspace.super-admin.action-policies.edit') }}">Paramètres Actions</a>
                </div>
            </form>

            <aside class="border-y border-slate-200 py-4 dark:border-slate-700">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Référence publiée</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Configuration actuelle</h2>
                    </div>
                    <a class="text-sm font-semibold text-cyan-700 hover:underline dark:text-cyan-300" href="{{ route('workspace.super-admin.workflow.edit') }}">Modifier</a>
                </div>

                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 xl:grid-cols-1">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Workflow</dt>
                        <dd class="mt-1 font-semibold text-slate-950 dark:text-white">{{ $publishedWorkflow }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Seuil de clôture</dt>
                        <dd class="mt-1 font-semibold text-slate-950 dark:text-white">{{ $defaults['actions_min_progress_for_closure'] }} %</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Clôture automatique</dt>
                        <dd class="mt-1 font-semibold text-slate-950 dark:text-white">{{ $defaults['actions_auto_complete_when_target_reached'] === '1' ? 'Active' : 'Inactive' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Base consolidée</dt>
                        <dd class="mt-1 font-semibold text-slate-950 dark:text-white">{{ $officialBasisLabel }}</dd>
                    </div>
                </dl>
            </aside>
        </section>

        @if (is_array($result))
            <section class="border-y border-slate-200 py-4 dark:border-slate-700">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Résultat du scénario</p>
                        <h2 class="mt-1 text-xl font-bold text-slate-950 dark:text-white">Impact calculé</h2>
                    </div>
                    <span class="anbg-badge {{ ($result['impact']['workflow_changed'] ?? false) ? 'anbg-badge-warning' : 'anbg-badge-success' }}">
                        {{ ($result['impact']['workflow_changed'] ?? false) ? 'Écart de workflow' : 'Workflow aligné' }}
                    </span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <article class="rounded-md border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Actions consolidées</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{{ $result['simulated']['official_actions_total'] }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $result['impact']['official_actions_delta'] >= 0 ? '+' : '' }}{{ $result['impact']['official_actions_delta'] }} action(s)</p>
                    </article>
                    <article class="rounded-md border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Taux d’exécution</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{{ number_format((float) $result['simulated']['official_completion_rate'], 1, ',', ' ') }} %</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $result['impact']['official_completion_rate_delta'] >= 0 ? '+' : '' }}{{ number_format((float) $result['impact']['official_completion_rate_delta'], 1, ',', ' ') }} pt(s)</p>
                    </article>
                    <article class="rounded-md border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Score moyen</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{{ number_format((float) $result['simulated']['official_average_score'], 1, ',', ' ') }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $result['impact']['official_average_score_delta'] >= 0 ? '+' : '' }}{{ number_format((float) $result['impact']['official_average_score_delta'], 1, ',', ' ') }}</p>
                    </article>
                    <article class="rounded-md border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Clôtures éligibles</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{{ $result['simulated']['closure_eligible_actions'] }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $result['impact']['closure_eligible_actions_delta'] >= 0 ? '+' : '' }}{{ $result['impact']['closure_eligible_actions_delta'] }}</p>
                    </article>
                    <article class="rounded-md border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Auto-clôtures potentielles</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{{ $result['impact']['auto_complete_candidates'] }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Actions à 100 %</p>
                    </article>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(320px,0.7fr)]">
                <div class="min-w-0">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Comparaison</p>
                            <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Actuel et simulé</h2>
                        </div>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $result['population']['actions_total'] ?? 0 }} action(s) analysée(s)</span>
                    </div>
                    <div class="app-table-wrapper mt-3 overflow-x-auto">
                        <table class="app-table data-table min-w-[680px]">
                            <thead><tr><th>Critère</th><th>Actuel</th><th>Simulé</th><th>Écart</th></tr></thead>
                            <tbody>
                                <tr><td>Workflow</td><td>{{ $result['current']['workflow_chain_label'] }}</td><td>{{ $result['simulated']['workflow_chain_label'] }}</td><td>{{ ($result['impact']['workflow_changed'] ?? false) ? 'Modifié' : 'Aucun' }}</td></tr>
                                <tr><td>Seuil de clôture</td><td>{{ $result['current']['min_progress_for_closure'] }} %</td><td>{{ $result['simulated']['min_progress_for_closure'] }} %</td><td>{{ $result['simulated']['min_progress_for_closure'] - $result['current']['min_progress_for_closure'] }} pt(s)</td></tr>
                                <tr><td>Clôture automatique</td><td>{{ $result['current']['auto_complete_when_target_reached'] ? 'Active' : 'Inactive' }}</td><td>{{ $result['simulated']['auto_complete_when_target_reached'] ? 'Active' : 'Inactive' }}</td><td>{{ $result['current']['auto_complete_when_target_reached'] === $result['simulated']['auto_complete_when_target_reached'] ? 'Aucun' : 'Modifiée' }}</td></tr>
                                <tr><td>Population ouverte</td><td colspan="2">{{ $result['population']['open_actions_total'] ?? 0 }} ouverte(s) · {{ $result['population']['terminal_actions_total'] ?? 0 }} terminale(s)</td><td>{{ $result['population']['excluded_actions_total'] ?? 0 }} hors base</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <aside>
                    <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Vigilances</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Points de décision</h2>
                    <div class="mt-3 divide-y divide-amber-200 rounded-md border border-amber-300 bg-amber-50 dark:divide-amber-800 dark:border-amber-800 dark:bg-amber-950/30">
                        @foreach (($result['warnings'] ?? []) as $warning)
                            <p class="p-3 text-sm text-amber-950 dark:text-amber-100">{{ $warning }}</p>
                        @endforeach
                    </div>
                </aside>
            </section>

            <section class="grid gap-4 xl:grid-cols-2">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Profils</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Aperçu dashboard</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach (($result['dashboard_preview'] ?? []) as $role => $preview)
                            <article class="rounded-md border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="font-semibold text-slate-950 dark:text-white">{{ strtoupper($role) }}</h3>
                                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ count($preview['cards'] ?? []) }} carte(s)</span>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    @foreach (collect($preview['kpis'] ?? [])->take(4) as $kpi)
                                        <div class="border-l-2 border-cyan-600 pl-2">
                                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $kpi['label'] }}</p>
                                            <p class="mt-1 text-sm font-bold text-slate-950 dark:text-white">{{ number_format((float) $kpi['value'], 1, ',', ' ') }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>

                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Sorties</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Aperçu exports</h2>
                    <div class="app-table-wrapper mt-3 overflow-x-auto">
                        <table class="app-table data-table min-w-[560px]">
                            <thead><tr><th>Format</th><th>Template publié</th><th>Niveau</th><th>Options</th></tr></thead>
                            <tbody>
                                @forelse (($result['export_preview'] ?? []) as $row)
                                    <tr>
                                        <td>{{ $row['format'] }}</td>
                                        <td>{{ $row['name'] }}</td>
                                        <td>{{ $row['reading_level'] }}</td>
                                        <td class="text-xs text-slate-500 dark:text-slate-400">
                                            Graphes {{ ! empty($row['meta']['graphs']) ? 'oui' : 'non' }} ·
                                            Filigrane {{ ! empty($row['meta']['watermark']) ? 'oui' : 'non' }} ·
                                            Signatures {{ ! empty($row['meta']['signatures']) ? 'oui' : 'non' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4"><x-ui.empty-state title="Aucun template publié" message="Le registre des sorties ne contient aucun modèle actif." icon="file" tone="info" class="my-4" /></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endif
    </div>
@endsection
