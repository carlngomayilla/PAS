<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

class CurrentAuthenticationRevoker
{
    public function revoke(Request $request): void
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof TransientToken) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return;
        }

        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }
    }
}
