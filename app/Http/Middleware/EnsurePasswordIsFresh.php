<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsFresh
{
    public function __construct(
        private readonly PasswordPolicyService $passwordPolicy
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $this->passwordPolicy->requiresPasswordChange($user)) {
            return $next($request);
        }

        if ($request->routeIs('workspace.profile.edit', 'workspace.profile.update', 'logout', 'v1.api.logout')) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $this->passwordPolicy->passwordChangeRequiredMessage(),
                'code' => 'password_change_required',
            ], 403);
        }

        return redirect()
            ->route('workspace.profile.edit')
            ->withErrors(['password' => $this->passwordPolicy->passwordChangeRequiredMessage()]);
    }
}
