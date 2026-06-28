<?php

namespace App\Services\Ai;

class PtaDocumentToImportGlobalMapperService
{
    public function __construct(
        private readonly PtaAiImportNormalizerService $normalizer,
        private readonly PtaImportTemplateAnalyzerService $template
    ) {}

    /**
     * @param  array{document?:array<string,mixed>,items?:list<array<string,mixed>>}  $structured
     * @return array{columns:list<string>,rows:list<array<string,mixed>>,valid:int,invalid:int,quality:list<array<string,mixed>>}
     */
    public function map(array $structured): array
    {
        $document = $structured['document'] ?? [];
        $items = $structured['items'] ?? [];
        $rows = [];
        $quality = [];
        $valid = 0;
        $invalid = 0;

        foreach ($this->normalizer->normalizeItems($document, $items) as $result) {
            $rows[] = $result['row'];
            $quality[] = [
                'valid' => $result['validation']['valid'],
                'score' => $result['validation']['score'],
                'errors' => $result['validation']['errors'],
                'warnings' => $result['validation']['warnings'],
                'agent_resolution' => $result['agent_resolution'],
            ];

            if ($result['validation']['valid']) {
                $valid++;
            } else {
                $invalid++;
            }
        }

        return [
            'columns' => $this->template->columns(),
            'rows' => $rows,
            'valid' => $valid,
            'invalid' => $invalid,
            'quality' => $quality,
        ];
    }
}
