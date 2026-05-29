<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class FunctionalAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) env('ADMIN_FONCTIONNEL_EMAIL', 'admin.fonctionnel@anbg.ga')));

        if ($email === '') {
            return;
        }

        $now = now();
        $existing = DB::table('users')
            ->where('email', $email)
            ->first(['id', 'password', 'email_verified_at', 'password_changed_at']);

        $temporaryPassword = null;
        $passwordChangedAt = $existing?->password_changed_at;

        if ($existing === null) {
            if (app()->environment('testing')) {
                $temporaryPassword = 'Pass@12345';
                $passwordChangedAt = $now;
            } else {
                $temporaryPassword = trim((string) env('ADMIN_FONCTIONNEL_INITIAL_PASSWORD', ''));
                if ($temporaryPassword === '') {
                    $temporaryPassword = app(PasswordPolicyService::class)->generateInitialPassword();
                }

                $passwordChangedAt = null;
            }
        }

        $payload = [
            'name' => trim((string) env('ADMIN_FONCTIONNEL_NAME', 'Administrateur fonctionnel')),
            'email_verified_at' => $existing?->email_verified_at ?? $now,
            'role' => User::ROLE_ADMIN_FONCTIONNEL,
            'is_agent' => false,
            'direction_id' => null,
            'service_id' => null,
            'password_changed_at' => $passwordChangedAt,
            'updated_at' => $now,
        ];

        if ($existing === null) {
            $payload['password'] = Hash::make((string) $temporaryPassword);
            $payload['created_at'] = $now;
        }

        if (Schema::hasColumn('users', 'custom_role_code')) {
            $payload['custom_role_code'] = null;
        }

        if (Schema::hasColumn('users', 'is_active')) {
            $payload['is_active'] = true;
        }

        if (Schema::hasColumn('users', 'unite_dg_id')) {
            $payload['unite_dg_id'] = null;
        }

        if (Schema::hasColumn('users', 'deleted_at')) {
            $payload['deleted_at'] = null;
        }

        DB::table('users')->updateOrInsert(['email' => $email], $payload);

        if ($temporaryPassword !== null && ! app()->environment('testing')) {
            $this->command?->newLine();
            $this->command?->warn('Compte administrateur fonctionnel cree avec un mot de passe temporaire :');
            $this->command?->table(
                ['Email', 'Nom', 'Mot de passe temporaire'],
                [[$email, (string) $payload['name'], $temporaryPassword]]
            );
        }
    }
}
