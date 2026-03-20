<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function __construct(
        private readonly PasswordPolicyService $passwordPolicy
    ) {
    }

    public function create(): View
    {
        return view('auth.lamp-login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');
        $identifier = trim((string) ($credentials['email'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        $authenticated = Auth::attempt([
            'email' => $identifier,
            'password' => $password,
        ], $remember);

        if (! $authenticated) {
            $normalizedMatricule = strtoupper(str_replace(' ', '', $identifier));
            $legacyEmailAliases = [
                strtolower($normalizedMatricule).'@anbg.ga',
                strtolower($normalizedMatricule).'@anbg.test',
            ];

            $matchedUser = User::query()
                ->whereRaw("UPPER(REPLACE(agent_matricule, ' ', '')) = ?", [$normalizedMatricule])
                ->orWhereIn('email', $legacyEmailAliases)
                ->first();

            if ($matchedUser instanceof User) {
                $authenticated = Auth::attempt([
                    'email' => (string) $matchedUser->email,
                    'password' => $password,
                ], $remember);
            }
        }

        if (! $authenticated) {
            return back()
                ->withErrors([
                    'email' => 'Identifiants invalides. Utilisez email ou matricule + mot de passe.',
                ])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        $defaultRoute = route('dashboard');
        $user = $request->user();
        if ($user instanceof User && $user->hasRole(User::ROLE_ADMIN)) {
            $defaultRoute = route('admin.dashboard');
        }

        if ($user instanceof User && $this->passwordPolicy->isExpired($user)) {
            return redirect()
                ->route('workspace.profile.edit')
                ->withErrors(['password' => $this->passwordPolicy->expirationMessage()]);
        }

        return redirect()->to($defaultRoute);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login.form');
    }
}
