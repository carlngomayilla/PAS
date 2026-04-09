@extends('layouts.workspace')

@section('title', $mode === 'create' ? 'Nouveau template d export' : 'Modifier template d export')

@section('content')
    @php
        $isEdit = $mode === 'edit';
        $meta = old('document_title') !== null ? null : ($template->meta_config ?? []);
        $layout = old('paper_size') !== null ? null : ($template->layout_config ?? []);
        $blocks = old('include_cover') !== null ? null : ($template->blocks_config ?? []);
        $style = old('color_primary') !== null ? null : ($template->style_config ?? []);
        $content = old('visible_columns') !== null ? null : ($template->content_config ?? []);
    @endphp

    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h1>{{ $isEdit ? 'Modifier template d export' : 'Nouveau template d export' }}</h1>
                <p class="text-slate-600">Identification, mise en page, contenu et affectation par defaut.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.templates.index') }}">Retour liste</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
            </div>
        </div>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.super-admin.templates.update', $template) : route('workspace.super-admin.templates.store') }}">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <div class="form-section">
                <h2 class="form-section-title">Identification</h2>
                <div class="form-grid">
                    <div><label for="name">Nom</label><input id="name" name="name" type="text" value="{{ old('name', $template->name) }}" required></div>
                    <div><label for="code">Code technique</label><input id="code" name="code" type="text" value="{{ old('code', $template->code) }}" required></div>
                    <div><label for="format">Format</label><select id="format" name="format" required>@foreach ($formatOptions as $option)<option value="{{ $option }}" @selected(old('format', $template->format) === $option)>{{ strtoupper($option) }}</option>@endforeach</select></div>
                    <div><label for="module">Module</label><select id="module" name="module" required>@foreach ($moduleOptions as $option)<option value="{{ $option }}" @selected(old('module', $template->module) === $option)>{{ $option }}</option>@endforeach</select></div>
                    <div><label for="report_type">Type de rapport</label><input id="report_type" name="report_type" type="text" value="{{ old('report_type', $template->report_type ?: 'consolidated_reporting') }}" required></div>
                    <div><label for="target_profile">Profil cible</label><select id="target_profile" name="target_profile"><option value="">Tous profils</option>@foreach ($profileOptions as $option)<option value="{{ $option }}" @selected(old('target_profile', $template->target_profile) === $option)>{{ $option }}</option>@endforeach</select></div>
                    <div><label for="reading_level">Niveau</label><select id="reading_level" name="reading_level"><option value="">Non borne</option>@foreach ($readingLevelOptions as $option)<option value="{{ $option }}" @selected(old('reading_level', $template->reading_level) === $option)>{{ $option }}</option>@endforeach</select></div>
                    <div class="flex items-end gap-3"><label class="checkbox-pill !mb-0"><input name="is_default" type="checkbox" value="1" @checked(old('is_default', $template->is_default))>Template par defaut</label><label class="checkbox-pill !mb-0"><input name="is_active" type="checkbox" value="1" @checked(old('is_active', $template->exists ? (int) $template->is_active : 1))>Actif</label></div>
                    <div class="md:col-span-2 xl:col-span-4"><label for="description">Description</label><textarea id="description" name="description" rows="3">{{ old('description', $template->description) }}</textarea></div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Metadonnees documentaires</h2>
                <div class="form-grid">
                    <div><label for="document_title">Titre document</label><input id="document_title" name="document_title" type="text" value="{{ old('document_title', $meta['document_title'] ?? $template->name) }}"></div>
                    <div><label for="document_subtitle">Sous-titre</label><input id="document_subtitle" name="document_subtitle" type="text" value="{{ old('document_subtitle', $meta['document_subtitle'] ?? '') }}"></div>
                    <div><label for="filename_prefix">Prefixe fichier</label><input id="filename_prefix" name="filename_prefix" type="text" value="{{ old('filename_prefix', $meta['filename_prefix'] ?? 'reporting_anbg') }}"></div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Mise en page et style</h2>
                <div class="form-grid">
                    <div><label for="paper_size">Format papier</label><input id="paper_size" name="paper_size" type="text" value="{{ old('paper_size', $layout['paper_size'] ?? 'a4') }}"></div>
                    <div><label for="orientation">Orientation</label><select id="orientation" name="orientation"><option value="portrait" @selected(old('orientation', $layout['orientation'] ?? 'landscape') === 'portrait')>portrait</option><option value="landscape" @selected(old('orientation', $layout['orientation'] ?? 'landscape') === 'landscape')>landscape</option></select></div>
                    <div><label for="header_text">Entete</label><input id="header_text" name="header_text" type="text" value="{{ old('header_text', $layout['header_text'] ?? '') }}"></div>
                    <div><label for="footer_text">Pied de page</label><input id="footer_text" name="footer_text" type="text" value="{{ old('footer_text', $layout['footer_text'] ?? '') }}"></div>
                    <div><label for="watermark_text">Filigrane</label><input id="watermark_text" name="watermark_text" type="text" value="{{ old('watermark_text', $layout['watermark_text'] ?? '') }}"></div>
                    <div><label for="font_family">Police</label><input id="font_family" name="font_family" type="text" value="{{ old('font_family', $style['font_family'] ?? 'Inter') }}"></div>
                    <div><label for="color_primary">Couleur primaire</label><input id="color_primary" name="color_primary" type="text" value="{{ old('color_primary', $style['color_primary'] ?? '#1E3A8A') }}"></div>
                    <div><label for="color_secondary">Couleur secondaire</label><input id="color_secondary" name="color_secondary" type="text" value="{{ old('color_secondary', $style['color_secondary'] ?? '#3B82F6') }}"></div>
                    <div><label for="excel_detail_sheet_name">Feuille detail Excel</label><input id="excel_detail_sheet_name" name="excel_detail_sheet_name" type="text" maxlength="31" value="{{ old('excel_detail_sheet_name', $layout['excel_detail_sheet_name'] ?? 'Reporting') }}"></div>
                    <div><label for="excel_graph_sheet_name">Feuille graphique Excel</label><input id="excel_graph_sheet_name" name="excel_graph_sheet_name" type="text" maxlength="31" value="{{ old('excel_graph_sheet_name', $layout['excel_graph_sheet_name'] ?? 'Synthese graphique') }}"></div>
                    <div class="md:col-span-2 xl:col-span-4 flex flex-wrap items-end gap-3">
                        <label class="checkbox-pill !mb-0"><input name="excel_freeze_header" type="checkbox" value="1" @checked(old('excel_freeze_header', $layout['excel_freeze_header'] ?? true))>Excel freeze header</label>
                        <label class="checkbox-pill !mb-0"><input name="excel_auto_filter" type="checkbox" value="1" @checked(old('excel_auto_filter', $layout['excel_auto_filter'] ?? true))>Excel auto filter</label>
                        <label class="checkbox-pill !mb-0"><input name="pdf_show_level_legend" type="checkbox" value="1" @checked(old('pdf_show_level_legend', $layout['pdf_show_level_legend'] ?? true))>PDF legende niveaux</label>
                        <label class="checkbox-pill !mb-0"><input name="pdf_show_kpi_cards" type="checkbox" value="1" @checked(old('pdf_show_kpi_cards', $layout['pdf_show_kpi_cards'] ?? true))>PDF cartes KPI</label>
                        <label class="checkbox-pill !mb-0"><input name="word_include_toc" type="checkbox" value="1" @checked(old('word_include_toc', $layout['word_include_toc'] ?? false))>Word sommaire</label>
                        <label class="checkbox-pill !mb-0"><input name="word_page_break_after_summary" type="checkbox" value="1" @checked(old('word_page_break_after_summary', $layout['word_page_break_after_summary'] ?? false))>Word saut apres synthese</label>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Blocs exportables</h2>
                <div class="flex flex-wrap gap-3">
                    <label class="checkbox-pill !mb-0"><input name="include_cover" type="checkbox" value="1" @checked(old('include_cover', $blocks['include_cover'] ?? 1))>Page de garde</label>
                    <label class="checkbox-pill !mb-0"><input name="include_summary" type="checkbox" value="1" @checked(old('include_summary', $blocks['include_summary'] ?? 1))>Synthese</label>
                    <label class="checkbox-pill !mb-0"><input name="include_detail_table" type="checkbox" value="1" @checked(old('include_detail_table', $blocks['include_detail_table'] ?? 1))>Table detail</label>
                    <label class="checkbox-pill !mb-0"><input name="include_charts" type="checkbox" value="1" @checked(old('include_charts', $blocks['include_charts'] ?? 1))>Graphiques</label>
                    <label class="checkbox-pill !mb-0"><input name="include_alerts" type="checkbox" value="1" @checked(old('include_alerts', $blocks['include_alerts'] ?? 1))>Alertes</label>
                    <label class="checkbox-pill !mb-0"><input name="include_signatures" type="checkbox" value="1" @checked(old('include_signatures', $blocks['include_signatures'] ?? 0))>Signatures</label>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Contenu dynamique</h2>
                <div class="form-grid">
                    <div class="md:col-span-2 xl:col-span-2"><label for="visible_columns">Colonnes visibles</label><textarea id="visible_columns" name="visible_columns" rows="4" placeholder="colonne_a, colonne_b, colonne_c">{{ old('visible_columns', implode(', ', $content['visible_columns'] ?? [])) }}</textarea></div>
                    <div class="md:col-span-2 xl:col-span-2"><label for="dynamic_variables">Variables dynamiques</label><textarea id="dynamic_variables" name="dynamic_variables" rows="4" placeholder="{app_name}\n{report_title}\n{generated_at}">{{ old('dynamic_variables', implode(PHP_EOL, $content['dynamic_variables'] ?? [])) }}</textarea></div>
                </div>
                <div class="mt-4">
                    <div class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Variables supportees</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($dynamicVariableOptions as $variable)
                            <code>{{ $variable }}</code>
                        @endforeach
                    </div>
                </div>
            </div>

            @unless ($isEdit)
                <div class="form-section">
                    <h2 class="form-section-title">Affectation initiale</h2>
                    <label class="checkbox-pill !mb-0"><input name="create_default_assignment" type="checkbox" value="1" @checked(old('create_default_assignment', 1))>Creer automatiquement une affectation par defaut avec les metadonnees du template</label>
                </div>
            @endunless

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                <a class="btn btn-secondary" href="{{ $isEdit ? route('workspace.super-admin.templates.show', $template) : route('workspace.super-admin.templates.index') }}">Retour</a>
            </div>
        </form>
    </section>
@endsection
