<?php

namespace Tests\Feature;

use Database\Seeders\TestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Couvre A09 : TestSeeder doit refuser de s executer en production (sinon il
 * cree un compte admin `*.test` avec mot de passe trivial `Pass@12345`).
 */
class TestSeederProductionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_seeder_throws_in_production_environment(): void
    {
        $previousEnv = app()->environment();
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('TestSeeder est interdit en production.');

            (new TestSeeder())->setContainer(app())->run();
        } finally {
            app()->detectEnvironment(fn () => $previousEnv);
        }
    }

    public function test_test_seeder_refuses_unknown_environment_without_override(): void
    {
        $previousEnv = app()->environment();
        app()->detectEnvironment(fn () => 'preprod');

        // Pas d override : l environnement preprod doit etre rejete.
        $previousOverride = env('ANBG_ALLOW_TEST_SEEDER');
        putenv('ANBG_ALLOW_TEST_SEEDER=false');

        try {
            $this->expectException(RuntimeException::class);
            (new TestSeeder())->setContainer(app())->run();
        } finally {
            if ($previousOverride === false) {
                putenv('ANBG_ALLOW_TEST_SEEDER');
            } else {
                putenv('ANBG_ALLOW_TEST_SEEDER='.(string) $previousOverride);
            }
            app()->detectEnvironment(fn () => $previousEnv);
        }
    }
}
