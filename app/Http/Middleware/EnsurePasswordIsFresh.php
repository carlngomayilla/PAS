<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsFresh
{
    public function __construct(
        private readonly PasswordPolicyService $passwordPolicy
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $this->passwordPolicy->isExpired($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => $this->passwordPolicy->expirationMessage(),
                'code' => 'password_expired',
            ], 403);
        }

        if ($request->routeIs('workspace.profile.edit', 'workspace.profile.update', 'logout')) {
            return $next($request);
        }

        return redirect()
            ->route('workspace.profile.edit')
            ->withErrors(['password' => $this->passwordPolicy->expirationMessage()]);
    }
}
