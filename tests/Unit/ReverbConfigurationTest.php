<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Laravel\Reverb\ReverbServiceProvider;
use Tests\TestCase;

class ReverbConfigurationTest extends TestCase
{
    public function test_reverb_package_and_configuration_are_available(): void
    {
        $this->assertTrue(class_exists(ReverbServiceProvider::class));
        $this->assertArrayHasKey('reverb:start', Artisan::all());
        $this->assertSame('reverb', config('reverb.default'));
        $this->assertSame('config', config('reverb.apps.provider'));
        $this->assertIsArray(config('reverb.apps.apps.0.options'));
    }
}
