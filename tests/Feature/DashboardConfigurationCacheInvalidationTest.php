<?php

namespace Tests\Feature;

use App\Services\Analytics\AnalyticsCacheVersionService;
use App\Services\DashboardProfileSettings;
use App\Services\RolePermissionSettings;
use App\Services\RoleRegistryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardConfigurationCacheInvalidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.version_store' => 'array']);
        Cache::store('array')->clear();
    }

    public function test_updating_role_permissions_bumps_the_dashboard_cache_version_once(): void
    {
        $versions = app(AnalyticsCacheVersionService::class);
        $settings = app(RolePermissionSettings::class);
        $before = $versions->dashboardVersion();

        $settings->update($settings->all());

        $this->assertSame($before + 1, $versions->dashboardVersion());
    }

    public function test_persisting_custom_roles_bumps_the_dashboard_cache_version_once(): void
    {
        $versions = app(AnalyticsCacheVersionService::class);
        $before = $versions->dashboardVersion();

        app(RoleRegistryService::class)->updateCustomRoles([]);

        $this->assertSame($before + 1, $versions->dashboardVersion());
    }

    public function test_updating_dashboard_profiles_bumps_the_dashboard_cache_version_once(): void
    {
        $versions = app(AnalyticsCacheVersionService::class);
        $before = $versions->dashboardVersion();

        app(DashboardProfileSettings::class)->update([]);

        $this->assertSame($before + 1, $versions->dashboardVersion());
    }
}
