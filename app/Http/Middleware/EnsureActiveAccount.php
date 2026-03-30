<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse|JsonResponse
    {
        $user = $request->user();
        if ($user === null || (bool) ($user->is_active ?? true)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            $request->user()?->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Compte desactive.',
            ], 403);
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()
            ->route('login.form')
            ->withErrors(['email' => 'Compte desactive.']);
    }
}
