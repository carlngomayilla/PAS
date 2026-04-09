<?php

namespace App\Http\Controllers;

use App\Models\JournalAudit;
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

        $user = $request->user();
        if ($user instanceof User && ! (bool) $user->is_active) {
            Auth::guard('web')->logout();

            return back()
                ->withErrors([
                    'email' => 'Compte desactive.',
                ])
                ->onlyInput('email');
        }

        if ($user instanceof User && $user->isSuspended()) {
            Auth::guard('web')->logout();

            return back()
                ->withErrors([
                    'email' => 'Compte temporairement suspendu.',
                ])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        $defaultRoute = route('dashboard');
        $user = $request->user();
        if ($user instanceof User) {
            $this->recordAuthenticationAudit($request, $user, 'login_success', [
                'remember' => $remember,
                'login_identifier' => $identifier,
            ]);
        }
        if ($user instanceof User && $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN)) {
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
        $user = $request->user();
        if ($user instanceof User) {
            $this->recordAuthenticationAudit($request, $user, 'logout', [
                'session_id' => $request->session()->getId(),
            ]);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login.form');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordAuthenticationAudit(Request $request, User $user, string $action, array $payload = []): void
    {
        JournalAudit::query()->create([
            'user_id' => $user->id,
            'module' => 'auth',
            'entite_type' => User::class,
            'entite_id' => (int) $user->id,
            'action' => $action,
            'ancienne_valeur' => null,
            'nouvelle_valeur' => $payload,
            'adresse_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
