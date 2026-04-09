@extends('layouts.workspace')

@section('title', 'Apparence')

@section('content')
    @php
        $paletteFields = [
            'primary_color' => 'Primaire',
            'secondary_color' => 'Secondaire',
            'surface_color' => 'Surface sombre',
            'success_color' => 'Succes',
            'accent_color' => 'Accent',
            'warning_color' => 'Alerte',
            'danger_color' => 'Critique',
        ];
        $surfaceFields = [
            'text_color' => 'Texte principal',
            'muted_text_color' => 'Texte secondaire',
            'border_color' => 'Bordures',
            'card_background_color' => 'Fond des cartes',
            'input_background_color' => 'Fond des champs',
        ];
        $previewInline = collect($previewPayload['css_variables'] ?? [])
            ->map(fn ($value, $key) => $key.': '.$value)
            ->implode('; ');
    @endphp

    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Apparence de la plateforme</h1>
                <p class="mt-2 text-slate-600">Pilotage fin des couleurs, composants, largeurs, densites et styles globaux avec apercu temps reel avant enregistrement.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.modules.edit') }}">Modules</a>
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.maintenance.index') }}">Maintenance</a>
            </div>
        </div>
    </section>

    <x-super-admin.draft-banner
        :has-draft="$hasDraft"
        :draft-updated-at="$draftUpdatedAt"
        message="Les modifications de design ne sont pas encore publiques"
        :publish-route="route('workspace.super-admin.appearance.publish-draft')"
        :discard-route="route('workspace.super-admin.appearance.discard-draft')"
    />

    <section class="grid gap-4 xl:grid-cols-[minmax(0,1.08fr),minmax(360px,0.92fr)]">
        <section class="ui-card mb-0">
            <form method="POST" action="{{ route('workspace.super-admin.appearance.update') }}" class="form-shell" id="appearance-form" data-appearance-preview-endpoint="{{ route('workspace.super-admin.appearance.preview') }}">
                @csrf
                <input id="appearance-method-spoof" name="_method" type="hidden" value="PUT">

                <div class="form-section">
                    <h2 class="form-section-title">Palette principale</h2>
                    <p class="form-section-subtitle">Couleurs systeme reutilisees dans les boutons, fonds, etats et accents graphiques.</p>
                    <div class="form-grid">
                        @foreach ($paletteFields as $key => $label)
                            <div>
                                <label for="{{ $key }}">{{ $label }}</label>
                                <div class="mt-2 flex items-center gap-3">
                                    <input class="h-12 w-16 p-1" id="{{ $key }}_picker" type="color" value="{{ old($key, $settings[$key]) }}" data-sync-color="{{ $key }}">
                                    <input id="{{ $key }}" name="{{ $key }}" type="text" value="{{ old($key, $settings[$key]) }}" maxlength="7" required>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="form-section">
                    <h2 class="form-section-title">Texte et surfaces</h2>
                    <p class="form-section-subtitle">Controle des tons de lecture, fonds de cartes et champs de saisie.</p>
                    <div class="form-grid">
                        @foreach ($surfaceFields as $key => $label)
                            <div>
                                <label for="{{ $key }}">{{ $label }}</label>
                                <div class="mt-2 flex items-center gap-3">
                                    <input class="h-12 w-16 p-1" id="{{ $key }}_picker" type="color" value="{{ old($key, $settings[$key]) }}" data-sync-color="{{ $key }}">
                                    <input id="{{ $key }}" name="{{ $key }}" type="text" value="{{ old($key, $settings[$key]) }}" maxlength="7" required>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="form-section">
                    <h2 class="form-section-title">Typographie et lecture</h2>
                    <p class="form-section-subtitle">Police globale, police de titre, densite de lecture et largeur des contenus.</p>
                    <div class="form-grid">
                        <div>
                            <label for="font_family">Police globale</label>
                            <select id="font_family" name="font_family">
                                @foreach ($fontOptions as $option)
                                    <option value="{{ $option }}" @selected(old('font_family', $settings['font_family']) === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="heading_font_family">Police des titres</label>
                            <select id="heading_font_family" name="heading_font_family">
                                @foreach ($headingFontOptions as $option)
                                    <option value="{{ $option }}" @selected(old('heading_font_family', $settings['heading_font_family']) === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="default_theme">Theme par defaut</label>
                            <select id="default_theme" name="default_theme">
                                @foreach ($themeOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('default_theme', $settings['default_theme']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="visual_density">Densite visuelle</label>
                            <select id="visual_density" name="visual_density">
                                @foreach ($densityOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('visual_density', $settings['visual_density']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="content_width">Largeur de lecture</label>
                            <select id="content_width" name="content_width">
                                @foreach ($contentWidthOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('content_width', $settings['content_width']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="sidebar_width">Largeur sidebar</label>
                            <select id="sidebar_width" name="sidebar_width">
                                @foreach ($sidebarWidthOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('sidebar_width', $settings['sidebar_width']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h2 class="form-section-title">Composants visuels</h2>
                    <p class="form-section-subtitle">Styles des arrieres-plans, cartes, boutons, tableaux et zones de saisie.</p>
                    <div class="form-grid">
                        <div>
                            <label for="page_background_style">Fond de page</label>
                            <select id="page_background_style" name="page_background_style">
                                @foreach ($pageBackgroundStyleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('page_background_style', $settings['page_background_style']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="header_style">Style header</label>
                            <select id="header_style" name="header_style">
                                @foreach ($headerStyleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('header_style', $settings['header_style']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="sidebar_style">Style sidebar</label>
                            <select id="sidebar_style" name="sidebar_style">
                                @foreach ($sidebarStyleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('sidebar_style', $settings['sidebar_style']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="card_style">Style cartes</label>
                            <select id="card_style" name="card_style">
                                @foreach ($cardStyleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('card_style', $settings['card_style']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="button_style">Style boutons</label>
                            <select id="button_style" name="button_style">
                                @foreach ($buttonStyleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('button_style', $settings['button_style']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="input_style">Style champs</label>
                            <select id="input_style" name="input_style">
                                @foreach ($inputStyleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('input_style', $settings['input_style']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="table_style">Style tableaux</label>
                            <select id="table_style" name="table_style">
                                @foreach ($tableStyleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('table_style', $settings['table_style']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="card_shadow_strength">Ombre des cartes</label>
                            <select id="card_shadow_strength" name="card_shadow_strength">
                                @foreach ($shadowOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('card_shadow_strength', $settings['card_shadow_strength']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h2 class="form-section-title">Rayons et profondeur</h2>
                    <p class="form-section-subtitle">Ajuste l arrondi et le flou pour uniformiser toute l application.</p>
                    <div class="form-grid">
                        <div>
                            <label for="card_radius">Rayon cartes</label>
                            <input id="card_radius" name="card_radius" type="text" value="{{ old('card_radius', $settings['card_radius']) }}" required>
                            <p class="field-hint">Exemple : <code>1.5rem</code> ou <code>18px</code>.</p>
                        </div>
                        <div>
                            <label for="button_radius">Rayon boutons</label>
                            <input id="button_radius" name="button_radius" type="text" value="{{ old('button_radius', $settings['button_radius']) }}" required>
                        </div>
                        <div>
                            <label for="input_radius">Rayon champs</label>
                            <input id="input_radius" name="input_radius" type="text" value="{{ old('input_radius', $settings['input_radius']) }}" required>
                        </div>
                        <div>
                            <label for="card_blur">Flou cartes</label>
                            <input id="card_blur" name="card_blur" type="text" value="{{ old('card_blur', $settings['card_blur']) }}" required>
                            <p class="field-hint">Exemple : <code>0px</code>, <code>4px</code>, <code>10px</code>.</p>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-secondary" type="submit" data-appearance-action="{{ route('workspace.super-admin.appearance.draft') }}" data-appearance-method="POST">Enregistrer le brouillon</button>
                    <button class="btn btn-primary" type="submit" data-appearance-action="{{ route('workspace.super-admin.appearance.update') }}" data-appearance-method="PUT">Publier maintenant</button>
                </div>
            </form>
        </section>

        <aside class="ui-card mb-0 xl:sticky xl:top-24">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2>Apercu temps reel</h2>
                    <p class="text-slate-600">Le rendu ci-dessous se met a jour avant enregistrement.</p>
                </div>
                <div class="text-right text-xs text-slate-500">
                    <div id="appearance-preview-theme">Theme : {{ $settings['default_theme'] === 'dark' ? 'Sombre' : 'Clair' }}</div>
                    <div id="appearance-preview-density">Densite : {{ $densityOptions[$settings['visual_density']] ?? $settings['visual_density'] }}</div>
                    <div id="appearance-preview-width">Lecture : {{ $contentWidthOptions[$settings['content_width']] ?? $settings['content_width'] }}</div>
                </div>
            </div>

            <div id="appearance-live-preview" class="appearance-live-preview mt-4" data-theme="{{ $settings['default_theme'] }}" style="{{ $previewInline }}">
                <div class="appearance-live-preview-meta">
                    <article class="appearance-live-preview-meta-card">
                        <span class="appearance-live-preview-meta-label">Version publique</span>
                        <strong>{{ $themeOptions[$publishedSettings['default_theme']] ?? $publishedSettings['default_theme'] }}</strong>
                        <span>{{ $densityOptions[$publishedSettings['visual_density']] ?? $publishedSettings['visual_density'] }} · {{ $contentWidthOptions[$publishedSettings['content_width']] ?? $publishedSettings['content_width'] }}</span>
                    </article>
                    <article class="appearance-live-preview-meta-card is-active">
                        <span class="appearance-live-preview-meta-label">{{ $hasDraft ? 'Brouillon en cours' : 'Version editee' }}</span>
                        <strong>{{ $themeOptions[$settings['default_theme']] ?? $settings['default_theme'] }}</strong>
                        <span>{{ $densityOptions[$settings['visual_density']] ?? $settings['visual_density'] }} · {{ $contentWidthOptions[$settings['content_width']] ?? $settings['content_width'] }}</span>
                    </article>
                </div>

                <div class="appearance-live-preview-toolbar mt-4">
                    <button class="appearance-live-preview-tab is-active" type="button" data-appearance-scene-button="dashboard">Dashboard</button>
                    <button class="appearance-live-preview-tab" type="button" data-appearance-scene-button="login">Connexion</button>
                    <button class="appearance-live-preview-tab" type="button" data-appearance-scene-button="table">Tableaux</button>
                    <button class="appearance-live-preview-tab" type="button" data-appearance-scene-button="components">Composants</button>
                </div>

                <section class="appearance-live-preview-scene is-active" data-appearance-scene="dashboard">
                    <div class="appearance-live-preview-shell">
                        <aside class="appearance-live-preview-sidebar">
                            <div class="appearance-live-preview-brand">PAS</div>
                            <div class="appearance-live-preview-nav">
                                <span class="is-active">Dashboard</span>
                                <span>Actions</span>
                                <span>Reporting</span>
                            </div>
                        </aside>
                        <div class="appearance-live-preview-main">
                            <header class="appearance-live-preview-header">
                                <div>
                                    <p class="appearance-live-preview-eyebrow">Pilotage</p>
                                    <h3 class="appearance-live-preview-title">Apercu du dashboard</h3>
                                </div>
                                <button type="button" class="appearance-live-preview-button appearance-live-preview-button-secondary">Exporter</button>
                            </header>

                            <div class="appearance-live-preview-content">
                                <div class="appearance-live-preview-kpis">
                                    <article class="appearance-live-preview-kpi">
                                        <span class="appearance-live-preview-kpi-label">Execution</span>
                                        <strong>82%</strong>
                                        <span class="appearance-live-preview-kpi-meta">Lecture consolidee</span>
                                    </article>
                                    <article class="appearance-live-preview-kpi">
                                        <span class="appearance-live-preview-kpi-label">Actions</span>
                                        <strong>124</strong>
                                        <span class="appearance-live-preview-kpi-meta">Suivi global</span>
                                    </article>
                                </div>

                                <section class="appearance-live-preview-card">
                                    <div class="appearance-live-preview-section-head">
                                        <div>
                                            <p class="appearance-live-preview-section-label">Graphique</p>
                                            <h4>Performance par direction</h4>
                                        </div>
                                    </div>
                                    <div class="appearance-live-preview-chart">
                                        <div class="appearance-live-preview-chart-bar"><span>DAF</span><i style="height: 82%"></i></div>
                                        <div class="appearance-live-preview-chart-bar"><span>DSIC</span><i style="height: 66%"></i></div>
                                        <div class="appearance-live-preview-chart-bar"><span>DS</span><i style="height: 74%"></i></div>
                                        <div class="appearance-live-preview-chart-bar"><span>UCAS</span><i style="height: 48%"></i></div>
                                    </div>
                                </section>

                                <section class="appearance-live-preview-card">
                                    <div class="appearance-live-preview-section-head">
                                        <div>
                                            <p class="appearance-live-preview-section-label">Tableau</p>
                                            <h4>Lecture des donnees</h4>
                                        </div>
                                    </div>
                                    <table class="appearance-live-preview-table">
                                        <thead>
                                            <tr><th>Direction</th><th>Valeur</th><th>Etat</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>DAF</td><td>32</td><td>Stable</td></tr>
                                            <tr><td>DSIC</td><td>18</td><td>En hausse</td></tr>
                                        </tbody>
                                    </table>
                                </section>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="appearance-live-preview-scene" data-appearance-scene="login">
                    <div class="appearance-live-preview-login-shell">
                        <div class="appearance-live-preview-login-hero">
                            <p class="appearance-live-preview-eyebrow">Acces plateforme</p>
                            <h3 class="appearance-live-preview-title">Page de connexion</h3>
                            <p class="appearance-live-preview-login-copy">Controle la lecture du hero, des champs et du formulaire public.</p>
                        </div>
                        <article class="appearance-live-preview-login-card">
                            <div>
                                <p class="appearance-live-preview-section-label">Authentification</p>
                                <h4>Connexion utilisateur</h4>
                            </div>
                            <div class="appearance-live-preview-form-grid">
                                <label>
                                    <span>Identifiant</span>
                                    <input type="text" value="SAD-001" readonly>
                                </label>
                                <label>
                                    <span>Mot de passe</span>
                                    <input type="text" value="••••••••" readonly>
                                </label>
                            </div>
                            <div class="appearance-live-preview-actions">
                                <button type="button" class="appearance-live-preview-button appearance-live-preview-button-primary">Se connecter</button>
                                <button type="button" class="appearance-live-preview-button appearance-live-preview-button-secondary">Assistance</button>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="appearance-live-preview-scene" data-appearance-scene="table">
                    <div class="appearance-live-preview-stack">
                        <section class="appearance-live-preview-card">
                            <div class="appearance-live-preview-section-head">
                                <div>
                                    <p class="appearance-live-preview-section-label">Tableau principal</p>
                                    <h4>Suivi des actions</h4>
                                </div>
                                <div class="appearance-live-preview-actions">
                                    <button type="button" class="appearance-live-preview-button appearance-live-preview-button-secondary">Filtrer</button>
                                    <button type="button" class="appearance-live-preview-button appearance-live-preview-button-primary">Exporter</button>
                                </div>
                            </div>
                            <table class="appearance-live-preview-table">
                                <thead>
                                    <tr><th>Action</th><th>Responsable</th><th>Avancement</th><th>Statut</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Numerisation des dossiers</td><td>DSIC</td><td>76%</td><td>En cours</td></tr>
                                    <tr><td>Cadre budgetaire</td><td>DAF</td><td>54%</td><td>A risque</td></tr>
                                    <tr><td>Suivi des bourses</td><td>DS</td><td>91%</td><td>Validee</td></tr>
                                </tbody>
                            </table>
                        </section>
                    </div>
                </section>

                <section class="appearance-live-preview-scene" data-appearance-scene="components">
                    <div class="appearance-live-preview-stack">
                        <section class="appearance-live-preview-card">
                            <div class="appearance-live-preview-section-head">
                                <div>
                                    <p class="appearance-live-preview-section-label">Composants</p>
                                    <h4>Boutons et champs</h4>
                                </div>
                            </div>
                            <div class="appearance-live-preview-actions">
                                <button type="button" class="appearance-live-preview-button appearance-live-preview-button-primary">Principal</button>
                                <button type="button" class="appearance-live-preview-button appearance-live-preview-button-secondary">Secondaire</button>
                                <button type="button" class="appearance-live-preview-button appearance-live-preview-button-warning">Alerte</button>
                            </div>
                            <div class="appearance-live-preview-form-grid mt-4">
                                <label>
                                    <span>Champ texte</span>
                                    <input type="text" value="Exemple" readonly>
                                </label>
                                <label>
                                    <span>Selection</span>
                                    <select disabled>
                                        <option>Valeur active</option>
                                    </select>
                                </label>
                            </div>
                        </section>
                    </div>
                </section>
            </div>
        </aside>
    </section>

    @push('head')
        <style>
            .appearance-live-preview {
                color: var(--app-text-color);
                font-family: var(--app-font-family);
            }

            .appearance-live-preview h3,
            .appearance-live-preview h4,
            .appearance-live-preview .appearance-live-preview-title {
                font-family: var(--app-heading-font-family);
            }

            .appearance-live-preview-meta {
                display: grid;
                gap: 0.75rem;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .appearance-live-preview-meta-card {
                display: flex;
                flex-direction: column;
                gap: 0.2rem;
                padding: 0.85rem 0.95rem;
                border-radius: calc(var(--app-card-radius) - 0.2rem);
                border: 1px solid rgb(var(--app-border-color-rgb) / 0.7);
                background: rgb(var(--app-card-background-rgb) / 0.82);
            }

            .appearance-live-preview-meta-card.is-active {
                border-color: rgb(var(--app-primary-rgb) / 0.7);
                box-shadow: 0 0 0 1px rgb(var(--app-primary-rgb) / 0.14);
            }

            .appearance-live-preview-meta-label {
                font-size: 0.64rem;
                font-weight: 700;
                letter-spacing: 0.12em;
                text-transform: uppercase;
                color: var(--app-muted-text-color);
            }

            .appearance-live-preview-toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 0.55rem;
            }

            .appearance-live-preview-tab {
                border: 1px solid rgb(var(--app-border-color-rgb) / 0.7);
                background: rgb(var(--app-card-background-rgb) / 0.85);
                color: var(--app-text-color);
                border-radius: 999px;
                padding: 0.55rem 0.85rem;
                font-size: 0.76rem;
                font-weight: 700;
            }

            .appearance-live-preview-tab.is-active {
                background: rgb(var(--app-primary-rgb) / 0.14);
                border-color: rgb(var(--app-primary-rgb) / 0.68);
                color: rgb(var(--app-primary-rgb));
            }

            .appearance-live-preview-scene {
                display: none;
            }

            .appearance-live-preview-scene.is-active {
                display: block;
            }

            .appearance-live-preview-shell {
                display: grid;
                grid-template-columns: var(--app-sidebar-width) minmax(0, 1fr);
                min-height: 34rem;
                overflow: hidden;
                border-radius: calc(var(--app-card-radius) + 0.1rem);
                border: 1px solid rgb(var(--app-border-color-rgb) / 0.8);
                background: var(--app-body-bg-light);
                box-shadow: var(--app-card-shadow);
            }

            .appearance-live-preview[data-theme='dark'] .appearance-live-preview-shell {
                background: var(--app-body-bg-dark);
                color: rgb(241 245 249);
                box-shadow: var(--app-card-shadow-dark);
            }

            .appearance-live-preview-sidebar {
                display: flex;
                flex-direction: column;
                gap: 1rem;
                padding: 1rem 0.85rem;
                background: var(--app-sidebar-bg);
                color: #fff;
            }

            .appearance-live-preview-brand {
                font-size: 0.95rem;
                font-weight: 800;
                letter-spacing: 0.12em;
                text-transform: uppercase;
            }

            .appearance-live-preview-nav {
                display: flex;
                flex-direction: column;
                gap: 0.45rem;
            }

            .appearance-live-preview-nav span {
                padding: 0.6rem 0.75rem;
                border-radius: calc(var(--app-button-radius) - 0.25rem);
                background: rgb(255 255 255 / 0.08);
                font-size: 0.78rem;
                font-weight: 600;
            }

            .appearance-live-preview-nav span.is-active {
                background: rgb(255 255 255 / 0.16);
            }

            .appearance-live-preview-main {
                display: flex;
                flex-direction: column;
                min-width: 0;
            }

            .appearance-live-preview-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                padding: 1rem 1.1rem;
                background: var(--app-header-bg-light);
                border-bottom: 1px solid rgb(var(--app-border-color-rgb) / 0.78);
            }

            .appearance-live-preview[data-theme='dark'] .appearance-live-preview-header {
                background: var(--app-header-bg-dark);
                border-bottom-color: rgb(var(--app-border-color-rgb) / 0.3);
            }

            .appearance-live-preview-eyebrow,
            .appearance-live-preview-section-label,
            .appearance-live-preview-kpi-label {
                font-size: 0.66rem;
                font-weight: 700;
                letter-spacing: 0.14em;
                text-transform: uppercase;
                color: var(--app-muted-text-color);
            }

            .appearance-live-preview-title,
            .appearance-live-preview-section-head h4 {
                margin: 0.2rem 0 0;
                font-size: 1.05rem;
                font-weight: 700;
                color: inherit;
            }

            .appearance-live-preview-content {
                display: flex;
                flex-direction: column;
                gap: var(--app-density-gap);
                padding: 1rem;
            }

            .appearance-live-preview-stack {
                display: flex;
                flex-direction: column;
                gap: var(--app-density-gap);
            }

            .appearance-live-preview-kpis {
                display: grid;
                gap: var(--app-density-gap);
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .appearance-live-preview-kpi,
            .appearance-live-preview-card {
                border-radius: var(--app-card-radius);
                border: 1px solid rgb(var(--app-border-color-rgb) / 0.78);
                background: var(--app-card-surface-light);
                box-shadow: var(--app-card-shadow);
                backdrop-filter: blur(var(--app-card-blur));
            }

            .appearance-live-preview[data-theme='dark'] .appearance-live-preview-kpi,
            .appearance-live-preview[data-theme='dark'] .appearance-live-preview-card {
                background: var(--app-card-surface-dark);
                box-shadow: var(--app-card-shadow-dark);
            }

            .appearance-live-preview-kpi {
                display: flex;
                flex-direction: column;
                gap: 0.35rem;
                padding: 1rem;
            }

            .appearance-live-preview-kpi strong {
                font-size: 1.55rem;
                line-height: 1;
            }

            .appearance-live-preview-kpi-meta {
                font-size: 0.76rem;
                color: var(--app-muted-text-color);
            }

            .appearance-live-preview-card {
                padding: 1rem;
            }

            .appearance-live-preview-section-head {
                display: flex;
                align-items: start;
                justify-content: space-between;
                gap: 1rem;
                margin-bottom: 0.9rem;
            }

            .appearance-live-preview-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.55rem;
            }

            .appearance-live-preview-chart {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                align-items: end;
                gap: 0.9rem;
                min-height: 12rem;
                padding-top: 0.8rem;
            }

            .appearance-live-preview-chart-bar {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 0.65rem;
                min-height: 100%;
                justify-content: end;
            }

            .appearance-live-preview-chart-bar span {
                font-size: 0.72rem;
                font-weight: 700;
                color: var(--app-muted-text-color);
            }

            .appearance-live-preview-chart-bar i {
                display: block;
                width: 100%;
                min-height: 1.8rem;
                border-radius: var(--app-button-radius) var(--app-button-radius) 0.35rem 0.35rem;
                background: linear-gradient(180deg, rgb(var(--app-primary-rgb) / 0.88) 0%, rgb(var(--app-secondary-rgb) / 0.8) 100%);
                box-shadow: inset 0 -1px 0 rgb(255 255 255 / 0.08);
            }

            .appearance-live-preview-button {
                border: 0;
                border-radius: var(--app-button-radius);
                padding: 0.72rem 0.95rem;
                font-size: 0.78rem;
                font-weight: 700;
                cursor: default;
            }

            .appearance-live-preview-button-primary {
                background: var(--app-button-primary-bg);
                color: var(--app-button-primary-text);
            }

            .appearance-live-preview-button-secondary {
                background: var(--app-button-secondary-bg);
                color: var(--app-button-secondary-text);
            }

            .appearance-live-preview-button-warning {
                background: var(--app-button-warning-bg);
                color: var(--app-button-warning-text);
            }

            .appearance-live-preview-form-grid {
                display: grid;
                gap: 0.9rem;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .appearance-live-preview-form-grid label {
                display: flex;
                flex-direction: column;
                gap: 0.45rem;
                font-size: 0.78rem;
                color: var(--app-muted-text-color);
            }

            .appearance-live-preview-form-grid input,
            .appearance-live-preview-form-grid select {
                min-height: 2.75rem;
                border-radius: var(--app-input-radius);
                border: 1px solid var(--app-input-border-color);
                background: var(--app-input-surface-light);
                color: var(--app-text-color);
                padding: 0.7rem 0.85rem;
            }

            .appearance-live-preview[data-theme='dark'] .appearance-live-preview-form-grid input,
            .appearance-live-preview[data-theme='dark'] .appearance-live-preview-form-grid select {
                background: var(--app-input-surface-dark);
                color: rgb(241 245 249);
            }

            .appearance-live-preview-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
                font-size: 0.78rem;
            }

            .appearance-live-preview-table th,
            .appearance-live-preview-table td {
                padding: 0.65rem 0.75rem;
                border-bottom: 1px solid rgb(var(--app-border-color-rgb) / 0.72);
                text-align: left;
            }

            .appearance-live-preview-table th {
                background: var(--app-table-head-bg-light);
                color: var(--app-muted-text-color);
                font-size: 0.64rem;
                letter-spacing: 0.12em;
                text-transform: uppercase;
            }

            .appearance-live-preview[data-theme='dark'] .appearance-live-preview-table th {
                background: var(--app-table-head-bg-dark);
            }

            .appearance-live-preview-table tbody tr:hover td {
                background: var(--app-table-row-hover-light);
            }

            .appearance-live-preview[data-theme='dark'] .appearance-live-preview-table tbody tr:hover td {
                background: var(--app-table-row-hover-dark);
            }

            .appearance-live-preview-login-shell {
                display: grid;
                gap: var(--app-density-gap);
                min-height: 34rem;
                padding: 1rem;
                background: var(--app-body-bg-light);
                border-radius: calc(var(--app-card-radius) + 0.1rem);
                border: 1px solid rgb(var(--app-border-color-rgb) / 0.8);
                box-shadow: var(--app-card-shadow);
            }

            .appearance-live-preview[data-theme='dark'] .appearance-live-preview-login-shell {
                background: var(--app-body-bg-dark);
                color: rgb(241 245 249);
                box-shadow: var(--app-card-shadow-dark);
            }

            .appearance-live-preview-login-hero,
            .appearance-live-preview-login-card {
                border-radius: var(--app-card-radius);
                border: 1px solid rgb(var(--app-border-color-rgb) / 0.75);
                background: var(--app-card-surface-light);
                box-shadow: var(--app-card-shadow);
                padding: 1.1rem;
            }

            .appearance-live-preview[data-theme='dark'] .appearance-live-preview-login-hero,
            .appearance-live-preview[data-theme='dark'] .appearance-live-preview-login-card {
                background: var(--app-card-surface-dark);
                box-shadow: var(--app-card-shadow-dark);
            }

            .appearance-live-preview-login-copy {
                margin-top: 0.65rem;
                max-width: 38ch;
                color: var(--app-muted-text-color);
                font-size: 0.84rem;
            }

            @media (max-width: 900px) {
                .appearance-live-preview-meta,
                .appearance-live-preview-shell {
                    grid-template-columns: 1fr;
                }

                .appearance-live-preview-form-grid,
                .appearance-live-preview-kpis,
                .appearance-live-preview-chart {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            (function () {
                var form = document.getElementById('appearance-form');
                var previewRoot = document.getElementById('appearance-live-preview');
                if (!form || !previewRoot) {
                    return;
                }

                var endpoint = form.getAttribute('data-appearance-preview-endpoint');
                var csrf = document.querySelector('meta[name="csrf-token"]');
                var themeNode = document.getElementById('appearance-preview-theme');
                var densityNode = document.getElementById('appearance-preview-density');
                var widthNode = document.getElementById('appearance-preview-width');
                var methodInput = document.getElementById('appearance-method-spoof');
                var defaultAction = form.getAttribute('action');
                var requestToken = 0;
                var debounceTimer = null;
                var densityLabels = @json($densityOptions);
                var widthLabels = @json($contentWidthOptions);
                var themeLabels = @json($themeOptions);

                function applySubmitIntent(submitter) {
                    if (!submitter) {
                        return;
                    }

                    var targetAction = submitter.getAttribute('data-appearance-action') || defaultAction;
                    var targetMethod = (submitter.getAttribute('data-appearance-method') || 'PUT').toUpperCase();

                    form.setAttribute('action', targetAction);

                    if (!methodInput) {
                        return;
                    }

                    if (targetMethod === 'POST') {
                        methodInput.disabled = true;
                        methodInput.value = 'PUT';
                        return;
                    }

                    methodInput.disabled = false;
                    methodInput.value = targetMethod;
                }

                function applyPreview(payload) {
                    if (!payload || !payload.css_variables) {
                        return;
                    }

                    Object.entries(payload.css_variables).forEach(function (entry) {
                        previewRoot.style.setProperty(entry[0], entry[1]);
                    });

                    var settings = payload.settings || {};
                    previewRoot.setAttribute('data-theme', settings.default_theme || 'dark');

                    if (themeNode) {
                        themeNode.textContent = 'Theme : ' + (themeLabels[settings.default_theme] || settings.default_theme || '-');
                    }
                    if (densityNode) {
                        densityNode.textContent = 'Densite : ' + (densityLabels[settings.visual_density] || settings.visual_density || '-');
                    }
                    if (widthNode) {
                        widthNode.textContent = 'Lecture : ' + (widthLabels[settings.content_width] || settings.content_width || '-');
                    }
                }

                function requestPreview() {
                    if (!endpoint) {
                        return;
                    }

                    requestToken += 1;
                    var currentToken = requestToken;
                    var formData = new FormData(form);

                    fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        body: formData,
                    })
                        .then(function (response) {
                            return response.ok ? response.json() : null;
                        })
                        .then(function (payload) {
                            if (!payload || currentToken !== requestToken) {
                                return;
                            }

                            applyPreview(payload);
                        })
                        .catch(function () {
                            // preview is progressive
                        });
                }

                form.querySelectorAll('[data-sync-color]').forEach(function (picker) {
                    picker.addEventListener('input', function () {
                        var target = document.getElementById(this.getAttribute('data-sync-color'));
                        if (target) {
                            target.value = this.value.toUpperCase();
                        }
                    });
                });

                form.querySelectorAll('input[type="text"]').forEach(function (input) {
                    if (!input.id || !document.getElementById(input.id + '_picker')) {
                        return;
                    }

                    input.addEventListener('input', function () {
                        var value = this.value.trim();
                        var picker = document.getElementById(this.id + '_picker');
                        if (picker && /^#?[0-9a-fA-F]{6}$/.test(value)) {
                            picker.value = (value.charAt(0) === '#' ? value : '#' + value).toUpperCase();
                        }
                    });
                });

                form.querySelectorAll('[data-appearance-action]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        applySubmitIntent(this);
                    });
                });

                form.addEventListener('input', function () {
                    window.clearTimeout(debounceTimer);
                    debounceTimer = window.setTimeout(requestPreview, 180);
                });

                form.addEventListener('change', function () {
                    window.clearTimeout(debounceTimer);
                    debounceTimer = window.setTimeout(requestPreview, 80);
                });

                document.querySelectorAll('[data-appearance-scene-button]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var scene = this.getAttribute('data-appearance-scene-button');

                        document.querySelectorAll('[data-appearance-scene-button]').forEach(function (node) {
                            node.classList.toggle('is-active', node === button);
                        });

                        document.querySelectorAll('[data-appearance-scene]').forEach(function (node) {
                            node.classList.toggle('is-active', node.getAttribute('data-appearance-scene') === scene);
                        });
                    });
                });

                applySubmitIntent(form.querySelector('[data-appearance-method="PUT"]'));
                applyPreview(@json($previewPayload));
            })();
        </script>
    @endpush
@endsection

