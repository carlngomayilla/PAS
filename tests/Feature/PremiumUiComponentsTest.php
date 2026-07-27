<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PremiumUiComponentsTest extends TestCase
{
    public function test_process_bubble_exposes_accessible_live_progress_contract(): void
    {
        $html = Blade::render('<x-ui.process-bubble />');

        $this->assertStringContainsString('data-process-bubble', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('data-process-progress-bar', $html);
        $this->assertStringContainsString('data-process-steps', $html);
        $this->assertStringContainsString('Réduire le suivi', $html);
    }

    public function test_premium_assets_are_loaded_by_the_application_entrypoint(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($javascript);
        $this->assertIsString($styles);
        $this->assertStringContainsString("import './premium-process-bubble';", $javascript);
        $this->assertStringContainsString('.premium-loading-orb', $styles);
        $this->assertStringContainsString('prefers-reduced-motion', $styles);
    }

    public function test_shared_data_table_is_responsive_without_forced_desktop_width(): void
    {
        $html = Blade::render('<x-ui.data-table><tbody><tr><td>Valeur</td></tr></tbody></x-ui.data-table>');

        $this->assertStringContainsString('min-w-full', $html);
        $this->assertStringNotContainsString('min-w-[1200px]', $html);
    }
}
