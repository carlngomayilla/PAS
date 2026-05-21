<?php

namespace App\Http\Controllers;

use App\Models\JournalAudit;
use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use PDOException;
use Throwable;

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

        if (! $this->databaseCanBeReachedForLogin()) {
            return $this->databaseUnavailableResponse($request);
        }

        try {
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
        } catch (QueryException|PDOException $exception) {
            Log::warning('Login database unavailable.', [
                'connection' => config('database.default'),
                'host' => config('database.connections.'.config('database.default').'.host'),
                'error' => $exception->getMessage(),
            ]);

            return $this->databaseUnavailableResponse($request);
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
        try {
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
        } catch (Throwable $exception) {
            // A27 — Un evenement d authentification non audite est une perte
            // SECURITAIRE (impossible de tracer les login/logout en cas
            // d incident). On logge en `critical` pour declencher l alerte
            // operationnelle, mais on ne casse pas le flow de login/logout.
            Log::critical('Authentication audit could not be recorded (A27).', [
                'user_id' => $user->id,
                'action' => $action,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }

    private function databaseCanBeReachedForLogin(): bool
    {
        $connectionName = (string) config('database.default');
        $connection = (array) config('database.connections.'.$connectionName, []);

        if (($connection['driver'] ?? null) !== 'pgsql') {
            return true;
        }

        if (! (bool) ($connection['login_preflight'] ?? true)) {
            return true;
        }

        $host = trim((string) ($connection['host'] ?? ''));
        $port = (int) ($connection['port'] ?? 5432);

        if ($host === '' || str_starts_with($host, '/')) {
            return true;
        }

        $timeout = max(0.2, min((float) ($connection['login_preflight_timeout'] ?? 1.0), 3.0));
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (is_resource($socket)) {
            fclose($socket);

            return true;
        }

        Log::warning('Login database preflight failed.', [
            'connection' => $connectionName,
            'host' => $host,
            'port' => $port,
            'error_number' => $errno,
            'error' => $errstr,
        ]);

        return false;
    }

    private function databaseUnavailableResponse(Request $request): RedirectResponse
    {
        return back()
            ->withErrors([
                'email' => 'Base de donnees indisponible. Verifiez que PostgreSQL sur la VM est demarre et accessible, puis reessayez.',
            ])
            ->onlyInput('email');
    }
}
