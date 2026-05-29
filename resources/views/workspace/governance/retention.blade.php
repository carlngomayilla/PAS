@extends('layouts.workspace')

@section('title', 'Rétention')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1>Rétention et archivage</h1>
                <p class="text-slate-600">
                    Cette vue pilote les archives non destructives. Les snapshots sont stockés dans <code>data_archives</code>.
                </p>
            </div>
            @if ($canRun)
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('workspace.retention.run') }}">
                        @csrf
                        <input type="hidden" name="mode" value="dry-run">
                        <button class="btn btn-secondary" type="submit">Lancer dry-run</button>
                    </form>
                    <form method="POST" action="{{ route('workspace.retention.run') }}" data-confirm-message="Exécuter l'archivage non destructif maintenant ?" data-confirm-tone="warning" data-confirm-label="Exécuter">
                        @csrf
                        <input type="hidden" name="mode" value="execute">
                        <button class="btn btn-primary" type="submit">Exécuter l'archivage</button>
                    </form>
                    <form method="POST" action="{{ route('workspace.retention.run') }}">
                        @csrf
                        <input type="hidden" name="scope" value="planning">
                        <input type="hidden" name="mode" value="dry-run">
                        <button class="btn btn-secondary" type="submit">Dry-run PAO/PTA</button>
                    </form>
                    <form method="POST" action="{{ route('workspace.retention.run') }}" data-confirm-message="Archiver automatiquement les PAO/PTA clotures eligibles maintenant ?" data-confirm-tone="warning" data-confirm-label="Archiver">
                        @csrf
                        <input type="hidden" name="scope" value="planning">
                        <input type="hidden" name="mode" value="execute">
                        <button class="btn btn-primary" type="submit">Archiver PAO/PTA</button>
                    </form>
                </div>
            @endif
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2>Politiques actives</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            @foreach ($summary['policies'] as $label => $value)
                <article class="rounded-xl border border-slate-200/85 p-4">
                    <strong>{{ str_replace('_', ' ', ucfirst($label)) }}</strong>
                    <p class="mt-2 text-2xl font-semibold">{{ $value }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2>Éligibilité actuelle</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            @foreach ($summary['counts'] as $label => $value)
                <article class="rounded-xl border border-slate-200/85 p-4">
                    <strong>{{ str_replace('_', ' ', ucfirst($label)) }}</strong>
                    <p class="mt-2 text-2xl font-semibold">{{ $value }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2>Archivage automatique PAO / PTA</h2>
        <div class="mt-3 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            <article class="rounded-xl border border-slate-200/85 p-4">
                <strong>Statut</strong>
                <p class="mt-2 text-2xl font-semibold">{{ data_get($planningArchiveSummary, 'settings.enabled') ? 'Actif' : 'Inactif' }}</p>
            </article>
            <article class="rounded-xl border border-slate-200/85 p-4">
                <strong>Delai PAO</strong>
                <p class="mt-2 text-2xl font-semibold">{{ (int) data_get($planningArchiveSummary, 'settings.pao_archive_after_days', 30) }} jours</p>
            </article>
            <article class="rounded-xl border border-slate-200/85 p-4">
                <strong>Delai PTA</strong>
                <p class="mt-2 text-2xl font-semibold">{{ (int) data_get($planningArchiveSummary, 'settings.pta_archive_after_days', 30) }} jours</p>
            </article>
            <article class="rounded-xl border border-slate-200/85 p-4">
                <strong>PAO eligibles</strong>
                <p class="mt-2 text-2xl font-semibold">{{ (int) data_get($planningArchiveSummary, 'counts.paos', 0) }}</p>
            </article>
            <article class="rounded-xl border border-slate-200/85 p-4">
                <strong>PTA eligibles</strong>
                <p class="mt-2 text-2xl font-semibold">{{ (int) data_get($planningArchiveSummary, 'counts.ptas', 0) }}</p>
            </article>
        </div>
    </section>

    <section class="ui-card">
        <h2>Dernieres archives</h2>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Source</th>
                        <th>Entite</th>
                        <th>Perimetre</th>
                        <th>Batch</th>
                        <th>Archive le</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summary['recent_archives'] as $archive)
                        <tr>
                            <td>{{ $archive->id }}</td>
                            <td><span class="anbg-badge anbg-badge-neutral px-3">{{ $archive->source_table }}</span></td>
                            <td>{{ $archive->entity_type }}@if($archive->entity_id) #{{ $archive->entity_id }} @endif</td>
                            <td>
                                @if ($archive->scope_label)
                                    <span class="anbg-badge anbg-badge-info px-3">{{ $archive->scope_label }}</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $archive->batch_key ?: '-' }}</td>
                            <td>{{ optional($archive->archived_at)->format('Y-m-d H:i') ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state
                                    title="Aucune archive enregistrée"
                                    message="Les archives non destructives apparaîtront ici après exécution."
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
    </section>
@endsection
