@extends('layouts.workspace')

@section('title', $template->name)

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Template d export</p>
                <h1 class="mt-2">{{ $template->name }}</h1>
                <p class="mt-2 text-slate-600">{{ $template->description ?: 'Aucune description fournie.' }}</p>
                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    <span class="anbg-badge anbg-badge-info">{{ $template->formatLabel() }}</span>
                    <span class="anbg-badge anbg-badge-neutral">{{ $template->module }}</span>
                    <span class="anbg-badge anbg-badge-warning">{{ $template->statusLabel() }}</span>
                    <span class="anbg-badge anbg-badge-success">{{ $template->reading_level ?: 'non borne' }}</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.templates.edit', $template) }}">Modifier</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.templates.preview', $template) }}">Previsualiser</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.templates.export-json', $template) }}">Exporter JSON</a>
                <form method="POST" action="{{ route('workspace.super-admin.templates.duplicate', $template) }}">@csrf<button class="btn btn-secondary" type="submit">Dupliquer</button></form>
                @if ($template->status !== \App\Models\ExportTemplate::STATUS_PUBLISHED)
                    <form method="POST" action="{{ route('workspace.super-admin.templates.publish', $template) }}">@csrf<input type="hidden" name="mark_as_default" value="1"><button class="btn btn-green" type="submit">Publier</button></form>
                @endif
                @if ($template->status !== \App\Models\ExportTemplate::STATUS_ARCHIVED)
                    <form method="POST" action="{{ route('workspace.super-admin.templates.archive', $template) }}">@csrf<button class="btn btn-delete" type="submit">Archiver</button></form>
                @endif
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><h2 class="text-base">Metadonnees</h2><p class="mt-2 text-sm text-slate-600">Code : <code>{{ $template->code }}</code></p><p class="mt-1 text-sm text-slate-600">Type de rapport : <strong>{{ $template->report_type }}</strong></p><p class="mt-1 text-sm text-slate-600">Profil cible : <strong>{{ $template->target_profile ?: 'Tous profils' }}</strong></p><p class="mt-1 text-sm text-slate-600">Titre document : <strong>{{ $template->documentTitle() }}</strong></p><p class="mt-1 text-sm text-slate-600">Prefixe fichier : <strong>{{ $template->filenamePrefix() }}</strong></p></article>
        <article class="ui-card !mb-0"><h2 class="text-base">Mise en page</h2><p class="mt-2 text-sm text-slate-600">Papier : <strong>{{ $template->paperSize() }}</strong></p><p class="mt-1 text-sm text-slate-600">Orientation : <strong>{{ $template->orientation() }}</strong></p><p class="mt-1 text-sm text-slate-600">Filigrane : <strong>{{ $template->layout_config['watermark_text'] ?? 'Aucun' }}</strong></p><p class="mt-1 text-sm text-slate-600">Police : <strong>{{ $template->style_config['font_family'] ?? 'Inter' }}</strong></p></article>
        <article class="ui-card !mb-0"><h2 class="text-base">Cycle de vie</h2><p class="mt-2 text-sm text-slate-600">Cree par : <strong>{{ $template->creator?->name ?? 'Systeme' }}</strong></p><p class="mt-1 text-sm text-slate-600">Mis a jour par : <strong>{{ $template->updater?->name ?? 'Systeme' }}</strong></p><p class="mt-1 text-sm text-slate-600">Publie le : <strong>{{ $template->published_at?->format('Y-m-d H:i') ?? 'Non publie' }}</strong></p></article>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Apercu fonctionnel</h2>
        <div class="mt-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            @foreach (($template->blocks_config ?? []) as $block => $enabled)
                <article class="ui-card !mb-0"><p class="text-sm text-slate-500">{{ $block }}</p><p class="mt-2 text-lg font-semibold">{{ $enabled ? 'Active' : 'Inactive' }}</p></article>
            @endforeach
        </div>
        <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-900/40">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Variables dynamiques</p>
            <div class="mt-2 flex flex-wrap gap-2">@forelse (($template->content_config['dynamic_variables'] ?? []) as $variable)<code>{{ $variable }}</code>@empty<span class="text-sm text-slate-500">Aucune variable declaree.</span>@endforelse</div>
        </div>
        <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-900/40">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Options avancees</p>
            <div class="mt-2 grid gap-2 md:grid-cols-2 text-sm text-slate-600 dark:text-slate-300">
                <div>Excel freeze header : <strong>{{ ($template->layout_config['excel_freeze_header'] ?? true) ? 'Oui' : 'Non' }}</strong></div>
                <div>Excel auto filter : <strong>{{ ($template->layout_config['excel_auto_filter'] ?? true) ? 'Oui' : 'Non' }}</strong></div>
                <div>Feuille detail : <strong>{{ $template->layout_config['excel_detail_sheet_name'] ?? 'Reporting' }}</strong></div>
                <div>Feuille graphique : <strong>{{ $template->layout_config['excel_graph_sheet_name'] ?? 'Synthese graphique' }}</strong></div>
                <div>PDF legende niveaux : <strong>{{ ($template->layout_config['pdf_show_level_legend'] ?? true) ? 'Oui' : 'Non' }}</strong></div>
                <div>PDF cartes KPI : <strong>{{ ($template->layout_config['pdf_show_kpi_cards'] ?? true) ? 'Oui' : 'Non' }}</strong></div>
                <div>Word sommaire : <strong>{{ ($template->layout_config['word_include_toc'] ?? false) ? 'Oui' : 'Non' }}</strong></div>
                <div>Word saut apres synthese : <strong>{{ ($template->layout_config['word_page_break_after_summary'] ?? false) ? 'Oui' : 'Non' }}</strong></div>
            </div>
        </div>
    </section>

    <section class="grid gap-3 lg:grid-cols-2 mb-3.5">
        <article class="ui-card !mb-0">
            <div class="flex items-center justify-between gap-3"><h2>Affectations</h2><span class="text-sm text-slate-500">{{ $template->assignments->count() }} affectation(s)</span></div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr><th class="px-3 py-2 text-left">Profil</th><th class="px-3 py-2 text-left">Niveau</th><th class="px-3 py-2 text-left">Scope</th><th class="px-3 py-2 text-left">Etat</th><th class="px-3 py-2 text-left">Action</th></tr></thead>
                    <tbody>
                        @forelse ($template->assignments as $assignment)
                            <tr>
                                <td class="px-3 py-2">{{ $assignment->target_profile ?: 'Tous profils' }}</td>
                                <td class="px-3 py-2">{{ $assignment->reading_level ?: 'Non borne' }}</td>
                                <td class="px-3 py-2">{{ $assignment->service?->code ?: ($assignment->direction?->code ?: 'Global') }}</td>
                                <td class="px-3 py-2">{{ $assignment->is_active ? 'Active' : 'Inactive' }}</td>
                                <td class="px-3 py-2"><form method="POST" action="{{ route('workspace.super-admin.templates.assignments.toggle', $assignment) }}">@csrf<button class="btn btn-secondary !px-3 !py-1.5" type="submit">Basculer</button></form></td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-4 text-slate-500" colspan="5">Aucune affectation.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="ui-card !mb-0">
            <h2>Ajouter une affectation</h2>
            <form method="POST" action="{{ route('workspace.super-admin.templates.assignments.store', $template) }}" class="mt-4 form-grid">
                @csrf
                <div><label for="assign_module">Module</label><select id="assign_module" name="module">@foreach ($moduleOptions as $option)<option value="{{ $option }}" @selected($assignmentDefaults['module'] === $option)>{{ $option }}</option>@endforeach</select></div>
                <div><label for="assign_report_type">Type de rapport</label><input id="assign_report_type" name="report_type" type="text" value="{{ $assignmentDefaults['report_type'] }}"></div>
                <div><label for="assign_format">Format</label><input id="assign_format" name="format" type="text" value="{{ $assignmentDefaults['format'] }}"></div>
                <div><label for="assign_target_profile">Profil</label><select id="assign_target_profile" name="target_profile"><option value="">Tous profils</option>@foreach ($profileOptions as $option)<option value="{{ $option }}" @selected($assignmentDefaults['target_profile'] === $option)>{{ $option }}</option>@endforeach</select></div>
                <div><label for="assign_reading_level">Niveau</label><select id="assign_reading_level" name="reading_level"><option value="">Non borne</option>@foreach ($readingLevelOptions as $option)<option value="{{ $option }}" @selected($assignmentDefaults['reading_level'] === $option)>{{ $option }}</option>@endforeach</select></div>
                <div><label for="assign_direction_id">Direction</label><select id="assign_direction_id" name="direction_id"><option value="">Globale</option>@foreach ($directionOptions as $direction)<option value="{{ $direction->id }}">{{ $direction->code }} - {{ $direction->libelle }}</option>@endforeach</select></div>
                <div><label for="assign_service_id">Service</label><select id="assign_service_id" name="service_id"><option value="">Aucun</option>@foreach ($serviceOptions as $service)<option value="{{ $service->id }}">{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>@endforeach</select></div>
                <div class="flex items-end gap-3"><label class="checkbox-pill !mb-0"><input name="is_default" type="checkbox" value="1">Defaut</label><label class="checkbox-pill !mb-0"><input name="is_active" type="checkbox" value="1" checked>Active</label></div>
                <div class="flex items-end"><button class="btn btn-primary" type="submit">Ajouter</button></div>
            </form>
        </article>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Versions</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr><th class="px-3 py-2 text-left">Version</th><th class="px-3 py-2 text-left">Statut</th><th class="px-3 py-2 text-left">Note</th><th class="px-3 py-2 text-left">Auteur</th><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-left">Snapshot</th><th class="px-3 py-2 text-left">Action</th></tr></thead>
                <tbody>
                    @forelse ($template->versions as $version)
                        <tr>
                            <td class="px-3 py-2">v{{ $version->version_number }}</td>
                            <td class="px-3 py-2">{{ $version->status }}</td>
                            <td class="px-3 py-2">{{ $version->note ?: '-' }}</td>
                            <td class="px-3 py-2">{{ $version->creator?->name ?? 'Systeme' }}</td>
                            <td class="px-3 py-2">{{ $version->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">
                                <div class="text-xs text-slate-500">
                                    {{ $version->snapshot['format'] ?? '-' }} /
                                    {{ $version->snapshot['module'] ?? '-' }} /
                                    {{ $version->snapshot['reading_level'] ?? 'non borne' }}
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <form method="POST" action="{{ route('workspace.super-admin.templates.versions.restore', [$template, $version]) }}">
                                    @csrf
                                    <button class="btn btn-secondary !px-3 !py-1.5" type="submit">Restaurer</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-4 text-slate-500" colspan="7">Aucune version n a encore ete publiee.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

