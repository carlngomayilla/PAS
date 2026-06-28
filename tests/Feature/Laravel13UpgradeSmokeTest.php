<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Laravel13UpgradeSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_runs_on_laravel_13_with_ai_tables(): void
    {
        $this->assertStringStartsWith('13.', app()->version());
        $this->assertTrue(Schema::hasTable('ai_import_batches'));
        $this->assertTrue(Schema::hasTable('ai_generated_reports'));
    }
}
