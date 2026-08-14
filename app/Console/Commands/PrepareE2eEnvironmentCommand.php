<?php

namespace App\Console\Commands;

use App\Support\E2eEnvironment;
use Database\Seeders\E2eSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;

#[Signature('e2e:prepare')]
#[Description('Réinitialise exclusivement la base et les données du navigateur E2E dédié')]
class PrepareE2eEnvironmentCommand extends Command
{
    public function handle(): int
    {
        try {
            E2eEnvironment::assertSafe();
        } catch (LogicException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $exitCode = $this->call('migrate:fresh', [
            '--database' => (string) config('database.default'),
            '--seed' => true,
            '--seeder' => E2eSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        if ($exitCode !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->components->info('Base E2E prête. Aucun jeu de données métier réel n’a été chargé.');

        return self::SUCCESS;
    }
}
