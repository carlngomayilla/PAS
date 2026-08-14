<?php

namespace Tests\Feature;

use App\Support\E2eEnvironment;
use Database\Seeders\E2eSeeder;
use LogicException;
use Tests\TestCase;

class E2eEnvironmentGuardTest extends TestCase
{
    public function test_prepare_command_refuses_the_phpunit_environment(): void
    {
        $this->artisan('e2e:prepare')->expectsOutputToContain('APP_ENV=e2e')->assertFailed();
    }

    public function test_e2e_seeder_refuses_the_phpunit_environment(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('APP_ENV=e2e');
        $this->seed(E2eSeeder::class);
    }

    public function test_guard_refuses_a_non_dedicated_database(): void
    {
        $this->app->detectEnvironment(fn (): string => 'e2e');
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'pas_anbg', 'e2e.database' => 'pas_anbg_e2e']);
        $this->expectException(LogicException::class);
        E2eEnvironment::assertSafe();
    }

    public function test_guard_refuses_shared_file_storage(): void
    {
        $this->app->detectEnvironment(fn (): string => 'e2e');
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'pas_anbg_e2e',
            'e2e.database' => 'pas_anbg_e2e',
            'filesystems.disks.local.root' => storage_path('app/private'),
            'e2e.storage_root' => storage_path('app/e2e-private'),
        ]);
        $this->expectException(LogicException::class);
        E2eEnvironment::assertSafe();
    }

    public function test_guard_accepts_the_exact_e2e_database_and_storage(): void
    {
        $this->app->detectEnvironment(fn (): string => 'e2e');
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'pas_anbg_e2e',
            'e2e.database' => 'pas_anbg_e2e',
            'filesystems.disks.local.root' => storage_path('app/e2e-private'),
            'e2e.storage_root' => storage_path('app/e2e-private'),
        ]);
        E2eEnvironment::assertSafe();
        $this->addToAssertionCount(1);
    }
}
