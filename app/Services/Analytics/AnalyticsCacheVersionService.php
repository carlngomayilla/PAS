<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;

class AnalyticsCacheVersionService
{
    private const REPORTING_VERSION_KEY = 'analytics-cache:reporting-version';
    private const DASHBOARD_VERSION_KEY = 'analytics-cache:dashboard-version';

    public function reportingVersion(): int
    {
        return (int) Cache::get(self::REPORTING_VERSION_KEY, 1);
    }

    public function dashboardVersion(): int
    {
        return (int) Cache::get(self::DASHBOARD_VERSION_KEY, 1);
    }

    public function bumpReporting(): void
    {
        $this->bump(self::REPORTING_VERSION_KEY);
    }

    public function bumpDashboard(): void
    {
        $this->bump(self::DASHBOARD_VERSION_KEY);
    }

    public function bumpAll(): void
    {
        $this->bumpReporting();
        $this->bumpDashboard();
    }

    private function bump(string $key): void
    {
        if (! Cache::has($key)) {
            Cache::forever($key, 1);
        }

        Cache::increment($key);
    }
}