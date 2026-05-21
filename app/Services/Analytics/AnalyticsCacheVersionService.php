<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;

class AnalyticsCacheVersionService
{
    private const REPORTING_VERSION_KEY = 'analytics-cache:reporting-version';
    private const DASHBOARD_VERSION_KEY = 'analytics-cache:dashboard-version';

    // A39 — Versionnement dedie pour le centre d alertes : permet d invalider
    // le cache TTL=60s d AlertCenterService des qu un evenement metier change
    // l etat des alertes (statut action, kpi_mesure, action_log, justificatif).
    private const ALERTS_VERSION_KEY = 'analytics-cache:alerts-version';

    public function reportingVersion(): int
    {
        return (int) Cache::get(self::REPORTING_VERSION_KEY, 1);
    }

    public function dashboardVersion(): int
    {
        return (int) Cache::get(self::DASHBOARD_VERSION_KEY, 1);
    }

    public function alertsVersion(): int
    {
        return (int) Cache::get(self::ALERTS_VERSION_KEY, 1);
    }

    public function bumpReporting(): void
    {
        $this->bump(self::REPORTING_VERSION_KEY);
    }

    public function bumpDashboard(): void
    {
        $this->bump(self::DASHBOARD_VERSION_KEY);
    }

    public function bumpAlerts(): void
    {
        $this->bump(self::ALERTS_VERSION_KEY);
    }

    public function bumpAll(): void
    {
        $this->bumpReporting();
        $this->bumpDashboard();
        $this->bumpAlerts();
    }

    private function bump(string $key): void
    {
        if (! Cache::has($key)) {
            Cache::forever($key, 1);
        }

        Cache::increment($key);
    }
}