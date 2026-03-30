<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly PasswordPolicyService $passwordPolicy
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::query()
            ->with(['direction:id,code,libelle', 'service:id,direction_id,code,libelle'])
            ->where('email', $validated['email'])
            ->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Identifiants invalides.',
            ], 401);
        }

        if (! (bool) $user->is_active) {
            return response()->json([
                'message' => 'Compte desactive.',
            ], 403);
        }

        if ($this->passwordPolicy->isExpired($user)) {
            return response()->json([
                'message' => $this->passwordPolicy->expirationMessage(),
                'code' => 'password_expired',
            ], 403);
        }

        $expiresAt = config('sanctum.expiration') !== null
            ? now()->addMinutes((int) config('sanctum.expiration'))
            : null;

        $token = $user->createToken($validated['device_name'], ['*'], $expiresAt)->plainTextToken;
        $profil = $user->profileInteractions();

        return response()->json([
            'message' => 'Connexion reussie.',
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => $user,
            'profil' => $profil,
            'interactions' => $profil['items'],
            'modules' => $user->workspaceModules(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $profil = $user->profileInteractions();

        return response()->json([
            'user' => $user->loadMissing([
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
            ]),
            'profil' => $profil,
            'interactions' => $profil['items'],
            'modules' => $user->workspaceModules(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token !== null) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Deconnexion reussie.',
        ]);
    }
}
