<?php

namespace App\Console\Commands;

use App\Services\Ai\PtaDocumentStructureExtractorService;
use Illuminate\Console\Command;

class AiTestPtaExtractionCommand extends Command
{
    protected $signature = 'ai:test-pta-extraction {file : Fichier texte extrait ou document source a tester}';

    protected $description = 'Teste la detection de structure PAS/PAO/PTA sans appel IA externe.';

    public function handle(PtaDocumentStructureExtractorService $extractor): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error('Fichier introuvable : '.$path);

            return self::FAILURE;
        }

        $content = (string) file_get_contents($path);
        $result = $extractor->extractFromText($content);

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
