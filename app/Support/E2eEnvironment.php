<?php

namespace App\Support;

use Illuminate\Support\Str;
use LogicException;

final class E2eEnvironment
{
    public static function assertSafe(): void
    {
        if (! app()->environment('e2e')) {
            throw new LogicException('La préparation E2E exige APP_ENV=e2e.');
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $expectedDatabase = (string) config('e2e.database');

        if ($connection !== 'pgsql'
            || $database === ''
            || $database !== $expectedDatabase
            || ! Str::endsWith(Str::lower($database), '_e2e')) {
            throw new LogicException('La préparation E2E exige une base PostgreSQL dédiée dont le nom se termine par _e2e.');
        }

        $storageRoot = self::normalizePath((string) config('filesystems.disks.local.root'));
        $expectedStorageRoot = self::normalizePath((string) config('e2e.storage_root'));

        if ($storageRoot === '' || $storageRoot !== $expectedStorageRoot || ! Str::contains($storageRoot, 'e2e')) {
            throw new LogicException('La préparation E2E exige un stockage local dédié contenant e2e dans son chemin.');
        }
    }

    private static function normalizePath(string $path): string
    {
        return Str::of($path)->replace('\\', '/')->rtrim('/')->lower()->toString();
    }
}
