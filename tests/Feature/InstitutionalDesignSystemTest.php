<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstitutionalDesignSystemTest extends TestCase
{
    public function test_admin_shell_uses_the_canonical_design_system_contract(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/admin.blade.php'));

        $this->assertStringContainsString('data-ui-version="institutional-v2"', $layout);
        $this->assertStringContainsString('id="admin-spotlight-open"', $layout);
        $this->assertStringContainsString('aria-controls="spotlight-backdrop"', $layout);
        $this->assertStringContainsString('aria-pressed="false"', $layout);
        $this->assertStringContainsString('class="app-footer mt-8"', $layout);
        $this->assertStringNotContainsString('<style>', $layout);
    }

    public function test_semantic_tokens_cover_light_dark_responsive_and_reduced_motion_states(): void
    {
        $styles = (string) file_get_contents(resource_path('css/ui-system.css'));

        foreach ([
            '--ui-canvas:',
            '--ui-surface-raised:',
            '--ui-text:',
            '--ui-border-strong:',
            '--ui-brand:',
            '--ui-danger:',
            '--ui-success:',
            '--ui-warning:',
            '--ui-focus:',
            'html.dark {',
            '@media (max-width: 47.99rem)',
            '@media (prefers-reduced-motion: reduce)',
        ] as $contract) {
            $this->assertStringContainsString($contract, $styles);
        }

        $this->assertStringContainsString('body.admin-theme-scope[data-ui-version="institutional-v2"]', $styles);
        $this->assertStringContainsString('.dashboard-command-center', $styles);
        $this->assertStringContainsString('.dashboard-priority-zone', $styles);
        $this->assertStringContainsString('.dashboard-synthesis-hierarchy-card', $styles);
        $this->assertStringContainsString('.dashboard-action-facts', $styles);
        $this->assertStringContainsString('table[data-mobile-cards]', $styles);
    }

    public function test_dashboard_is_decision_first_and_uses_progressive_disclosure(): void
    {
        $commandCenter = (string) file_get_contents(resource_path('views/dashboard/partials/command-center.blade.php'));
        $dashboard = (string) file_get_contents(resource_path('views/partials/dashboard-analytics.blade.php'));
        $hierarchy = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-synthesis-hierarchy.blade.php'));

        $this->assertStringContainsString('data-dashboard-insight-zone', $commandCenter);
        $this->assertStringContainsString('À traiter aujourd’hui', $commandCenter);
        $this->assertStringContainsString('data-flow-state=', $commandCenter);
        $this->assertStringContainsString('dashboard-priority-summary', $commandCenter);
        $this->assertStringContainsString('Du PAS aux actions opérationnelles', $hierarchy);
        $this->assertStringContainsString('Dépliez uniquement le niveau à analyser', $hierarchy);
        $this->assertStringContainsString('dashboard-action-facts', $hierarchy);
        $this->assertStringContainsString('Ouvrir l’action', $hierarchy);
        $this->assertStringContainsString('data-dashboard-filter-toggle', $dashboard);
        $this->assertStringContainsString('data-mobile-collapsed="true"', $dashboard);

        $strategicNode = '/<details class="dashboard-synthesis-node dashboard-synthesis-node-strategic-objective"[^>]*>/';
        $operationalNode = '/<details class="dashboard-synthesis-node dashboard-synthesis-node-operational-objective"[^>]*>/';

        $this->assertMatchesRegularExpression($strategicNode, $hierarchy);
        $this->assertMatchesRegularExpression($operationalNode, $hierarchy);
        $this->assertDoesNotMatchRegularExpression(
            '/dashboard-synthesis-node-strategic-objective"[^>]*\sopen(?:\s|>)/',
            $hierarchy,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/dashboard-synthesis-node-operational-objective"[^>]*\sopen(?:\s|>)/',
            $hierarchy,
        );
    }

    public function test_theme_and_search_controls_expose_accessible_interactions(): void
    {
        $adminShell = (string) file_get_contents(resource_path('js/admin-shell.js'));
        $enhancements = (string) file_get_contents(resource_path('js/ui-enhancements.js'));

        $this->assertStringContainsString('function syncThemeControls(theme)', $adminShell);
        $this->assertStringContainsString("themeToggle.setAttribute('aria-pressed'", $adminShell);
        $this->assertStringContainsString("window.dispatchEvent(new CustomEvent('anbg:theme-changed'", $adminShell);
        $this->assertStringContainsString("document.getElementById('admin-spotlight-open')", $enhancements);
        $this->assertStringContainsString("trigger.addEventListener('click', openSpotlight)", $enhancements);
        $this->assertStringContainsString('function initDashboardFilterDisclosures()', $enhancements);
        $this->assertStringContainsString("form.dataset.mobileCollapsed = expanded ? 'false' : 'true'", $enhancements);
    }
}
