<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class PtaDocumentTextExtractionService
{
    /**
     * @var array<string,array{size:int,image_count:int,to_unicode_count:int,text_operator_count:int}>
     */
    private array $pdfProfiles = [];

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
        if ($this->hasEnoughText($text)) {
            return $text;
        }

        $text = $this->normalizeText($this->extractWithPdftotext($path));
        if ($this->hasEnoughText($text)) {
            return $text;
        }

        $text = $this->normalizeText($this->extractRawPdfText($path));
        if ($this->hasEnoughText($text)) {
            return $text;
        }

        if ($this->shouldPreferOcrBeforePdfParser($path)) {
            $text = $this->extractWithOcr($path);
            if ($this->hasEnoughText($text)) {
                return $text;
            }

            throw new RuntimeException($this->scannedPdfMessage());
        }

        $text = $this->normalizeText($this->extractWithPdfParser($path));
        if ($this->hasEnoughText($text)) {
            return $text;
        }

        $guardReason = $this->pdfParserGuardReason($path);
        if ($guardReason !== null) {
            throw new RuntimeException($this->pdfParserGuardMessage($path, $guardReason));
        }

        throw new RuntimeException(
            'Aucune donnee texte exploitable n a ete extraite du PDF. '
            .'Configurez AI_PTA_PDF_TEXT_COMMAND ou AI_PTA_PDF_OCR_COMMAND, '
            .'verifiez les dependances PDF/OCR du serveur, ou importez le modele Excel/source texte.'
        );
    }

    private function extractWithConfiguredCommand(string $path): ?string
    {
        return $this->extractWithConfiguredProcess($path, 'pdf_text_command');
    }

    private function extractWithConfiguredOcrCommand(string $path): ?string
    {
        foreach ($this->configuredOcrCommandKeys() as $configKey) {
            $text = $this->normalizeText($this->extractWithConfiguredProcess($path, $configKey));
            if ($this->hasEnoughText($text)) {
                return $text;
            }
        }

        return null;
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

        $timeout = $this->isOcrCommandKey($configKey)
            ? max(30, (int) config('ai_training.pta.pdf_ocr_timeout', 900))
            : 120;

        return $this->runShellCommand($command, $timeout);
    }

    private function extractWithBundledOcr(string $path): ?string
    {
        if ($this->configuredOcrEngine() === 'none') {
            return null;
        }

        return $this->extractWithBundledWindowsOcr($path)
            ?: $this->extractWithBundledLinuxOcr($path);
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

            $result = Process::timeout(max(30, (int) config('ai_training.pta.windows_ocr_timeout', 300)))->run([
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

            return $result->successful() ? $result->output() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractWithBundledLinuxOcr(string $path): ?string
    {
        if (PHP_OS_FAMILY === 'Windows' || ! (bool) config('ai_training.pta.linux_ocr_enabled', true)) {
            return null;
        }

        try {
            $script = (string) config('ai_training.pta.linux_ocr_script_path', base_path('scripts/ocr/linux_pdf_ocr.sh'));
            if (! is_file($script)) {
                return null;
            }

            $binary = (new ExecutableFinder)->find('bash');
            if ($binary === null) {
                return null;
            }

            $result = Process::timeout(max(30, (int) config('ai_training.pta.linux_ocr_timeout', 900)))->run([$binary, $script, $path]);

            return $result->successful() ? $result->output() : null;
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

            $result = Process::timeout(60)->run([$binary, '-layout', $path, '-']);

            return $result->successful() ? $result->output() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractWithPdfParser(string $path): ?string
    {
        if ($this->pdfParserGuardReason($path) !== null) {
            return null;
        }

        if (! $this->pdfParserAvailable()) {
            return null;
        }

        try {
            return $this->makePdfParser()->parseFile($path)->getText();
        } catch (Throwable) {
            return null;
        }
    }

    protected function makePdfParser(): Parser
    {
        return new Parser;
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
        $content = @file_get_contents($path);
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

    private function runShellCommand(string $command, int $timeout = 120): ?string
    {
        try {
            $result = Process::timeout($timeout)->run($command);

            return $result->successful() ? $result->output() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function looksLikeImageOnlyPdf(string $path): bool
    {
        $profile = $this->pdfProfile($path);

        return $profile['image_count'] > 0
            && $profile['to_unicode_count'] === 0
            && $profile['text_operator_count'] < 3;
    }

    private function looksLikeImageDominantPdf(string $path): bool
    {
        $profile = $this->pdfProfile($path);

        return $profile['image_count'] >= 2
            && $profile['to_unicode_count'] === 0;
    }

    private function shouldPreferOcrBeforePdfParser(string $path): bool
    {
        return $this->looksLikeImageOnlyPdf($path) || $this->looksLikeImageDominantPdf($path);
    }

    private function extractWithOcr(string $path): string
    {
        return $this->normalizeText(
            $this->extractWithConfiguredOcrCommand($path)
                ?: $this->extractWithBundledOcr($path)
        );
    }

    private function pdfParserGuardReason(string $path): ?string
    {
        if (! (bool) config('ai_training.pta.pdf_parser_enabled', true)) {
            return 'disabled';
        }

        if ($this->shouldPreferOcrBeforePdfParser($path)) {
            return 'image';
        }

        $maxBytes = max(0, (int) config('ai_training.pta.pdf_parser_max_bytes', 5 * 1024 * 1024));
        if ($maxBytes > 0 && $this->pdfProfile($path)['size'] > $maxBytes) {
            return 'size';
        }

        return null;
    }

    private function pdfParserGuardMessage(string $path, string $reason): string
    {
        if ($reason === 'image') {
            return $this->scannedPdfMessage();
        }

        if ($reason === 'disabled') {
            return 'Le texte du PDF n a pas pu etre extrait par les outils rapides, et l analyse PDF interne Smalot est desactivee. '
                .'Configurez AI_PTA_PDF_TEXT_COMMAND ou AI_PTA_PDF_OCR_COMMAND, reactivez AI_PTA_PDF_PARSER_ENABLED, '
                .'ou importez le modele Excel/source texte.';
        }

        $size = $this->formatBytes($this->pdfProfile($path)['size']);
        $limit = $this->formatBytes(max(0, (int) config('ai_training.pta.pdf_parser_max_bytes', 5 * 1024 * 1024)));

        return 'Le texte du PDF n a pas pu etre extrait par les outils rapides. '
            .'L analyse PDF interne Smalot a ete ignoree car le fichier pese '.$size.' et depasse le seuil AI_PTA_PDF_PARSER_MAX_BYTES='.$limit.'. '
            .'Configurez AI_PTA_PDF_TEXT_COMMAND ou AI_PTA_PDF_OCR_COMMAND, augmentez prudemment ce seuil hors requete web, '
            .'ou importez le modele Excel/source texte.';
    }

    private function scannedPdfMessage(): string
    {
        $engine = $this->configuredOcrEngine();
        $commandNames = implode(', ', $this->configuredOcrEnvironmentNames());
        $commandHint = $commandNames === '' ? 'AI_PTA_PDF_OCR_COMMAND' : $commandNames;

        return 'Le PDF semble etre un document scanne ou compose uniquement d images. '
            .'L OCR local'.($engine === 'auto' ? '' : ' '.$engine).' n a pas pu extraire assez de texte exploitable. '
            .'Configurez AI_PTA_OCR_ENGINE, '.$commandHint.' ou AI_PTA_PDF_TEXT_COMMAND, '
            .'verifiez les dependances OCR du serveur, ou importez le modele Excel/source texte.';
    }

    private function hasEnoughText(string $text): bool
    {
        return $text !== '' && mb_strlen($text) >= max(20, (int) config('ai_training.pta.pdf_min_text_chars', 80));
    }

    /**
     * @return array{size:int,image_count:int,to_unicode_count:int,text_operator_count:int}
     */
    private function pdfProfile(string $path): array
    {
        $cacheKey = realpath($path) ?: $path;
        if (isset($this->pdfProfiles[$cacheKey])) {
            return $this->pdfProfiles[$cacheKey];
        }

        $content = @file_get_contents($path);
        if (! is_string($content)) {
            return $this->pdfProfiles[$cacheKey] = [
                'size' => 0,
                'image_count' => 0,
                'to_unicode_count' => 0,
                'text_operator_count' => 0,
            ];
        }

        return $this->pdfProfiles[$cacheKey] = [
            'size' => mb_strlen($content, '8bit'),
            'image_count' => substr_count($content, '/Subtype /Image'),
            'to_unicode_count' => substr_count($content, '/ToUnicode'),
            'text_operator_count' => preg_match_all('/\b(?:Tj|TJ)\b/', $content) ?: 0,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1).' Mo';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' Ko';
        }

        return $bytes.' octets';
    }

    /**
     * @return list<string>
     */
    private function configuredOcrCommandKeys(): array
    {
        $keys = match ($this->configuredOcrEngine()) {
            'paddleocr' => ['paddleocr_command', 'pdf_ocr_command'],
            'surya' => ['surya_ocr_command', 'pdf_ocr_command'],
            'custom' => ['pdf_ocr_command'],
            'none' => [],
            default => ['pdf_ocr_command', 'paddleocr_command', 'surya_ocr_command'],
        };

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private function configuredOcrEnvironmentNames(): array
    {
        $names = [
            'pdf_ocr_command' => 'AI_PTA_PDF_OCR_COMMAND',
            'paddleocr_command' => 'AI_PTA_PADDLEOCR_COMMAND',
            'surya_ocr_command' => 'AI_PTA_SURYA_OCR_COMMAND',
        ];

        return array_values(array_map(
            static fn (string $key): string => $names[$key],
            array_filter($this->configuredOcrCommandKeys(), static fn (string $key): bool => isset($names[$key]))
        ));
    }

    private function configuredOcrEngine(): string
    {
        $engine = strtolower(trim((string) config('ai_training.pta.ocr_engine', 'auto')));
        $engine = str_replace(['_', '-'], '', $engine);

        return match ($engine) {
            'paddle', 'paddleocr' => 'paddleocr',
            'surya', 'suryaocr' => 'surya',
            'command', 'configured', 'custom' => 'custom',
            'off', 'disabled', 'none' => 'none',
            default => 'auto',
        };
    }

    private function isOcrCommandKey(string $configKey): bool
    {
        return in_array($configKey, ['pdf_ocr_command', 'paddleocr_command', 'surya_ocr_command'], true);
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
