<?php

namespace App\Services\Security;

use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordPolicyService
{
    public function rule(bool $required = true): Password
    {
        $rule = Password::min($this->minimumLength());

        if (config('security.passwords.require_letters', true)) {
            $rule = $rule->letters();
        }

        if (config('security.passwords.require_mixed_case', true)) {
            $rule = $rule->mixedCase();
        }

        if (config('security.passwords.require_numbers', true)) {
            $rule = $rule->numbers();
        }

        if (config('security.passwords.require_symbols', true)) {
            $rule = $rule->symbols();
        }

        if (
            config('security.passwords.check_pwned', true)
            && ! app()->runningUnitTests()
            && ! app()->environment(['local', 'testing'])
        ) {
            $rule = $rule->uncompromised();
        }

        return $rule;
    }

    public function validateNotReused(User $user, string $plainPassword, string $field = 'password'): void
    {
        if (Hash::check($plainPassword, (string) $user->password)) {
            throw ValidationException::withMessages([
                $field => 'Le nouveau mot de passe doit etre different du mot de passe actuel.',
            ]);
        }

        $historySize = max(1, (int) config('security.passwords.history_size', 5));

        $recentHashes = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit($historySize)
            ->pluck('password_hash');

        foreach ($recentHashes as $hash) {
            if (Hash::check($plainPassword, (string) $hash)) {
                throw ValidationException::withMessages([
                    $field => sprintf(
                        'Le mot de passe ne doit pas reutiliser l un des %d derniers mots de passe.',
                        $historySize
                    ),
                ]);
            }
        }
    }

    public function persistPassword(User $user, string $plainPassword, bool $requiresChangeOnNextLogin = false): void
    {
        $hashedPassword = Hash::make($plainPassword);

        $user->forceFill([
            'password' => $hashedPassword,
            'password_changed_at' => $requiresChangeOnNextLogin ? null : now(),
        ])->save();

        PasswordHistory::query()->create([
            'user_id' => $user->id,
            'password_hash' => $hashedPassword,
        ]);

        $historySize = max(1, (int) config('security.passwords.history_size', 5));
        $historyIdsToKeep = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit($historySize)
            ->pluck('id');

        PasswordHistory::query()
            ->where('user_id', $user->id)
            ->whereNotIn('id', $historyIdsToKeep)
            ->delete();
    }

    public function requiresPasswordChange(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        // Un mot de passe temporaire est marque par NULL jusqu a son premier
        // changement. Les mots de passe personnels ne sont pas soumis a une
        // expiration periodique.
        return $user->password_changed_at === null;
    }

    /**
     * Genere un mot de passe aleatoire conforme a la policy active. Utilise par
     * les seeders et imports admin pour eviter tout mdp partage `Pass@12345`
     * (cf. A08).
     */
    public function generateInitialPassword(): string
    {
        $minLength = $this->minimumLength();

        return Str::password(
            length: $minLength + 4,
            letters: (bool) config('security.passwords.require_letters', true),
            numbers: (bool) config('security.passwords.require_numbers', true),
            symbols: (bool) config('security.passwords.require_symbols', false),
            spaces: false,
        );
    }

    public function passwordChangeRequiredMessage(): string
    {
        return 'Votre mot de passe provisoire doit etre remplacé avant d accéder aux autres modules.';
    }

    public function helpText(): string
    {
        $requirements = [];

        if (config('security.passwords.require_letters', true)) {
            $requirements[] = config('security.passwords.require_mixed_case', false)
                ? 'majuscules/minuscules'
                : 'lettres';
        }

        if (config('security.passwords.require_numbers', true)) {
            $requirements[] = 'chiffres';
        }

        if (config('security.passwords.require_symbols', false)) {
            $requirements[] = 'symboles';
        }

        $requirementsText = $requirements !== []
            ? implode(', ', $requirements)
            : 'aucune complexite obligatoire';

        return sprintf(
            'Mot de passe: %d caracteres minimum, %s, historique des %d derniers mots de passe. Aucun renouvellement periodique n est impose.',
            $this->minimumLength(),
            $requirementsText,
            (int) config('security.passwords.history_size', 5)
        );
    }

    private function minimumLength(): int
    {
        return max(8, (int) config('security.passwords.min_length', 8));
    }
}
