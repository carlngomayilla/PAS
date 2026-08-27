<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response;

class ConfigureHorizonCspNonce
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Horizon::cspNonce(Vite::cspNonce());

        return $next($request);
    }
}
