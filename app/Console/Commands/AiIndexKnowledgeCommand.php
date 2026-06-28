<?php

namespace App\Console\Commands;

use App\Services\Ai\AiKnowledgeService;
use Illuminate\Console\Command;

class AiIndexKnowledgeCommand extends Command
{
    protected $signature = 'ai:index-knowledge {--fresh : Reindexe depuis zero}';

    protected $description = 'Indexe les documents metier IA PAS/PAO/PTA et les references Excel officielles.';

    public function handle(AiKnowledgeService $knowledge): int
    {
        $stats = $knowledge->indexDefaultReferences((bool) $this->option('fresh'));

        $this->info('Base documentaire IA indexee.');
        $this->table(['Documents', 'Chunks'], [[$stats['documents'], $stats['chunks']]]);

        return self::SUCCESS;
    }
}
