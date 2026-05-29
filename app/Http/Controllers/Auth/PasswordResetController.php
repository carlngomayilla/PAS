<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Flow public de réinitialisation de mot de passe :
 *   GET  /password/forgot            → formulaire de demande
 *   POST /password/forgot            → envoi du lien par e-mail
 *   GET  /password/reset/{token}     → formulaire de saisie du nouveau mot de passe
 *   POST /password/reset             → application du reset
 *
 * Respecte la politique mots de passe (longueur, mixed case, symboles, HIBP)
 * et l'historique (5 derniers) via PasswordPolicyService.
 */
class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordPolicyService $passwordPolicy
    ) {
    }

    public function showRequestForm(): View
    {
        return view('auth.passwords.forgot');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $status = Password::sendResetLink($request->only('email'));
        } catch (Throwable $exception) {
            Log::warning('Password reset link could not be sent.', [
                'email' => (string) $request->input('email'),
                'exception' => $exception->getMessage(),
            ]);

            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => "Le service d'envoi d'e-mail est indisponible. Verifiez la configuration SMTP/Brevo puis reessayez.",
                ]);
        }

        return back()->with('status', $status === Password::RESET_LINK_SENT
            ? "Si un compte existe pour cet e-mail, un lien de réinitialisation vient d'être envoyé."
            : "Si un compte existe pour cet e-mail, un lien de réinitialisation vient d'être envoyé.");
        // Note : on retourne le même message dans les deux cas pour ne pas
        // divulguer l'existence ou non d'un compte (énumération d'utilisateurs).
    }

    public function showResetForm(Request $request, string $token): View
    {
        return view('auth.passwords.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', $this->passwordPolicy->rule(), 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                // Vérifie l'historique de mots de passe (5 derniers).
                $this->passwordPolicy->validateNotReused($user, $password);
                $this->passwordPolicy->persistPassword($user, $password);
                $user->forceFill([
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login.form')
                ->with('status', 'Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => match ($status) {
                Password::INVALID_TOKEN => 'Le lien de réinitialisation est invalide ou expiré.',
                Password::INVALID_USER => 'Aucun compte n’est associé à cet e-mail.',
                default => 'Impossible de réinitialiser le mot de passe. Réessayez plus tard.',
            }]);
    }
}
