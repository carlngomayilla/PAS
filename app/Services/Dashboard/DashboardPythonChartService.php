<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

class DashboardPythonChartService
{
    private const TIMEOUT_SECONDS = 18;

    private static ?bool $available = null;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload): array
    {
        if (self::$available === false || empty($payload['rows'])) {
            return [];
        }

        $script = base_path('analytics/charts/dashboard_charts.py');
        if (! is_file($script)) {
            self::$available = false;

            return [];
        }

        $cacheKey = $this->cacheKey($payload, $script);
        $ttl = now()->addMinutes(max(1, (int) config('dashboard.charts.cache_minutes', 10)));

        try {
            return Cache::remember($cacheKey, $ttl, fn (): array => $this->generateFresh($payload, $script));
        } catch (Throwable) {
            return $this->generateFresh($payload, $script);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function generateFresh(array $payload, string $script): array
    {
        if (self::$available === false || empty($payload['rows'])) {
            return [];
        }

        $pythonBinaries = $this->pythonBinaries();
        if ($pythonBinaries === []) {
            self::$available = false;

            return [];
        }

        try {
            $input = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        foreach ($pythonBinaries as $python) {
            try {
                $result = Process::timeout(self::TIMEOUT_SECONDS)
                    ->input($input)
                    ->run([$python, $script]);
            } catch (Throwable) {
                continue;
            }

            if ($result->failed()) {
                continue;
            }

            $decoded = json_decode($result->output(), true);
            if (! is_array($decoded)) {
                continue;
            }

            $figures = $decoded['figures'] ?? [];

            return is_array($figures) ? $figures : [];
        }

        self::$available = false;

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cacheKey(array $payload, string $script): string
    {
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $parts = [
            'tenant' => $context['tenant_id'] ?? 'default',
            'year' => $context['year'] ?? 'all',
            'period' => $context['period'] ?? 'all',
            'direction' => $context['direction_id'] ?? 'all',
            'service' => $context['service_id'] ?? 'all',
            'script' => @filemtime($script) ?: 0,
            'payload' => sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''),
        ];

        return 'dashboard_charts:'.implode(':', array_map(static fn ($value): string => (string) $value, $parts));
    }

    /**
     * @return array<int, string>
     */
    private function pythonBinaries(): array
    {
        $candidates = [
            base_path('analytics/.venv/bin/python'),
            base_path('analytics/.venv/Scripts/python.exe'),
        ];

        if (filter_var(env('DASHBOARD_PYTHON_CHARTS', false), FILTER_VALIDATE_BOOLEAN)) {
            $candidates = [
                ...$candidates,
                ...(PHP_OS_FAMILY === 'Windows' ? ['python', 'python3'] : ['python3', 'python']),
            ];
        }

        $binaries = [];
        foreach ($candidates as $candidate) {
            if (str_contains($candidate, DIRECTORY_SEPARATOR) || str_contains($candidate, '/')) {
                if (is_file($candidate)) {
                    $binaries[] = $candidate;
                }

                continue;
            }

            $binaries[] = $candidate;
        }

        return array_values(array_unique($binaries));
    }
}
