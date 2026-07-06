<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class PtaDocumentVisionSourceService
{
    /**
     * @return array{paths:list<string>,temporary_directories:list<string>,source:string}
     */
    public function sources(string $path, string $extension): array
    {
        if (! (bool) config('ai_training.pta.vision_first_enabled', true)) {
            return $this->emptySources();
        }

        return match (strtolower($extension)) {
            'png', 'jpg', 'jpeg' => is_file($path)
                ? ['paths' => [$path], 'temporary_directories' => [], 'source' => 'image']
                : $this->emptySources(),
            'pdf' => (bool) config('ai_training.pta.vision_pdf_enabled', true)
                ? $this->pdfSources($path)
                : $this->emptySources(),
            default => $this->emptySources(),
        };
    }

    /**
     * @param  array{temporary_directories?:list<string>}  $sources
     */
    public function cleanup(array $sources): void
    {
        $storageRoot = realpath(storage_path('app')) ?: storage_path('app');

        foreach ($sources['temporary_directories'] ?? [] as $directory) {
            $resolved = realpath($directory);
            if ($resolved === false || ! str_starts_with($resolved, $storageRoot)) {
                continue;
            }

            File::deleteDirectory($resolved);
        }
    }

    /**
     * @return array{paths:list<string>,temporary_directories:list<string>,source:string}
     */
    private function pdfSources(string $path): array
    {
        if (! is_file($path)) {
            return $this->emptySources();
        }

        $temporaryDirectory = storage_path('app/ai/tmp/pta-vision/'.(string) Str::uuid());
        File::ensureDirectoryExists($temporaryDirectory);

        $paths = $this->renderWithConfiguredCommand($path, $temporaryDirectory)
            ?: $this->renderWithBundledWindowsScript($path, $temporaryDirectory);

        $paths = $this->limitedImagePaths($paths === [] ? $this->imagesIn($temporaryDirectory) : $paths);

        if ($paths === []) {
            File::deleteDirectory($temporaryDirectory);

            return $this->emptySources();
        }

        return [
            'paths' => $paths,
            'temporary_directories' => [$temporaryDirectory],
            'source' => 'pdf-render',
        ];
    }

    /**
     * @return list<string>
     */
    private function renderWithConfiguredCommand(string $path, string $outputDirectory): array
    {
        $command = trim((string) config('ai_training.pta.vision_pdf_render_command', ''));
        if ($command === '') {
            return [];
        }

        $command = str_replace(
            ['{file}', '{output}'],
            [escapeshellarg($path), escapeshellarg($outputDirectory)],
            $command
        );

        if (! str_contains($command, escapeshellarg($path))) {
            $command .= ' '.escapeshellarg($path).' '.escapeshellarg($outputDirectory);
        }

        try {
            $result = Process::timeout(max(30, (int) config('ai_training.pta.vision_pdf_render_timeout', 300)))
                ->run($command);

            if (! $result->successful()) {
                return [];
            }

            return array_values(array_filter(array_map(
                static fn (string $line): string => trim($line),
                preg_split('/\R/', $result->output()) ?: []
            ), static fn (string $line): bool => $line !== '' && is_file($line)));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function renderWithBundledWindowsScript(string $path, string $outputDirectory): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        try {
            $script = $this->resolvePath((string) config('ai_training.pta.windows_ocr_script_path', base_path('scripts/ocr/windows_pdf_ocr.ps1')));
            if (! is_file($script)) {
                return [];
            }

            $binary = (new ExecutableFinder)->find('powershell.exe')
                ?? (new ExecutableFinder)->find('powershell')
                ?? (new ExecutableFinder)->find('pwsh');

            if ($binary === null) {
                return [];
            }

            $result = Process::timeout(max(30, (int) config('ai_training.pta.vision_pdf_render_timeout', 300)))->run([
                $binary,
                '-NoProfile',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                $script,
                '-Path',
                $path,
                '-MaxPages',
                (string) max(1, (int) config('ai_training.pta.vision_pdf_max_pages', 3)),
                '-RenderWidth',
                (string) max(0, (int) config('ai_training.pta.vision_pdf_render_width', 1800)),
                '-ImageOutputDirectory',
                $outputDirectory,
                '-ImageOnly',
            ]);

            if (! $result->successful()) {
                return [];
            }

            return $this->imagesIn($outputDirectory);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function limitedImagePaths(array $paths): array
    {
        $limit = max(1, (int) config('ai_training.pta.vision_pdf_max_pages', 3));

        return array_slice(array_values(array_filter($paths, static fn (string $path): bool => is_file($path))), 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function imagesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $paths = [];
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
            $paths = array_merge($paths, glob($directory.'/*.'.$extension) ?: []);
        }

        sort($paths);

        return array_values($paths);
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @return array{paths:list<string>,temporary_directories:list<string>,source:string}
     */
    private function emptySources(): array
    {
        return ['paths' => [], 'temporary_directories' => [], 'source' => 'none'];
    }
}
