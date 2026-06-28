<?php

namespace App\Services\Ai;

use RuntimeException;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class PtaDocumentTextExtractionService
{
    public function extract(string $path, string $extension): string
    {
        if (! is_file($path)) {
            throw new RuntimeException('Le fichier source est introuvable.');
        }

        return match (strtolower($extension)) {
            'pdf' => $this->extractPdf($path),
            default => throw new RuntimeException('Extraction texte non disponible pour ce type de document.'),
        };
    }

    private function extractPdf(string $path): string
    {
        $text = $this->normalizeText($this->extractWithConfiguredCommand($path));
        if ($text !== '' && mb_strlen($text) >= 80) {
            return $text;
        }

        $text = $this->extractWithPdftotext($path)
            ?: $this->extractWithPdfParser($path)
            ?: $this->extractRawPdfText($path);

        $text = $this->normalizeText($text);
        if ($text !== '' && mb_strlen($text) >= 80) {
            return $text;
        }

        if ($this->looksLikeImageOnlyPdf($path) || $this->looksLikeImageDominantPdf($path)) {
            $text = $this->normalizeText(
                $this->extractWithConfiguredOcrCommand($path)
                    ?: $this->extractWithBundledWindowsOcr($path)
            );

            if ($text !== '' && mb_strlen($text) >= 80) {
                return $text;
            }

            throw new RuntimeException($this->scannedPdfMessage());
        }

        throw new RuntimeException('Aucune donnee texte exploitable n a ete extraite du PDF.');
    }

    private function extractWithConfiguredCommand(string $path): ?string
    {
        return $this->extractWithConfiguredProcess($path, 'pdf_text_command');
    }

    private function extractWithConfiguredOcrCommand(string $path): ?string
    {
        return $this->extractWithConfiguredProcess($path, 'pdf_ocr_command');
    }

    private function extractWithConfiguredProcess(string $path, string $configKey): ?string
    {
        $command = trim((string) config('ai_training.pta.'.$configKey, ''));
        if ($command === '') {
            return null;
        }

        $escapedPath = escapeshellarg($path);
        $command = str_contains($command, '{file}')
            ? str_replace('{file}', $escapedPath, $command)
            : $command.' '.$escapedPath;

        return $this->runShellCommand($command);
    }

    private function extractWithBundledWindowsOcr(string $path): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! (bool) config('ai_training.pta.windows_ocr_enabled', true)) {
            return null;
        }

        try {
            $script = (string) config('ai_training.pta.windows_ocr_script_path', base_path('scripts/ocr/windows_pdf_ocr.ps1'));
            if (! is_file($script)) {
                return null;
            }

            $binary = (new ExecutableFinder)->find('powershell.exe')
                ?? (new ExecutableFinder)->find('powershell')
                ?? (new ExecutableFinder)->find('pwsh');

            if ($binary === null) {
                return null;
            }

            $process = new Process([
                $binary,
                '-NoProfile',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                $script,
                '-Path',
                $path,
                '-MaxPages',
                (string) max(0, (int) config('ai_training.pta.windows_ocr_max_pages', 0)),
                '-RenderWidth',
                (string) max(0, (int) config('ai_training.pta.windows_ocr_render_width', 2600)),
            ]);
            $process->setTimeout(max(30, (int) config('ai_training.pta.windows_ocr_timeout', 300)));
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractWithPdftotext(string $path): ?string
    {
        try {
            $binary = (new ExecutableFinder)->find('pdftotext');
            if ($binary === null) {
                return null;
            }

            $process = new Process([$binary, '-layout', $path, '-']);
            $process->setTimeout(60);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractWithPdfParser(string $path): ?string
    {
        if (! $this->pdfParserAvailable()) {
            return null;
        }

        try {
            return (new Parser)->parseFile($path)->getText();
        } catch (Throwable) {
            return null;
        }
    }

    private function pdfParserAvailable(): bool
    {
        if (class_exists(Parser::class)) {
            return true;
        }

        $basePath = base_path('vendor/smalot/pdfparser/src/Smalot/PdfParser');
        if (! is_dir($basePath)) {
            return false;
        }

        spl_autoload_register(static function (string $class) use ($basePath): void {
            $prefix = 'Smalot\\PdfParser\\';
            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $path = $basePath.'/'.str_replace('\\', '/', $relativeClass).'.php';
            if (is_file($path)) {
                require_once $path;
            }
        });

        return class_exists(Parser::class);
    }

    private function extractRawPdfText(string $path): ?string
    {
        $content = file_get_contents($path);
        if (! is_string($content) || $content === '') {
            return null;
        }

        preg_match_all('/\(([^()]{4,})\)\s*Tj/s', $content, $matches);
        if (($matches[1] ?? []) === []) {
            preg_match_all('/\(([^()]{4,})\)/s', $content, $matches);
        }

        $chunks = array_map(
            static fn (string $value): string => stripcslashes($value),
            $matches[1] ?? []
        );

        return implode("\n", $chunks);
    }

    private function runShellCommand(string $command): ?string
    {
        try {
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(120);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function looksLikeImageOnlyPdf(string $path): bool
    {
        $content = file_get_contents($path);
        if (! is_string($content)) {
            return false;
        }

        $imageCount = substr_count($content, '/Subtype /Image');
        $toUnicodeCount = substr_count($content, '/ToUnicode');
        $textOperators = preg_match_all('/\b(?:Tj|TJ)\b/', $content) ?: 0;

        return $imageCount >= 2 && $toUnicodeCount === 0 && $textOperators < 3;
    }

    private function looksLikeImageDominantPdf(string $path): bool
    {
        $content = file_get_contents($path);
        if (! is_string($content)) {
            return false;
        }

        return substr_count($content, '/Subtype /Image') >= 2
            && substr_count($content, '/ToUnicode') === 0;
    }

    private function scannedPdfMessage(): string
    {
        return 'Le PDF semble etre un document scanne ou compose uniquement d images. '
            .'L OCR automatique n a pas pu extraire assez de texte exploitable. '
            .'Configurez AI_PTA_PDF_OCR_COMMAND ou AI_PTA_PDF_TEXT_COMMAND, ou importez le modele Excel/source texte.';
    }

    private function normalizeText(?string $text): string
    {
        if (! is_string($text)) {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(["\u{00A0}", "\0"], [' ', ''], $text);
        $text = preg_replace("/[ \t]+$/m", '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
