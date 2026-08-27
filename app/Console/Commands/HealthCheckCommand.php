<?php

namespace App\Console\Commands;

use App\Services\Notifications\BrevoMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Stringable;
use Throwable;

class HealthCheckCommand extends Command
{
    protected $signature = 'anbg:health-check {--json : Affiche le resultat en JSON}';

    protected $description = 'Verifie les dependances techniques minimales de l application.';

    public function handle(
        MasterSupervisorRepository $masterSupervisors,
        BrevoMailService $brevoMailService
    ): int {
        $checks = [
            $this->checkDatabase(),
            $this->checkStoragePath(storage_path('app'), 'storage/app'),
            $this->checkStoragePath(storage_path('logs'), 'storage/logs'),
            $this->checkStoragePath(base_path('bootstrap/cache'), 'bootstrap/cache'),
            $this->checkOpenApiSpec(),
            $this->checkQueueBackend(),
            $this->checkBrevo($brevoMailService),
        ];

        if ($this->shouldCheckHorizon()) {
            $checks[] = $this->checkHorizon($masterSupervisors);
        }

        if ((bool) config('dashboard.next_pilot.enabled', false)) {
            $checks[] = $this->checkNextPilot();
        }

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
        } catch (Throwable $e) {
            return [
                'label' => 'Base de donnees',
                'status' => 'fail',
                'details' => sprintf('connexion indisponible (%s)', class_basename($e)),
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

        if ($connection === 'redis') {
            return $this->checkRedisQueueBackend();
        }

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

    /**
     * @return array{label:string,status:string,details:string}
     */
    private function checkRedisQueueBackend(): array
    {
        $redisConnection = trim((string) config('queue.connections.redis.connection', 'default'));
        $redisConnection = $redisConnection !== '' ? $redisConnection : 'default';

        try {
            $response = Redis::connection($redisConnection)->command('ping');

            if (! $this->isSuccessfulRedisPing($response)) {
                return [
                    'label' => 'Queue',
                    'status' => 'fail',
                    'details' => sprintf('connexion redis %s: reponse PING invalide', $redisConnection),
                ];
            }

            return [
                'label' => 'Queue',
                'status' => 'ok',
                'details' => sprintf('connexion redis %s: PING OK', $redisConnection),
            ];
        } catch (Throwable $exception) {
            return [
                'label' => 'Queue',
                'status' => 'fail',
                'details' => sprintf(
                    'connexion redis %s: PING indisponible (%s)',
                    $redisConnection,
                    class_basename($exception)
                ),
            ];
        }
    }

    /**
     * @return array{label:string,status:string,details:string}
     */
    private function checkBrevo(BrevoMailService $brevoMailService): array
    {
        $configuration = $brevoMailService->configurationStatus();
        $defaultMailer = $this->defaultBrevoMailerStatus();

        if ($defaultMailer['required'] && ! $defaultMailer['configured']) {
            return [
                'label' => 'Brevo',
                'status' => 'fail',
                'details' => sprintf(
                    'mailer par defaut brevo incomplet (%s)',
                    $defaultMailer['issue'] ?? 'smtp_configuration_invalid'
                ),
            ];
        }

        if (! $configuration['enabled']) {
            return [
                'label' => 'Brevo',
                'status' => 'ok',
                'details' => $defaultMailer['required']
                    ? 'mailer par defaut brevo configure; canal complementaire desactive'
                    : 'canal desactive',
            ];
        }

        $details = $configuration['configured']
            ? sprintf('transport %s configure', $configuration['transport'])
            : sprintf(
                'transport %s incomplet (%s)',
                $configuration['transport'],
                $configuration['issue'] ?? 'configuration_invalid'
            );

        if ($configuration['configured'] && $defaultMailer['required']) {
            $details .= '; mailer par defaut brevo configure';
        }

        return [
            'label' => 'Brevo',
            'status' => $configuration['configured'] ? 'ok' : 'fail',
            'details' => $details,
        ];
    }

    /**
     * @return array{required: bool, configured: bool, issue: string|null}
     */
    private function defaultBrevoMailerStatus(): array
    {
        $required = app()->isProduction()
            && strtolower(trim((string) config('mail.default'))) === 'brevo';

        if (! $required) {
            return [
                'required' => false,
                'configured' => true,
                'issue' => null,
            ];
        }

        $mailer = config('mail.mailers.brevo');
        if (! is_array($mailer)
            || strtolower(trim((string) ($mailer['transport'] ?? ''))) !== 'smtp'
            || trim((string) ($mailer['host'] ?? '')) === ''
            || (int) ($mailer['port'] ?? 0) <= 0) {
            return [
                'required' => true,
                'configured' => false,
                'issue' => 'smtp_configuration_invalid',
            ];
        }

        $configured = trim((string) ($mailer['username'] ?? '')) !== ''
            && trim((string) ($mailer['password'] ?? '')) !== '';

        return [
            'required' => true,
            'configured' => $configured,
            'issue' => $configured ? null : 'smtp_credentials_missing',
        ];
    }

    private function shouldCheckHorizon(): bool
    {
        return app()->isProduction()
            && (string) config('queue.default') === 'redis';
    }

    /**
     * @return array{label:string,status:string,details:string}
     */
    private function checkHorizon(MasterSupervisorRepository $masterSupervisors): array
    {
        try {
            $masters = collect($masterSupervisors->all());
        } catch (Throwable $exception) {
            return [
                'label' => 'Horizon',
                'status' => 'fail',
                'details' => sprintf('etat indisponible (%s)', class_basename($exception)),
            ];
        }

        if ($masters->isEmpty()) {
            return [
                'label' => 'Horizon',
                'status' => 'fail',
                'details' => 'aucun superviseur maitre actif',
            ];
        }

        $statuses = $masters
            ->map(static fn (mixed $master): string => strtolower(trim((string) data_get($master, 'status'))))
            ->values();
        $isRunning = $statuses->every(static fn (string $status): bool => $status === 'running');

        return [
            'label' => 'Horizon',
            'status' => $isRunning ? 'ok' : 'fail',
            'details' => $isRunning
                ? sprintf('%d superviseur(s) maitre(s) running', $masters->count())
                : 'superviseur maitre absent, en pause ou dans un etat invalide',
        ];
    }

    private function isSuccessfulRedisPing(mixed $response): bool
    {
        if ($response === true) {
            return true;
        }

        if ($response instanceof Stringable) {
            $response = (string) $response;
        }

        return is_string($response)
            && strcasecmp(ltrim(trim($response), '+'), 'PONG') === 0;
    }

    /**
     * @return array{label:string,status:string,details:string}
     */
    private function checkNextPilot(): array
    {
        $healthUrl = trim((string) config('dashboard.next_pilot.health_url', ''));
        if (! $this->isAllowedInternalHealthUrl($healthUrl)) {
            return [
                'label' => 'Dashboard Next',
                'status' => 'fail',
                'details' => 'URL de sante interne invalide',
            ];
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(1)
                ->timeout(3)
                ->withoutRedirecting()
                ->get($healthUrl);
            $isHealthy = $response->successful()
                && $response->json('status') === 'ok';

            return [
                'label' => 'Dashboard Next',
                'status' => $isHealthy ? 'ok' : 'fail',
                'details' => $isHealthy
                    ? 'runtime Next joignable'
                    : sprintf('reponse de sante invalide (HTTP %d)', $response->status()),
            ];
        } catch (Throwable $exception) {
            return [
                'label' => 'Dashboard Next',
                'status' => 'fail',
                'details' => sprintf('runtime indisponible (%s)', class_basename($exception)),
            ];
        }
    }

    private function isAllowedInternalHealthUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($scheme, ['http', 'https'], true)
            && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }
}
