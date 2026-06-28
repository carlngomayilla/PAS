<?php

namespace App\Console\Commands;

use App\Services\Ai\PtaTrainingDatasetService;
use Illuminate\Console\Command;

class AiBuildTrainingDatasetCommand extends Command
{
    protected $signature = 'ai:build-training-dataset {--path= : Chemin JSONL de sortie}';

    protected $description = 'Exporte les corrections et rapports valides en dataset JSONL.';

    public function handle(PtaTrainingDatasetService $training): int
    {
        $path = $training->exportJsonl($this->option('path') ?: null);

        $this->info('Dataset IA genere : '.$path);

        return self::SUCCESS;
    }
}
