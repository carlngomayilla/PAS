<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class HealthCheckCommand extends Command
{
    protected $signature = 'anbg:health-check {--json : Affiche le resultat en JSON}';

    protected $description = 'Verifie les dependances techniques minimales de l application.';

    public function handle(): int
    {
        $checks = [
            $this->checkDatabase(),
            $this->checkStoragePath(storage_path('app'), 'storage/app'),
            $this->checkStoragePath(storage_path('logs'), 'storage/logs'),
            $this->checkStoragePath(base_path('bootstrap/cache'), 'bootstrap/cache'),
            $this->checkOpenApiSpec(),
            $this->checkQueueBackend(),
        ];

        $hasFailure = collect($checks)->contains(static fn (array $check): bool => $check['status'] === 'fail');

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => ! $hasFailure,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $hasFailure ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['Check', 'Status', 'Details'],
            collect($checks)->map(static fn (array $check): array => [
                $check['label'],
                strtoupper($check['status']),
                $check['details'],
            ])->all()
        );

        if ($hasFailure) {
            $this->error('Health check echoue.');

            return self::FAILURE;
        }

        $this->info('Health check OK.');

        return self::SUCCESS;
    }

    /**
     * @return array{label:string,status:string,details:string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            $driver = (string) DB::connection()->getDriverName();
            $database = (string) DB::connection()->getDatabaseName();
            $migrationsTable = Schema::hasTable('migrations') ? 'migrations OK' : 'migrations absente';

            return [
                'label' => 'Base de donnees',
                'status' => 'ok',
                'details' => trim(sprintf('%s:%s %s', $driver, $database, $migrationsTable)),
            ];
        } catch (\Throwable $e) {
            return [
                'label' => 'Base de donnees',
                'status' => 'fail',
                'details' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{label:string,status:string,details:string}
     */
    private function checkStoragePath(string $path, string $label): array
    {
        $exists = File::exists($path);
        $writable = $exists && File::isWritable($path);

        return [
            'label' => $label,
            'status' => ($exists && $writable) ? 'ok' : 'fail',
            'details' => $exists
                ? ($writable ? 'present et accessible en ecriture' : 'present mais non accessible en ecriture')
                : 'absent',
        ];
    }

    /**
     * @return array{label:string,status:string,details:string}
     */
    private function checkOpenApiSpec(): array
    {
        $path = base_path('docs/openapi.yaml');

        return [
            'label' => 'Spec OpenAPI',
            'status' => File::exists($path) ? 'ok' : 'fail',
            'details' => File::exists($path) ? 'docs/openapi.yaml present' : 'docs/openapi.yaml absent',
        ];
    }

    /**
     * @return array{label:string,status:string,details:string}
     */
    private function checkQueueBackend(): array
    {
        $connection = (string) config('queue.default');

        if ($connection !== 'database') {
            return [
                'label' => 'Queue',
                'status' => 'ok',
                'details' => sprintf('connexion %s', $connection),
            ];
        }

        return [
            'label' => 'Queue',
            'status' => Schema::hasTable('jobs') ? 'ok' : 'fail',
            'details' => Schema::hasTable('jobs')
                ? 'connexion database et table jobs presente'
                : 'connexion database mais table jobs absente',
        ];
    }
}
