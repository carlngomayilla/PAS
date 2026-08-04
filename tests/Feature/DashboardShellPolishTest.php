<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardShellPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_suivi_evaluation_dashboard_renders_the_six_expected_control_cards(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'k.angue.anbg@gmail.com')->firstOrFail();

        $content = $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        preg_match_all(
            '/<a[^>]+data-dashboard-primary-kpi[^>]*>.*?<\/a>/s',
            $content,
            $primaryCards,
        );

        $this->assertCount(6, $primaryCards[0]);

        $renderedCards = implode("\n", $primaryCards[0]);
        $this->assertStringContainsString('Avancement global', $primaryCards[0][0]);
        $this->assertStringContainsString('Actions en retard', $renderedCards);
        $this->assertStringContainsString('Actions clôturées', $renderedCards);
        $this->assertStringContainsString('En attente validation contrôleur', $renderedCards);
        $this->assertStringNotContainsString('Alertes critiques', $renderedCards);
    }

    public function test_shell_assets_keep_navbar_sidebar_and_header_controls_aligned(): void
    {
        $styles = file_get_contents(base_path('resources/css/anbg-glass.css'));
        $script = file_get_contents(base_path('resources/js/admin-shell.js'));
        $sidebar = file_get_contents(base_path('resources/views/components/admin/sidebar.blade.php'));

        $this->assertIsString($styles);
        $this->assertIsString($script);
        $this->assertIsString($sidebar);
        $this->assertStringContainsString('.sidebar-desktop-expanded', $styles);
        $this->assertStringContainsString('.app-sidebar-logo-flame', $styles);
        $this->assertStringContainsString('transform: translate(42%, -42%)', $styles);
        $this->assertStringContainsString('syncDesktopSidebarLayout', $script);
        $this->assertStringContainsString("sidebar.addEventListener('mouseenter'", $script);
        $this->assertStringContainsString("'Menu' => 10", $sidebar);
        $this->assertStringContainsString("'Plateforme' => 60", $sidebar);
    }

    public function test_import_and_reporting_navigation_are_unified_with_internal_tabs(): void
    {
        $sidebar = (string) file_get_contents(resource_path('views/components/admin/sidebar.blade.php'));
        $layout = (string) file_get_contents(resource_path('views/layouts/admin.blade.php'));
        $tabs = (string) file_get_contents(resource_path('views/components/ui/module-tabs.blade.php'));
        $styles = (string) file_get_contents(resource_path('css/anbg-glass.css'));

        $this->assertStringContainsString("\$visibleImportModuleCodes = collect(['imports_excel', 'ai_imports'])", $sidebar);
        $this->assertStringContainsString("\$visibleReportingModuleCodes = collect(['reporting', 'ai_reports'])", $sidebar);
        $this->assertStringContainsString("'code' => 'imports'", $sidebar);
        $this->assertStringContainsString("'label' => 'Imports'", $sidebar);
        $this->assertStringContainsString("'label' => 'Reporting'", $sidebar);
        $this->assertStringNotContainsString("'label' => \$moduleLabel('ai_imports', 'IA & Imports')", $sidebar);
        $this->assertStringNotContainsString("'label' => \$moduleLabel('ai_reports', 'Rapports IA')", $sidebar);

        $this->assertStringContainsString('Import Excel', $layout);
        $this->assertStringContainsString('Import assisté par IA', $layout);
        $this->assertStringContainsString('Reporting institutionnel', $layout);
        $this->assertStringContainsString('Reporting assisté par IA', $layout);
        $this->assertStringContainsString('<x-ui.module-tabs', $layout);
        $this->assertStringContainsString('role="tablist"', $tabs);
        $this->assertStringContainsString('aria-selected=', $tabs);

        // Le logo de la barre laterale repliee est rendu par une balise <img>
        // (`.app-sidebar-logo-flame`) et non plus par un `background-image` CSS,
        // pour eviter le logo affiche en double.
        $this->assertStringContainsString('app-sidebar-logo-image app-sidebar-logo-flame', $sidebar);
        $this->assertStringNotContainsString("url('/images/logo-anbg-flamme.png')", $styles);
        $this->assertStringContainsString('.module-family-tab.is-active::after', $styles);
        $this->assertStringContainsString('All application tabs inherit the Import module navigation treatment.', $styles);
        $this->assertStringContainsString('Unified statistical cards: same visual language as the control dashboard.', $styles);
        $this->assertStringContainsString('The motif is drawn in CSS and contains no third-party logo or branding.', $styles);
        $this->assertStringContainsString('.data-table-shell,', $styles);
        $this->assertStringContainsString('background-image: none !important;', $styles);
    }
}
