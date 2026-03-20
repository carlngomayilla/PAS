@extends('layouts.workspace')

@section('title', 'Retention')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1>Retention et archivage</h1>
                <p class="text-slate-600">
                    Cette vue pilote les archives non destructives. Les snapshots sont stockes dans <code>data_archives</code>.
                </p>
            </div>
            @if ($canRun)
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('workspace.retention.run') }}">
                        @csrf
                        <input type="hidden" name="mode" value="dry-run">
                        <button class="btn btn-blue" type="submit">Lancer dry-run</button>
                    </form>
                    <form method="POST" action="{{ route('workspace.retention.run') }}" data-confirm-message="Executer l'archivage non destructif maintenant ?" data-confirm-tone="warning" data-confirm-label="Executer">
                        @csrf
                        <input type="hidden" name="mode" value="execute">
                        <button class="btn btn-amber" type="submit">Executer l'archivage</button>
                    </form>
                </div>
            @endif
        </div>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Politiques actives</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            @foreach ($summary['policies'] as $label => $value)
                <article class="rounded-xl border border-slate-200/85 p-4 dark:border-slate-800">
                    <strong>{{ str_replace('_', ' ', ucfirst($label)) }}</strong>
                    <p class="mt-2 text-2xl font-semibold">{{ $value }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Eligibilite actuelle</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            @foreach ($summary['counts'] as $label => $value)
                <article class="rounded-xl border border-slate-200/85 p-4 dark:border-slate-800">
                    <strong>{{ str_replace('_', ' ', ucfirst($label)) }}</strong>
                    <p class="mt-2 text-2xl font-semibold">{{ $value }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="ui-card">
        <h2>Dernieres archives</h2>
        <div class="table-wrap">
            <table>
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
                            <td colspan="6" class="text-slate-600">Aucune archive enregistree.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
