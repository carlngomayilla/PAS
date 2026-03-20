<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_command_succeeds_on_local_stack(): void
    {
        $this->artisan('anbg:health-check')
            ->expectsOutputToContain('Health check OK.')
            ->assertSuccessful();
    }

    public function test_health_check_command_can_render_json(): void
    {
        $exitCode = Artisan::call('anbg:health-check', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"ok": true', $output);
        $this->assertStringContainsString('"label": "Base de donnees"', $output);
    }
}
