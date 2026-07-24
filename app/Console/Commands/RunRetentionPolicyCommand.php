<?php

namespace App\Console\Commands;

use App\Models\RetentionRun;
use App\Models\User;
use App\Services\Governance\RetentionOperationService;
use Illuminate\Console\Command;

class RunRetentionPolicyCommand extends Command
{
    protected $signature = 'anbg:retention-run {--execute : Cree les archives au lieu d un simple dry-run} {--actor_id= : ID utilisateur a enregistrer comme operateur}';

    protected $description = 'Execute un dry-run ou une archive de retention pour les donnees anciennes.';

    public function handle(RetentionOperationService $retentionOperationService): int
    {
        $actor = null;
        $actorId = $this->option('actor_id');
        if (is_numeric($actorId)) {
            $actor = User::query()->find((int) $actorId);
        }

        $execute = (bool) $this->option('execute');
        $operation = $retentionOperationService->run(
            RetentionRun::SCOPE_DATA,
            $execute,
            $actor,
            'console',
            null,
            'artisan anbg:retention-run'
        );
        $result = $operation['result'];

        $this->info('Mode: '.($result['mode'] ?? 'dry-run'));
        $this->line('Execution: #'.$operation['run']->id);

        if (isset($result['created']) && is_array($result['created'])) {
            foreach ($result['created'] as $label => $count) {
                $this->line(sprintf('%s: %d archive(s) creee(s)', $label, (int) $count));
            }
        }

        $summary = $result['summary'] ?? $result;
        if (is_array($summary) && isset($summary['counts']) && is_array($summary['counts'])) {
            $this->table(
                ['Categorie', 'Eligibles non archives'],
                collect($summary['counts'])
                    ->map(fn ($count, $label): array => [$label, (int) $count])
                    ->values()
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
