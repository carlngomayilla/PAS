@extends('layouts.workspace')

@section('title', 'Parametres generaux')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Parametres generaux</h1>
                <p class="mt-2 text-slate-600">Pilotage des textes, intitulés et libellés globaux réellement visibles dans l application.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.templates.index') }}">Templates d export</a>
            </div>
        </div>
    </section>

    <x-super-admin.draft-banner
        :has-draft="$hasDraft"
        :draft-updated-at="$draftUpdatedAt"
        message="Les libelles publics n ont pas encore ete publies"
        :publish-route="route('workspace.super-admin.settings.publish-draft')"
        :discard-route="route('workspace.super-admin.settings.discard-draft')"
    />

    <section class="ui-card mb-3.5">
        <form method="POST" action="{{ route('workspace.super-admin.settings.update') }}" class="form-shell" enctype="multipart/form-data" id="general-settings-form">
            @csrf
            <input id="general-settings-method-spoof" name="_method" type="hidden" value="PUT">

            <div class="form-section">
                <h2 class="form-section-title">Identite applicative</h2>
                <div class="form-grid">
                    <div><label for="app_name">Nom application</label><input id="app_name" name="app_name" type="text" value="{{ old('app_name', $settings['app_name']) }}" required></div>
                    <div><label for="app_short_name">Sigle court</label><input id="app_short_name" name="app_short_name" type="text" value="{{ old('app_short_name', $settings['app_short_name']) }}" required></div>
                    <div class="md:col-span-2 xl:col-span-4"><label for="institution_label">Intitule institutionnel</label><input id="institution_label" name="institution_label" type="text" value="{{ old('institution_label', $settings['institution_label']) }}" required></div>
                    <div>
                        <label for="logo_mark">Logo compact</label>
                        <input id="logo_mark" name="logo_mark" type="file" accept=".png,.jpg,.jpeg,.webp,.svg">
                    </div>
                    <div>
                        <label for="logo_wordmark">Logo wordmark</label>
                        <input id="logo_wordmark" name="logo_wordmark" type="file" accept=".png,.jpg,.jpeg,.webp,.svg">
                    </div>
                    <div>
                        <label for="logo_full">Logo complet</label>
                        <input id="logo_full" name="logo_full" type="file" accept=".png,.jpg,.jpeg,.webp,.svg">
                    </div>
                    <div>
                        <label for="favicon">Favicon</label>
                        <input id="favicon" name="favicon" type="file" accept=".png,.ico,.svg">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Langue et formats globaux</h2>
                <div class="form-grid">
                    <div>
                        <label for="default_locale">Langue par defaut</label>
                        <select id="default_locale" name="default_locale" required>
                            @foreach ($localeOptions as $localeCode => $localeLabel)
                                <option value="{{ $localeCode }}" @selected(old('default_locale', $settings['default_locale']) === $localeCode)>{{ $localeLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="default_timezone">Fuseau horaire</label>
                        <select id="default_timezone" name="default_timezone" required>
                            @foreach ($timezoneOptions as $timezoneValue => $timezoneLabel)
                                <option value="{{ $timezoneValue }}" @selected(old('default_timezone', $settings['default_timezone']) === $timezoneValue)>{{ $timezoneLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="date_format">Format date</label>
                        <select id="date_format" name="date_format" required>
                            @foreach ($dateFormatOptions as $formatValue => $formatLabel)
                                <option value="{{ $formatValue }}" @selected(old('date_format', $settings['date_format']) === $formatValue)>{{ $formatLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="datetime_format">Format date + heure</label>
                        <select id="datetime_format" name="datetime_format" required>
                            @foreach ($dateTimeFormatOptions as $formatValue => $formatLabel)
                                <option value="{{ $formatValue }}" @selected(old('datetime_format', $settings['datetime_format']) === $formatValue)>{{ $formatLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="number_precision">Precision numerique</label>
                        <select id="number_precision" name="number_precision" required>
                            @foreach ($numberPrecisionOptions as $precisionValue => $precisionLabel)
                                <option value="{{ $precisionValue }}" @selected((string) old('number_precision', $settings['number_precision']) === (string) $precisionValue)>{{ $precisionLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="number_decimal_separator">Separateur decimal</label>
                        <input id="number_decimal_separator" name="number_decimal_separator" type="text" maxlength="2" value="{{ old('number_decimal_separator', $settings['number_decimal_separator']) }}" required>
                    </div>
                    <div>
                        <label for="number_thousands_separator">Separateur milliers</label>
                        <input id="number_thousands_separator" name="number_thousands_separator" type="text" maxlength="2" value="{{ old('number_thousands_separator', $settings['number_thousands_separator']) }}" required>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Interface connectee</h2>
                <div class="form-grid">
                    <div><label for="sidebar_caption">Libelle sidebar</label><input id="sidebar_caption" name="sidebar_caption" type="text" value="{{ old('sidebar_caption', $settings['sidebar_caption']) }}" required></div>
                    <div><label for="admin_header_eyebrow">Libelle header</label><input id="admin_header_eyebrow" name="admin_header_eyebrow" type="text" value="{{ old('admin_header_eyebrow', $settings['admin_header_eyebrow']) }}" required></div>
                    <div class="md:col-span-2 xl:col-span-4"><label for="footer_text">Footer global</label><input id="footer_text" name="footer_text" type="text" value="{{ old('footer_text', $settings['footer_text']) }}" required></div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Espace invite et connexion</h2>
                <div class="form-grid">
                    <div><label for="guest_space_label">Libelle espace invite</label><input id="guest_space_label" name="guest_space_label" type="text" value="{{ old('guest_space_label', $settings['guest_space_label']) }}" required></div>
                    <div><label for="login_page_title">Titre onglet connexion</label><input id="login_page_title" name="login_page_title" type="text" value="{{ old('login_page_title', $settings['login_page_title']) }}" required></div>
                    <div><label for="login_welcome_title">Titre de bienvenue</label><input id="login_welcome_title" name="login_welcome_title" type="text" value="{{ old('login_welcome_title', $settings['login_welcome_title']) }}" required></div>
                    <div><label for="login_welcome_text">Texte de bienvenue</label><input id="login_welcome_text" name="login_welcome_text" type="text" value="{{ old('login_welcome_text', $settings['login_welcome_text']) }}" required></div>
                    <div><label for="login_form_title">Titre du formulaire</label><input id="login_form_title" name="login_form_title" type="text" value="{{ old('login_form_title', $settings['login_form_title']) }}" required></div>
                    <div><label for="login_form_subtitle">Sous-titre du formulaire</label><input id="login_form_subtitle" name="login_form_subtitle" type="text" value="{{ old('login_form_subtitle', $settings['login_form_subtitle']) }}" required></div>
                    <div><label for="login_identifier_label">Libelle identifiant</label><input id="login_identifier_label" name="login_identifier_label" type="text" value="{{ old('login_identifier_label', $settings['login_identifier_label']) }}" required></div>
                    <div><label for="login_identifier_placeholder">Placeholder identifiant</label><input id="login_identifier_placeholder" name="login_identifier_placeholder" type="text" value="{{ old('login_identifier_placeholder', $settings['login_identifier_placeholder']) }}" required></div>
                    <div class="md:col-span-2 xl:col-span-4"><label for="login_helper_text">Aide de connexion</label><input id="login_helper_text" name="login_helper_text" type="text" value="{{ old('login_helper_text', $settings['login_helper_text']) }}" required></div>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-secondary" type="submit" data-draft-action="{{ route('workspace.super-admin.settings.draft') }}" data-draft-method="POST">Enregistrer le brouillon</button>
                <button class="btn btn-primary" type="submit" data-draft-action="{{ route('workspace.super-admin.settings.update') }}" data-draft-method="PUT">Publier maintenant</button>
            </div>
        </form>
    </section>

    <x-super-admin.compare-panels :editable-title="$hasDraft ? 'Brouillon / edition' : 'Version en edition'">
        <x-slot:published>
            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                <article class="rounded-3xl border border-slate-200/80 bg-white/90 p-4 dark:border-slate-700 dark:bg-slate-900/80"><p class="text-sm text-slate-500">Titre public</p><p class="mt-2 text-xl font-semibold">{{ $publishedSettings['app_name'] }}</p><p class="mt-2 text-sm text-slate-600">{{ $publishedSettings['institution_label'] }}</p></article>
                <article class="rounded-3xl border border-slate-200/80 bg-white/90 p-4 dark:border-slate-700 dark:bg-slate-900/80"><p class="text-sm text-slate-500">Connexion</p><p class="mt-2 text-xl font-semibold">{{ $publishedSettings['login_form_title'] }}</p><p class="mt-2 text-sm text-slate-600">{{ $publishedSettings['login_form_subtitle'] }}</p></article>
                <article class="rounded-3xl border border-slate-200/80 bg-white/90 p-4 dark:border-slate-700 dark:bg-slate-900/80"><p class="text-sm text-slate-500">Footer</p><p class="mt-2 text-sm text-slate-700 dark:text-slate-200">{{ $publishedSettings['footer_text'] }}</p></article>
                <article class="rounded-3xl border border-slate-200/80 bg-white/90 p-4 dark:border-slate-700 dark:bg-slate-900/80">
                    <p class="text-sm text-slate-500">Formats actifs</p>
                    <p class="mt-2 text-sm text-slate-700 dark:text-slate-200">Langue : {{ strtoupper($publishedSettings['default_locale']) }}</p>
                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">Fuseau : {{ $publishedSettings['default_timezone'] }}</p>
                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">Date : {{ $dateFormatOptions[$publishedSettings['date_format']] ?? $publishedSettings['date_format'] }}</p>
                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">Heure : {{ $dateTimeFormatOptions[$publishedSettings['datetime_format']] ?? $publishedSettings['datetime_format'] }}</p>
                </article>
            </div>
        </x-slot:published>

        <x-slot:editable>
            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                <article class="rounded-3xl border border-blue-200/80 bg-blue-50/70 p-4 dark:border-blue-700/60 dark:bg-blue-950/20"><p class="text-sm text-slate-500">Titre edite</p><p class="mt-2 text-xl font-semibold">{{ $settings['app_name'] }}</p><p class="mt-2 text-sm text-slate-600">{{ $settings['institution_label'] }}</p></article>
                <article class="rounded-3xl border border-blue-200/80 bg-blue-50/70 p-4 dark:border-blue-700/60 dark:bg-blue-950/20"><p class="text-sm text-slate-500">Connexion</p><p class="mt-2 text-xl font-semibold">{{ $settings['login_form_title'] }}</p><p class="mt-2 text-sm text-slate-600">{{ $settings['login_form_subtitle'] }}</p></article>
                <article class="rounded-3xl border border-blue-200/80 bg-blue-50/70 p-4 dark:border-blue-700/60 dark:bg-blue-950/20"><p class="text-sm text-slate-500">Footer</p><p class="mt-2 text-sm text-slate-700 dark:text-slate-200">{{ $settings['footer_text'] }}</p></article>
                <article class="rounded-3xl border border-blue-200/80 bg-blue-50/70 p-4 dark:border-blue-700/60 dark:bg-blue-950/20">
                    <p class="text-sm text-slate-500">Formats edites</p>
                    <p class="mt-2 text-sm text-slate-700 dark:text-slate-200">Langue : {{ strtoupper($settings['default_locale']) }}</p>
                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">Fuseau : {{ $settings['default_timezone'] }}</p>
                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">Date : {{ $dateFormatOptions[$settings['date_format']] ?? $settings['date_format'] }}</p>
                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">Heure : {{ $dateTimeFormatOptions[$settings['datetime_format']] ?? $settings['datetime_format'] }}</p>
                </article>
                <article class="rounded-3xl border border-blue-200/80 bg-blue-50/70 p-4 dark:border-blue-700/60 dark:bg-blue-950/20">
                    <p class="text-sm text-slate-500">Identite visuelle</p>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <div class="rounded-2xl border border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-900">
                            <x-brand.logo variant="mark" class="h-10 w-auto" />
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                            <x-brand.logo variant="wordmark" class="h-8 w-auto" />
                        </div>
                    </div>
                </article>
            </div>
        </x-slot:editable>
    </x-super-admin.compare-panels>

    @push('scripts')
        <script>
            (function () {
                var form = document.getElementById('general-settings-form');
                var methodInput = document.getElementById('general-settings-method-spoof');
                if (!form || !methodInput) {
                    return;
                }

                var defaultAction = form.getAttribute('action');

                function applySubmitIntent(button) {
                    if (!button) {
                        return;
                    }

                    var action = button.getAttribute('data-draft-action') || defaultAction;
                    var method = (button.getAttribute('data-draft-method') || 'PUT').toUpperCase();

                    form.setAttribute('action', action);

                    if (method === 'POST') {
                        methodInput.disabled = true;
                        methodInput.value = 'PUT';
                        return;
                    }

                    methodInput.disabled = false;
                    methodInput.value = method;
                }

                form.querySelectorAll('[data-draft-action]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        applySubmitIntent(this);
                    });
                });

                applySubmitIntent(form.querySelector('[data-draft-method="PUT"]'));
            })();
        </script>
    @endpush
@endsection

