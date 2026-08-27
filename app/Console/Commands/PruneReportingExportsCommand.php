<?php

namespace App\Console\Commands;

use App\Services\Exports\ReportingExportRetentionService;
use Illuminate\Console\Command;

class PruneReportingExportsCommand extends Command
{
    protected $signature = 'anbg:prune-reporting-exports
        {--execute : Supprime definitivement les exports expires}';

    protected $description = 'Liste ou supprime explicitement les exports reporting dont le lien est expire.';

    public function handle(ReportingExportRetentionService $retentionService): int
    {
        $execute = (bool) $this->option('execute');
        $result = $retentionService->enforceRetention($execute);

        $this->line($result['examined'].' fichier(s) examine(s).');
        $this->line($result['expired'].' export(s) reporting expire(s) detecte(s).');

        if ($execute) {
            $this->info($result['deleted'].' export(s) reporting expire(s) supprime(s).');
        } else {
            $this->info('Simulation uniquement : aucun fichier supprime. Utilisez --execute pour appliquer la purge.');
        }

        if ($result['failed'] > 0) {
            $this->error($result['failed'].' export(s) n ont pas pu etre inspecte(s) ou supprime(s).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
