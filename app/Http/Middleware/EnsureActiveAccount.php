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
        if ($user === null) {
            return $next($request);
        }

        $message = null;
        if (! (bool) ($user->is_active ?? true)) {
            $message = 'Compte desactive.';
        } elseif (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            $message = 'Compte temporairement suspendu.';
        }

        if ($message === null) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            $request->user()?->currentAccessToken()?->delete();

            return response()->json([
                'message' => $message,
            ], 403);
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()
            ->route('login.form')
            ->withErrors(['email' => $message]);
    }
}
