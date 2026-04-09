<?php

namespace App\Providers;

use App\Models\Action;
use App\Models\Pao;
use App\Models\PaoAxe;
use App\Models\PaoObjectifOperationnel;
use App\Models\PaoObjectifStrategique;
use App\Models\Pas;
use App\Services\AppearanceSettings;
use App\Services\ActionCalculationSettings;
use App\Services\ActionManagementSettings;
use App\Services\DashboardProfileSettings;
use App\Services\DocumentPolicySettings;
use App\Services\DynamicReferentialSettings;
use App\Services\ManagedKpiSettings;
use App\Services\NotificationPolicySettings;
use App\Services\OrganizationGovernanceService;
use App\Services\PlatformDiagnosticService;
use App\Services\PlatformSimulationService;
use App\Services\PlatformSnapshotService;
use App\Services\PlatformSettings;
use App\Services\PlatformMaintenanceService;
use App\Services\RoleRegistryService;
use App\Services\RolePermissionSettings;
use App\Services\WorkflowSettings;
use App\Services\WorkspaceModuleSettings;
use App\Policies\ActionPolicy;
use App\Policies\PaoPolicy;
use App\Policies\PaoAxePolicy;
use App\Policies\PaoObjectifOperationnelPolicy;
use App\Policies\PaoObjectifStrategiquePolicy;
use App\Policies\PasPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AppearanceSettings::class);
        $this->app->singleton(ActionCalculationSettings::class);
        $this->app->singleton(ActionManagementSettings::class);
        $this->app->singleton(DashboardProfileSettings::class);
        $this->app->singleton(DocumentPolicySettings::class);
        $this->app->singleton(DynamicReferentialSettings::class);
        $this->app->singleton(ManagedKpiSettings::class);
        $this->app->singleton(NotificationPolicySettings::class);
        $this->app->singleton(OrganizationGovernanceService::class);
        $this->app->singleton(PlatformDiagnosticService::class);
        $this->app->singleton(PlatformSimulationService::class);
        $this->app->singleton(PlatformSnapshotService::class);
        $this->app->singleton(PlatformSettings::class);
        $this->app->singleton(PlatformMaintenanceService::class);
        $this->app->singleton(RoleRegistryService::class);
        $this->app->singleton(RolePermissionSettings::class);
        $this->app->singleton(WorkflowSettings::class);
        $this->app->singleton(WorkspaceModuleSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $platformSettings = $this->app->make(PlatformSettings::class);
        app()->setLocale($platformSettings->locale());
        config(['app.timezone' => $platformSettings->timezone()]);
        date_default_timezone_set($platformSettings->timezone());
        Carbon::setLocale($platformSettings->locale());

        Gate::policy(Action::class, ActionPolicy::class);
        Gate::policy(Pas::class, PasPolicy::class);
        Gate::policy(Pao::class, PaoPolicy::class);
        Gate::policy(PaoAxe::class, PaoAxePolicy::class);
        Gate::policy(PaoObjectifStrategique::class, PaoObjectifStrategiquePolicy::class);
        Gate::policy(PaoObjectifOperationnel::class, PaoObjectifOperationnelPolicy::class);

        RateLimiter::for('login', function (Request $request): array {
            $identifier = Str::lower(trim((string) $request->input('email', 'guest')));

            return [
                Limit::perMinutes(10, 5)->by('login:'.$identifier.'|'.$request->ip()),
                Limit::perMinutes(10, 25)->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('api-login', function (Request $request): array {
            $identifier = Str::lower(trim((string) $request->input('email', 'guest')));

            return [
                Limit::perMinutes(10, 5)->by('api-login:'.$identifier.'|'.$request->ip()),
                Limit::perMinutes(10, 25)->by('api-login-ip:'.$request->ip()),
            ];
        });

        View::share('appearanceSettings', $this->app->make(AppearanceSettings::class));
        View::share('platformSettings', $platformSettings);
    }
}
