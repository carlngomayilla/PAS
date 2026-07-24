<?php

namespace App\Services\AiImport;

use App\Services\Ai\PtaDocumentTextExtractionService;

class PdfExtractionService
{
    public function __construct(
        private readonly PtaDocumentTextExtractionService $extractor
    ) {}

    /**
     * @return array{source_type:string,text:string,rows:list<array<string,mixed>>,metadata:array<string,mixed>}
     */
    public function extract(string $absolutePath): array
    {
        return [
            'source_type' => 'pdf',
            'text' => $this->extractor->extract($absolutePath, 'pdf'),
            'rows' => [],
            'metadata' => [
                'path' => $absolutePath,
            ],
        ];
    }
}
