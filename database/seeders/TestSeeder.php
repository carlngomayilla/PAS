<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seeder de developpement/recette UNIQUEMENT.
 *
 * Cree un compte admin technique (email `*.test`, mot de passe trivial) tres
 * pratique en local mais inacceptable en production. Une garde refuse donc
 * d executer ce seeder lorsque l environnement est `production` ou que la base
 * de donnees cible n est pas une instance jetable (cf. A09).
 *
 * Pour bootstrapper la prod, utiliser :
 *   php artisan db:seed --class=ProductionSafeSeeder
 */
class TestSeeder extends Seeder
{
    public function run(): void
    {
        $this->assertEnvironmentAllowsTestData();

        $this->call(DatabaseSeeder::class);

        $now = now();

        DB::table('users')->updateOrInsert(
            ['email' => 'admin.technique@anbg.test'],
            [
                'name' => 'Administrateur technique',
                'password' => Hash::make('Pass@12345'),
                'role' => User::ROLE_ADMIN,
                'is_agent' => false,
                'direction_id' => null,
                'service_id' => null,
                'email_verified_at' => $now,
                'password_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /**
     * Refuse l execution si l environnement applicatif est production, ou si
     * l operateur n a pas explicitement debloque la garde via
     * `ANBG_ALLOW_TEST_SEEDER=true` (utile pour une demo controlee).
     */
    private function assertEnvironmentAllowsTestData(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'TestSeeder est interdit en production. Utilisez ProductionSafeSeeder.'
            );
        }

        $allowOverride = filter_var(env('ANBG_ALLOW_TEST_SEEDER', false), FILTER_VALIDATE_BOOLEAN);
        if (! $allowOverride && ! app()->environment(['local', 'testing', 'development', 'staging'])) {
            throw new RuntimeException(
                'TestSeeder refuse de s executer sur cet environnement ('.app()->environment().'). '
                .'Definissez ANBG_ALLOW_TEST_SEEDER=true si vous savez ce que vous faites.'
            );
        }
    }
}
