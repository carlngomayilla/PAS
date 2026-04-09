<?php

namespace App\Services\Security;

use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordPolicyService
{
    public function rule(bool $required = true): Password
    {
        $rule = $required
            ? Password::min((int) config('security.passwords.min_length', 12))
            : Password::min((int) config('security.passwords.min_length', 12));

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

    public function persistPassword(User $user, string $plainPassword): void
    {
        $hashedPassword = Hash::make($plainPassword);

        $user->forceFill([
            'password' => $hashedPassword,
            'password_changed_at' => now(),
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

    public function isExpired(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $expireDays = (int) config('security.passwords.expire_days', 90);
        if ($expireDays <= 0) {
            return false;
        }

        $changedAt = $user->password_changed_at ?? $user->created_at ?? now();

        return $changedAt === null || $changedAt->copy()->addDays($expireDays)->isPast();
    }

    public function assertNotExpired(User $user): void
    {
        if ($this->isExpired($user)) {
            throw new AuthenticationException($this->expirationMessage());
        }
    }

    public function expirationMessage(): string
    {
        return sprintf(
            'Votre mot de passe a expire. Veuillez le renouveler tous les %d jours.',
            (int) config('security.passwords.expire_days', 90)
        );
    }

    public function helpText(): string
    {
        return sprintf(
            'Mot de passe: %d caracteres minimum, majuscules/minuscules, chiffres, symboles, expiration tous les %d jours, historique des %d derniers mots de passe.',
            (int) config('security.passwords.min_length', 12),
            (int) config('security.passwords.expire_days', 90),
            (int) config('security.passwords.history_size', 5)
        );
    }
}
