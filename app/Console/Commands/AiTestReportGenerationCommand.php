<?php

namespace App\Console\Commands;

use App\Services\Ai\ActionReportMetricsBuilder;
use App\Services\Ai\AiReportWritingService;
use Illuminate\Console\Command;

class AiTestReportGenerationCommand extends Command
{
    protected $signature = 'ai:test-report-generation {--type=pta : Type/scope de rapport} {--year= : Annee de reference}';

    protected $description = 'Teste la generation de brouillon de rapport depuis les metriques Laravel.';

    public function handle(ActionReportMetricsBuilder $metrics, AiReportWritingService $writer): int
    {
        $type = (string) $this->option('type');
        $year = $this->option('year');
        $filters = [];
        if ($year !== null && $year !== '') {
            $filters = [
                'period_start' => $year.'-01-01',
                'period_end' => $year.'-12-31',
            ];
        }

        $snapshot = $metrics->build($type, $filters);
        $this->line($writer->draft('Test rapport IA '.$type, $type, $snapshot));

        return self::SUCCESS;
    }
}
