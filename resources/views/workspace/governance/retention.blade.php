@extends('layouts.workspace')

@section('title', 'Rétention et archivage')

@section('content')
    @php
        $filters = is_array($filters ?? null) ? $filters : [];
        $archiveSummary = is_array($archiveSummary ?? null) ? $archiveSummary : [];
        $retentionCounts = is_array($retentionSummary['counts'] ?? null) ? $retentionSummary['counts'] : [];
        $retentionPolicies = is_array($retentionSummary['policies'] ?? null) ? $retentionSummary['policies'] : [];
        $planningCounts = is_array($planningArchiveSummary['counts'] ?? null) ? $planningArchiveSummary['counts'] : [];
        $planningSettings = is_array($planningArchiveSummary['settings'] ?? null) ? $planningArchiveSummary['settings'] : [];
        $dataCandidates = array_sum(array_map('intval', $retentionCounts));
        $planningCandidates = array_sum(array_map('intval', $planningCounts));
        $sourceLabels = [
            'pas' => 'PAS',
            'justificatifs' => 'Justificatifs',
            'action_logs' => 'Journaux d’action',
            'notifications' => 'Notifications',
        ];
        $policyLabels = [
            'pas_years_after_end' => 'PAS après clôture',
            'justificatifs_days' => 'Justificatifs',
            'action_logs_days' => 'Journaux d’action',
            'notifications_days' => 'Notifications',
        ];
        $runStatusLabels = ['running' => 'En cours', 'completed' => 'Terminée', 'failed' => 'Échec'];
        $runStatusClasses = [
            'running' => 'anbg-badge anbg-badge-info',
            'completed' => 'anbg-badge anbg-badge-success',
            'failed' => 'anbg-badge anbg-badge-danger',
        ];
        $exportFilters = collect($filters)->filter(static fn ($value): bool => $value !== null && $value !== '')->all();
    @endphp

    <div class="app-screen-flow">
        <section class="app-screen-block border-b border-slate-200 pb-4 dark:border-slate-700">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Gouvernance des données</p>
                    <h1 class="mt-1 text-2xl font-bold text-slate-950 dark:text-white">Rétention et archivage</h1>
                </div>
                <a class="btn btn-secondary" href="{{ route('workspace.retention.export.csv', $exportFilters) }}">Exporter le registre CSV</a>
            </div>

            <nav class="mt-4 flex flex-wrap gap-2" aria-label="Traçabilité administrative">
                @if (auth()->user()?->hasPermission('audit.read'))
                    <a class="btn btn-secondary btn-sm" href="{{ route('workspace.audit.index') }}">Journal d’audit</a>
                @endif
                <a class="btn btn-primary btn-sm" href="{{ route('workspace.retention.index') }}" aria-current="page">Rétention</a>
                @if (auth()->user()?->hasPermission('delegations.manage'))
                    <a class="btn btn-secondary btn-sm" href="{{ route('workspace.delegations.index') }}">Délégations</a>
                @endif
            </nav>
        </section>

        <section class="showcase-summary-grid app-screen-kpis">
            <x-stat-card-link :href="route('workspace.retention.index').'#data-retention'" label="Données éligibles" :value="$dataCandidates" :meta="null" />
            <x-stat-card-link :href="route('workspace.retention.index').'#planning-retention'" label="PAO / PTA éligibles" :value="$planningCandidates" :meta="null" />
            <x-stat-card-link :href="route('workspace.retention.index').'#archive-register'" label="Archives filtrées" :value="$archiveSummary['total'] ?? 0" :meta="null" />
            <x-stat-card-link :href="route('workspace.retention.index').'#archive-register'" label="Lots d’archives" :value="$archiveSummary['batches'] ?? 0" :meta="null" />
            <x-stat-card-link :href="route('workspace.retention.index').'#run-history'" label="Exécutions tracées" :value="$runs->count()" :meta="null" />
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <article id="data-retention" class="ui-card app-screen-block">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase text-slate-500">Snapshots non destructifs</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Données historiques</h2>
                    </div>
                    <span class="anbg-badge {{ $dataCandidates > 0 ? 'anbg-badge-warning' : 'anbg-badge-success' }}">{{ $dataCandidates }} éligible{{ $dataCandidates > 1 ? 's' : '' }}</span>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-x-5 gap-y-3 text-sm sm:grid-cols-4">
                    @foreach ($retentionCounts as $key => $value)
                        <div class="border-l-2 border-slate-200 pl-3 dark:border-slate-700">
                            <dt class="text-xs text-slate-500">{{ $sourceLabels[$key] ?? ucfirst(str_replace('_', ' ', $key)) }}</dt>
                            <dd class="mt-1 text-xl font-bold text-slate-950 dark:text-white">{{ (int) $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($canRun)
                    <div class="mt-5 flex flex-wrap gap-2 border-t border-slate-200 pt-4 dark:border-slate-700">
                        <form method="POST" action="{{ route('workspace.retention.run') }}">
                            @csrf
                            <input type="hidden" name="scope" value="data">
                            <input type="hidden" name="mode" value="dry-run">
                            <button class="btn btn-secondary" type="submit">Simuler</button>
                        </form>
                        <form method="POST" action="{{ route('workspace.retention.run') }}" data-confirm-message="Exécuter l’archivage non destructif des données éligibles ?" data-confirm-tone="warning" data-confirm-label="Exécuter">
                            @csrf
                            <input type="hidden" name="scope" value="data">
                            <input type="hidden" name="mode" value="execute">
                            <button class="btn btn-primary" type="submit" @disabled($dataCandidates === 0)>Exécuter</button>
                        </form>
                    </div>
                @endif
            </article>

            <article id="planning-retention" class="ui-card app-screen-block">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase text-slate-500">Archivage de statut</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Planification PAO / PTA</h2>
                    </div>
                    <span class="anbg-badge {{ ($planningSettings['enabled'] ?? false) ? 'anbg-badge-success' : 'anbg-badge-neutral' }}">
                        {{ ($planningSettings['enabled'] ?? false) ? 'Actif' : 'Inactif' }}
                    </span>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-x-5 gap-y-3 sm:grid-cols-4">
                    <div class="border-l-2 border-slate-200 pl-3 dark:border-slate-700">
                        <dt class="text-xs text-slate-500">PAO éligibles</dt>
                        <dd class="mt-1 text-xl font-bold text-slate-950 dark:text-white">{{ (int) ($planningCounts['paos'] ?? 0) }}</dd>
                    </div>
                    <div class="border-l-2 border-slate-200 pl-3 dark:border-slate-700">
                        <dt class="text-xs text-slate-500">PTA éligibles</dt>
                        <dd class="mt-1 text-xl font-bold text-slate-950 dark:text-white">{{ (int) ($planningCounts['ptas'] ?? 0) }}</dd>
                    </div>
                    <div class="border-l-2 border-slate-200 pl-3 dark:border-slate-700">
                        <dt class="text-xs text-slate-500">Délai PAO</dt>
                        <dd class="mt-1 text-xl font-bold text-slate-950 dark:text-white">{{ (int) ($planningSettings['pao_archive_after_days'] ?? 30) }} j</dd>
                    </div>
                    <div class="border-l-2 border-slate-200 pl-3 dark:border-slate-700">
                        <dt class="text-xs text-slate-500">Délai PTA</dt>
                        <dd class="mt-1 text-xl font-bold text-slate-950 dark:text-white">{{ (int) ($planningSettings['pta_archive_after_days'] ?? 30) }} j</dd>
                    </div>
                </dl>

                @if ($canRun)
                    <div class="mt-5 flex flex-wrap gap-2 border-t border-slate-200 pt-4 dark:border-slate-700">
                        <form method="POST" action="{{ route('workspace.retention.run') }}">
                            @csrf
                            <input type="hidden" name="scope" value="planning">
                            <input type="hidden" name="mode" value="dry-run">
                            <button class="btn btn-secondary" type="submit">Simuler</button>
                        </form>
                        <form method="POST" action="{{ route('workspace.retention.run') }}" data-confirm-message="Archiver les PAO et PTA clôturés éligibles ?" data-confirm-tone="warning" data-confirm-label="Archiver">
                            @csrf
                            <input type="hidden" name="scope" value="planning">
                            <input type="hidden" name="mode" value="execute">
                            <button class="btn btn-primary" type="submit" @disabled(! ($planningSettings['enabled'] ?? false) || $planningCandidates === 0)>Archiver</button>
                        </form>
                    </div>
                @endif
            </article>
        </section>

        <section class="app-screen-block border-y border-slate-200 py-4 dark:border-slate-700">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-bold text-slate-950 dark:text-white">Politiques actives</h2>
                <span class="text-xs font-semibold uppercase text-slate-500">Configuration serveur</span>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($retentionPolicies as $key => $value)
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-2 text-sm dark:border-slate-700">
                        <span class="text-slate-600 dark:text-slate-300">{{ $policyLabels[$key] ?? ucfirst(str_replace('_', ' ', $key)) }}</span>
                        <strong>{{ (int) $value }} {{ str_contains($key, 'years') ? 'ans' : 'jours' }}</strong>
                    </div>
                @endforeach
            </div>
        </section>

        <section id="run-history" class="ui-card app-screen-block">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-bold text-slate-950 dark:text-white">Historique des exécutions</h2>
                <span class="text-sm text-slate-500">20 dernières opérations</span>
            </div>

            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table min-w-[66rem]">
                    <thead>
                        <tr>
                            <th>Exécution</th>
                            <th>Périmètre</th>
                            <th>Mode</th>
                            <th>Candidats</th>
                            <th>Traités</th>
                            <th>Opérateur</th>
                            <th>Statut</th>
                            <th>Durée</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($runs as $run)
                            @php
                                $candidateTotal = array_sum(array_map('intval', is_array($run->candidates) ? $run->candidates : []));
                                $processedTotal = array_sum(array_map('intval', is_array($run->processed) ? $run->processed : []));
                                $duration = $run->started_at && $run->completed_at ? $run->started_at->diffInSeconds($run->completed_at) : null;
                            @endphp
                            <tr id="run-{{ $run->id }}">
                                <td>
                                    <strong>#{{ $run->id }}</strong><br>
                                    <span class="text-xs text-slate-500">{{ optional($run->started_at)->format('d/m/Y H:i:s') }}</span>
                                </td>
                                <td>{{ $run->scope === 'planning' ? 'PAO / PTA' : 'Données historiques' }}</td>
                                <td>{{ $run->mode === 'execute' ? 'Exécution' : 'Simulation' }}</td>
                                <td><strong>{{ $candidateTotal }}</strong></td>
                                <td><strong>{{ $processedTotal }}</strong></td>
                                <td>
                                    {{ $run->initiatedBy?->name ?? 'Système' }}<br>
                                    <span class="text-xs text-slate-500">{{ ucfirst($run->source) }}</span>
                                </td>
                                <td>
                                    <span class="{{ $runStatusClasses[$run->status] ?? 'anbg-badge anbg-badge-neutral' }}">{{ $runStatusLabels[$run->status] ?? ucfirst($run->status) }}</span>
                                    @if ($run->error_message)
                                        <div class="mt-1 max-w-72 text-xs text-rose-700 dark:text-rose-300">{{ $run->error_message }}</div>
                                    @elseif ($run->batch_key)
                                        <div class="mt-1 text-xs text-slate-500">{{ $run->batch_key }}</div>
                                    @endif
                                </td>
                                <td>{{ $duration !== null ? $duration.' s' : '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-slate-500">Aucune exécution enregistrée.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section id="archive-register" class="ui-card app-screen-block">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-bold text-slate-950 dark:text-white">Registre des archives</h2>
                <span class="text-sm text-slate-500">{{ $archives->total() }} résultat{{ $archives->total() > 1 ? 's' : '' }}</span>
            </div>

            <form method="GET" action="{{ route('workspace.retention.index') }}" class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(14rem,1fr)_repeat(5,minmax(9rem,auto))_auto] xl:items-end">
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Recherche
                    <input name="q" type="search" maxlength="120" value="{{ $filters['q'] ?? '' }}" placeholder="Périmètre, source, lot, opérateur">
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Source
                    <select name="source">
                        <option value="">Toutes</option>
                        @foreach (($options['sources'] ?? []) as $source)
                            <option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ $sourceLabels[$source] ?? ucfirst(str_replace('_', ' ', $source)) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Lot
                    <select name="batch">
                        <option value="">Tous</option>
                        @foreach (($options['batches'] ?? []) as $batch)
                            <option value="{{ $batch }}" @selected(($filters['batch'] ?? '') === $batch)>{{ $batch }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Opérateur
                    <select name="actor_id">
                        <option value="">Tous</option>
                        @foreach (($options['actors'] ?? []) as $actor)
                            <option value="{{ $actor->id }}" @selected((int) ($filters['actor_id'] ?? 0) === (int) $actor->id)>{{ $actor->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Du
                    <input name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}">
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500">
                    Au
                    <input name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}">
                </label>
                <div class="flex gap-2">
                    <button class="btn btn-primary" type="submit">Filtrer</button>
                    <a class="btn btn-secondary" href="{{ route('workspace.retention.index') }}" title="Réinitialiser les filtres" aria-label="Réinitialiser les filtres">×</a>
                </div>
            </form>

            <div class="app-table-wrapper mt-4 overflow-x-auto">
                <table class="app-table data-table min-w-[76rem]">
                    <thead>
                        <tr>
                            <th>Archive</th>
                            <th>Source</th>
                            <th>Entité</th>
                            <th>Périmètre</th>
                            <th>Lot</th>
                            <th>Opérateur</th>
                            <th class="text-right">Opérations</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($archives as $archive)
                            <tr id="archive-{{ $archive['id'] }}">
                                <td>
                                    <strong>#{{ $archive['id'] }}</strong><br>
                                    <span class="text-xs text-slate-500">{{ optional($archive['archived_at'])->format('d/m/Y H:i:s') }}</span>
                                </td>
                                <td><span class="anbg-badge anbg-badge-neutral">{{ $sourceLabels[$archive['source_table']] ?? ucfirst(str_replace('_', ' ', $archive['source_table'])) }}</span></td>
                                <td>
                                    <strong>{{ ucfirst(str_replace('_', ' ', $archive['entity_type'])) }}</strong>
                                    @if ($archive['entity_id'] !== null)<span class="text-xs text-slate-500">#{{ $archive['entity_id'] }}</span>@endif
                                </td>
                                <td><span class="line-clamp-2 max-w-72">{{ $archive['scope_label'] ?: '-' }}</span></td>
                                <td><span class="font-mono text-xs">{{ $archive['batch_key'] ?: '-' }}</span></td>
                                <td>{{ $archive['actor']?->name ?? 'Système' }}</td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <details class="relative text-left">
                                            <summary class="btn btn-secondary btn-sm cursor-pointer list-none">Aperçu</summary>
                                            <div class="absolute right-0 z-30 mt-2 w-[34rem] max-w-[88vw] rounded-lg border border-slate-200 bg-white p-3 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                                                <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words text-xs text-slate-700 dark:text-slate-200">{{ $archive['payload_json'] }}</pre>
                                            </div>
                                        </details>
                                        <a class="btn btn-secondary btn-sm" href="{{ route('workspace.retention.archives.download', $archive['id']) }}">JSON</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <x-ui.empty-state title="Aucune archive trouvée" message="Aucun enregistrement ne correspond aux critères sélectionnés." icon="file" tone="info" class="my-4" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination">{{ $archives->links() }}</div>
        </section>
    </div>
@endsection
