<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSufficientExecutionTime
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredLimit = (int) ini_get('max_execution_time');
        $applicationLimit = (int) config('app.web_execution_time_limit', 120);

        if ($configuredLimit > 0 && $applicationLimit > $configuredLimit) {
            set_time_limit($applicationLimit);
        }

        return $next($request);
    }
}
