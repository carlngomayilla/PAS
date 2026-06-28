<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class AiKnowledgeService
{
    public function __construct(
        private readonly AiEmbeddingService $embeddings
    ) {}

    /**
     * @return array{documents:int,chunks:int}
     */
    public function indexDefaultReferences(bool $fresh = false): array
    {
        $this->ensureDirectories();

        $references = [
            [
                'source' => (string) config('ai_training.pta.agent_reference_path'),
                'target' => $this->knowledgePath('referentiels/codes_agent_anbg_import.xlsx'),
                'type' => 'referentiel',
            ],
            [
                'source' => (string) config('ai_training.pta.import_template_path'),
                'target' => $this->knowledgePath('templates/modele_import_global_pas_pao_pta.xlsx'),
                'type' => 'template',
            ],
        ];

        foreach ($references as $reference) {
            if (! is_file($reference['source'])) {
                continue;
            }
            File::ensureDirectoryExists(dirname($reference['target']));
            File::copy($reference['source'], $reference['target']);
        }

        return $this->indexDirectory((string) config('ai_training.knowledge_root'), $fresh);
    }

    /**
     * @return array{documents:int,chunks:int}
     */
    public function indexDirectory(string $directory, bool $fresh = false): array
    {
        if (! is_dir($directory)) {
            throw new RuntimeException('Dossier de connaissance introuvable : '.$directory);
        }

        if ($fresh) {
            AiKnowledgeDocument::query()->delete();
        }

        $stats = ['documents' => 0, 'chunks' => 0];
        foreach (File::allFiles($directory) as $file) {
            if ($file->getFilename() === '.gitkeep') {
                continue;
            }

            $result = $this->indexFile($file->getPathname(), $this->typeFromPath($file->getPathname()));
            $stats['documents'] += 1;
            $stats['chunks'] += $result->chunks()->count();
        }

        return $stats;
    }

    public function indexFile(string $path, string $type = 'document'): AiKnowledgeDocument
    {
        if (! is_file($path)) {
            throw new RuntimeException('Document IA introuvable : '.$path);
        }

        $content = $this->extractText($path);
        $document = AiKnowledgeDocument::query()->updateOrCreate([
            'file_path' => $path,
        ], [
            'title' => pathinfo($path, PATHINFO_FILENAME),
            'type' => $type,
            'content' => $content,
            'metadata' => [
                'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                'size' => filesize($path),
                'indexed_at' => now()->toIso8601String(),
            ],
            'status' => AiKnowledgeDocument::STATUS_ACTIVE,
        ]);

        $document->chunks()->delete();
        foreach ($this->chunk($content) as $index => $chunk) {
            $document->chunks()->create([
                'chunk_index' => $index,
                'content' => $chunk,
                'metadata' => ['source_path' => $path],
                'embedding' => $this->embeddings->embed($chunk),
            ]);
        }

        return $document;
    }

    /**
     * @return list<array{document_id:int,title:string,type:string,content:string,score:float}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $needle = $this->embeddings->embed($query);

        return AiKnowledgeDocument::query()
            ->with('chunks')
            ->where('status', AiKnowledgeDocument::STATUS_ACTIVE)
            ->get()
            ->flatMap(fn (AiKnowledgeDocument $document) => $document->chunks->map(fn ($chunk): array => [
                'document_id' => $document->id,
                'title' => $document->title,
                'type' => $document->type,
                'content' => $chunk->content,
                'score' => $this->embeddings->cosine($needle, $chunk->embedding),
            ]))
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    public function ensureDirectories(): void
    {
        $root = (string) config('ai_training.knowledge_root');
        foreach (['pta', 'pas', 'pao', 'reports', 'procedures', 'templates', 'referentiels'] as $folder) {
            File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.$folder);
        }
        File::ensureDirectoryExists((string) config('ai_training.training_root'));
    }

    private function knowledgePath(string $relativePath): string
    {
        return rtrim((string) config('ai_training.knowledge_root'), '\\/').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    }

    private function extractText(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx' => $this->extractSpreadsheetText($path),
            'csv', 'txt', 'md', 'json' => (string) file_get_contents($path),
            default => pathinfo($path, PATHINFO_FILENAME),
        };
    }

    private function extractSpreadsheetText(string $path): string
    {
        $spreadsheet = IOFactory::load($path);
        $lines = [];
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $lines[] = '# '.$sheet->getTitle();
            $highestRow = $sheet->getHighestDataRow();
            $highestColumn = $sheet->getHighestDataColumn();
            for ($row = 1; $row <= $highestRow; $row++) {
                $values = $sheet->rangeToArray('A'.$row.':'.$highestColumn.$row, null, true, false)[0] ?? [];
                $text = implode(' | ', array_values(array_filter(array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    $values
                ), static fn (string $value): bool => $value !== '')));
                if ($text !== '') {
                    $lines[] = $text;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function chunk(string $content): array
    {
        $size = max(500, (int) config('ai_training.pta.chunk_size', 1800));
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $chunks = [];
        for ($offset = 0; $offset < mb_strlen($content); $offset += $size) {
            $chunks[] = mb_substr($content, $offset, $size);
        }

        return $chunks;
    }

    private function typeFromPath(string $path): string
    {
        $normalized = Str::of($path)->replace('\\', '/')->lower();

        return match (true) {
            $normalized->contains('/pta/') => 'pta',
            $normalized->contains('/pas/') => 'pas',
            $normalized->contains('/pao/') => 'pao',
            $normalized->contains('/reports/') => 'report',
            $normalized->contains('/procedures/') => 'procedure',
            $normalized->contains('/templates/') => 'template',
            $normalized->contains('/referentiels/') => 'referentiel',
            default => 'document',
        };
    }
}
