<?php

namespace App\Services\Dashboard;

use Symfony\Component\Process\Process;
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
                $process = new Process([$python, $script]);
                $process->setInput($input);
                $process->setTimeout(self::TIMEOUT_SECONDS);
                $process->run();
            } catch (Throwable) {
                continue;
            }

            if (! $process->isSuccessful()) {
                continue;
            }

            $decoded = json_decode($process->getOutput(), true);
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
