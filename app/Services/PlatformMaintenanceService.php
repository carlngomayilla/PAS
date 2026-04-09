<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;

class PlatformMaintenanceService
{
    private const MAINTENANCE_SECRET = 'super-admin-bypass';

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $application = app();

        return [
            'maintenance_active' => method_exists($application, 'isDownForMaintenance')
                ? $application->isDownForMaintenance()
                : false,
            'config_cached' => method_exists($application, 'configurationIsCached')
                ? $application->configurationIsCached()
                : false,
            'routes_cached' => method_exists($application, 'routesAreCached')
                ? $application->routesAreCached()
                : false,
            'events_cached' => method_exists($application, 'eventsAreCached')
                ? $application->eventsAreCached()
                : false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function perform(string $action): array
    {
        $allowed = $this->actions();

        if (! array_key_exists($action, $allowed)) {
            abort(422, 'Action de maintenance non prise en charge.');
        }

        $exitCode = match ($action) {
            'clear_cache' => Artisan::call('optimize:clear'),
            'clear_views' => Artisan::call('view:clear'),
            'cache_views' => Artisan::call('view:cache'),
            'maintenance_on' => Artisan::call('down', ['--refresh' => 60, '--retry' => 60, '--secret' => self::MAINTENANCE_SECRET]),
            'maintenance_off' => Artisan::call('up'),
        };

        return [
            'action' => $action,
            'label' => $allowed[$action],
            'exit_code' => $exitCode,
            'output' => trim(Artisan::output()),
            'status' => $this->status(),
            'bypass_url' => $action === 'maintenance_on' ? url('/'.self::MAINTENANCE_SECRET) : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function actions(): array
    {
        return [
            'clear_cache' => 'Vider le cache applicatif',
            'clear_views' => 'Vider le cache des vues',
            'cache_views' => 'Regenerer le cache des vues',
            'maintenance_on' => 'Activer le mode maintenance',
            'maintenance_off' => 'Desactiver le mode maintenance',
        ];
    }
}
