<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class PremiumUiComponentsTest extends TestCase
{
    public function test_global_loader_exposes_the_accessible_blocking_contract(): void
    {
        $html = Blade::render('<x-ui.process-bubble />');

        $this->assertStringContainsString('data-global-loader', $html);
        $this->assertStringContainsString('data-process-bubble', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('data-global-loader-message', $html);
        $this->assertStringContainsString('global-loader-spinner', $html);
        $this->assertStringNotContainsString('data-process-progress-bar', $html);
        $this->assertStringNotContainsString('data-process-close', $html);
        $this->assertStringNotContainsString('premium-loading-orb', $html);
    }

    public function test_global_loader_assets_and_operation_counter_are_loaded(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/ui-system.css'));

        $this->assertIsString($javascript);
        $this->assertIsString($styles);
        $this->assertStringContainsString("import './premium-process-bubble';", $javascript);
        $this->assertStringContainsString("import './data-table-enhancements';", $javascript);
        $this->assertStringContainsString('.global-loader-overlay', $styles);
        $this->assertStringContainsString('z-index: 110000', $styles);
        $this->assertStringContainsString('prefers-reduced-motion', $styles);

        $processJavascript = file_get_contents(resource_path('js/premium-process-bubble.js'));
        $notificationJavascript = file_get_contents(resource_path('js/ui-enhancements.js'));
        $adminShellJavascript = file_get_contents(resource_path('js/admin-shell.js'));

        $this->assertIsString($processJavascript);
        $this->assertIsString($notificationJavascript);
        $this->assertIsString($adminShellJavascript);
        $this->assertStringContainsString("document.readyState === 'loading'", $processJavascript);
        $this->assertStringContainsString('const activeOperations = new Map()', $processJavascript);
        $this->assertStringContainsString('get activeCount()', $processJavascript);
        $this->assertStringContainsString("document.body.setAttribute('aria-busy', 'true')", $processJavascript);
        $this->assertStringContainsString("pageRoot?.setAttribute('inert', '')", $processJavascript);
        $this->assertStringContainsString('beginFormSubmission', $processJavascript);
        $this->assertStringContainsString('submitConfirmedForm(form, submitter)', $adminShellJavascript);
        $this->assertStringNotContainsString('SPINNER_HTML', $notificationJavascript);
        $this->assertStringNotContainsString('Envoi en cours', $notificationJavascript);
        $this->assertStringContainsString('.flash-warning', $notificationJavascript);
    }

    public function test_shared_data_table_is_responsive_without_forced_desktop_width(): void
    {
        $html = Blade::render('<x-ui.data-table title="Actions" description="Liste filtrable" enhanced mobile-cards :page-size="25"><tbody><tr><td>Valeur</td></tr></tbody></x-ui.data-table>');

        $this->assertStringContainsString('min-w-full', $html);
        $this->assertStringNotContainsString('min-w-[1200px]', $html);
        $this->assertStringContainsString('data-table-enhanced', $html);
        $this->assertStringContainsString('data-table-page-size="25"', $html);
        $this->assertStringContainsString('data-mobile-cards', $html);
        $this->assertStringContainsString('Liste filtrable', $html);
    }

    public function test_opt_in_data_tables_offer_search_sort_and_local_pagination(): void
    {
        $javascript = file_get_contents(resource_path('js/data-table-enhancements.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString("const TABLE_SELECTOR = 'table[data-table-enhanced]'", $javascript);
        $this->assertStringContainsString('const PAGE_SIZES = [10, 25, 50, 100]', $javascript);
        $this->assertStringContainsString('data-table-search-input', $javascript);
        $this->assertStringContainsString('data-table-sort-button', $javascript);
        $this->assertStringContainsString('data-table-pagination', $javascript);
        $this->assertStringContainsString('dataset.tableFilterMatch', $javascript);
        $this->assertStringContainsString('data-table-column-menu', $javascript);
        $this->assertStringContainsString('data-table-resize-handle', $javascript);
    }

    public function test_pta_detailed_view_keeps_filters_and_exposes_complete_analysis_tables(): void
    {
        $dashboard = file_get_contents(resource_path('views/partials/dashboard-analytics.blade.php'));
        $details = file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-tables.blade.php'));

        $this->assertIsString($dashboard);
        $this->assertIsString($details);
        $this->assertStringContainsString('name="dashboardTab" value="{{ $currentDashboardTab }}"', $dashboard);
        $this->assertStringContainsString('data-dashboard-synthesis-filter-form', $dashboard);
        $this->assertStringContainsString('En retard / non réalisées', $details);
        $this->assertStringContainsString('Non démarrées', $details);
        $this->assertStringContainsString('Actions partiellement réalisées', $details);
        $this->assertStringContainsString('Actions reportées', $details);
        $this->assertStringContainsString('Mesures correctives', $details);
        $this->assertStringContainsString('data-table-enhanced', $details);
        $this->assertStringContainsString('data-mobile-cards', $details);
        $this->assertStringContainsString('max-w-[42rem]', $details);
    }

    public function test_pta_ajax_actions_keep_button_labels_stable_and_release_the_global_loader(): void
    {
        $ptaForm = file_get_contents(resource_path('views/workspace/pta/form.blade.php'));
        $ptaTracking = file_get_contents(resource_path('views/workspace/pta-suivi/index.blade.php'));

        $this->assertIsString($ptaForm);
        $this->assertIsString($ptaTracking);
        $this->assertStringNotContainsString("textContent = 'Envoi…'", $ptaForm);
        $this->assertStringNotContainsString("textContent = 'Sauvegarde…'", $ptaForm);
        $this->assertStringNotContainsString("textContent = 'Enregistrement...'", $ptaTracking);
        $this->assertStringContainsString('window.AnBGLoader?.start', $ptaForm);
        $this->assertStringContainsString('window.AnBGLoader?.finish', $ptaForm);
        $this->assertStringContainsString('window.AnBGLoader?.start', $ptaTracking);
        $this->assertStringContainsString('window.AnBGLoader?.finish', $ptaTracking);
    }

    public function test_table_preview_only_opens_from_its_dedicated_trigger(): void
    {
        $javascript = file_get_contents(resource_path('js/preview-modal.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('[data-preview-table-trigger]', $javascript);
        $this->assertStringNotContainsString(
            "event.target.closest('table.app-table.preview-table-clickable')",
            $javascript
        );
    }

    public function test_shared_form_controls_use_the_unified_accessible_presentation(): void
    {
        $errors = new ViewErrorBag;
        $html = Blade::render(<<<'BLADE'
            <x-form.input label="Titre" name="titre" hint="Saisissez un titre clair." required />
            <x-form.select label="Statut" name="statut" required>
                <option value="actif">Actif</option>
            </x-form.select>
            <x-form.textarea label="Description" name="description" />
        BLADE, compact('errors'));

        $this->assertStringContainsString('app-form-field', $html);
        $this->assertStringContainsString('app-form-control', $html);
        $this->assertStringContainsString('app-form-label', $html);
        $this->assertStringContainsString('is-required', $html);
        $this->assertStringContainsString('aria-describedby="titre-hint"', $html);
        $this->assertStringContainsString('aria-invalid="false"', $html);
    }

    public function test_global_form_enhancement_preserves_filters_and_compact_table_actions(): void
    {
        $javascript = file_get_contents(resource_path('js/ui-enhancements.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($javascript);
        $this->assertIsString($styles);
        $this->assertStringContainsString('function enhanceForm(form)', $javascript);
        $this->assertStringContainsString("form.method.toLowerCase() === 'get'", $javascript);
        $this->assertStringContainsString("'.app-table-wrapper'", $javascript);
        $this->assertStringContainsString("form.classList.toggle('app-form-compact'", $javascript);
        $this->assertStringContainsString('function initDeadlineChangeFields()', $javascript);
        $this->assertStringContainsString("scope === 'all' || scope === targetKind", $javascript);
        $this->assertStringContainsString('.app-form-surface', $styles);
        $this->assertStringContainsString('.app-form-control:focus-visible', $styles);
        $this->assertStringContainsString('html.dark body.admin-theme-scope .app-form-control', $styles);
    }

    public function test_password_toggle_is_a_centered_accessible_icon_control(): void
    {
        $html = Blade::render('<div class="relative"><input id="password" type="password"><x-auth.password-toggle target="password" /></div>');
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-password-toggle="password"', $html);
        $this->assertStringContainsString('aria-controls="password"', $html);
        $this->assertStringContainsString('.auth-password-toggle', $styles);
        $this->assertStringContainsString('box-sizing: border-box', $styles);
        $this->assertStringContainsString('justify-content: center', $styles);
        $this->assertStringContainsString('html.dark .auth-password-toggle', $styles);
    }

    public function test_collapsed_sidebar_uses_the_same_official_favicon_as_the_application_icon(): void
    {
        $sidebar = file_get_contents(resource_path('views/components/admin/sidebar.blade.php'));
        $glassStyles = file_get_contents(resource_path('css/anbg-glass.css'));

        $this->assertIsString($sidebar);
        $this->assertIsString($glassStyles);
        $this->assertStringContainsString("asset('favicon.png')", $sidebar);
        $this->assertStringNotContainsString("asset('images/logo-anbg-flamme.png')", $sidebar);
        $this->assertStringNotContainsString("background-image: url('/images/logo-anbg-flamme.png')", $glassStyles);
    }
}
