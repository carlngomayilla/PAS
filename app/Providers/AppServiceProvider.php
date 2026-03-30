<?php

namespace App\Providers;

use App\Models\Action;
use App\Models\Pao;
use App\Models\PaoAxe;
use App\Models\PaoObjectifOperationnel;
use App\Models\PaoObjectifStrategique;
use App\Models\Pas;
use App\Policies\ActionPolicy;
use App\Policies\PaoPolicy;
use App\Policies\PaoAxePolicy;
use App\Policies\PaoObjectifOperationnelPolicy;
use App\Policies\PaoObjectifStrategiquePolicy;
use App\Policies\PasPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Action::class, ActionPolicy::class);
        Gate::policy(Pas::class, PasPolicy::class);
        Gate::policy(Pao::class, PaoPolicy::class);
        Gate::policy(PaoAxe::class, PaoAxePolicy::class);
        Gate::policy(PaoObjectifStrategique::class, PaoObjectifStrategiquePolicy::class);
        Gate::policy(PaoObjectifOperationnel::class, PaoObjectifOperationnelPolicy::class);

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinutes(10, 5)->by((string) $request->ip());
        });

        RateLimiter::for('api-login', function (Request $request): Limit {
            return Limit::perMinutes(10, 5)->by((string) $request->ip());
        });
    }
}
