<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardSynthesisDropdownTest extends TestCase
{
    public function test_dashboard_synthesis_dropdown_has_dedicated_javascript_binding(): void
    {
        $view = (string) file_get_contents(resource_path('views/partials/dashboard-analytics.blade.php'));
        $script = (string) file_get_contents(resource_path('js/dashboard-render.js'));

        $this->assertStringContainsString('data-dashboard-synthesis-selector', $view);
        $this->assertStringContainsString('function bindSynthesisSelectors()', $script);
        $this->assertStringContainsString("summary.addEventListener('click'", $script);
        $this->assertStringContainsString("event.preventDefault();", $script);
        $this->assertStringContainsString("details.open = shouldOpen;", $script);
    }
}
