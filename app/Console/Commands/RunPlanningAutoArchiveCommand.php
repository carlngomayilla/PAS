<?php

namespace App\Console\Commands;

use App\Models\RetentionRun;
use App\Models\User;
use App\Services\Governance\RetentionOperationService;
use Illuminate\Console\Command;

class RunPlanningAutoArchiveCommand extends Command
{
    protected $signature = 'anbg:planning-auto-archive {--execute : Passe les PAO/PTA eligibles au statut archive} {--actor_id= : ID utilisateur a enregistrer comme operateur}';

    protected $description = 'Execute un dry-run ou l archivage automatique des PAO/PTA clotures.';

    public function handle(RetentionOperationService $retentionOperationService): int
    {
        $actor = null;
        $actorId = $this->option('actor_id');
        if (is_numeric($actorId)) {
            $actor = User::query()->find((int) $actorId);
        }

        $operation = $retentionOperationService->run(
            RetentionRun::SCOPE_PLANNING,
            (bool) $this->option('execute'),
            $actor,
            'console',
            null,
            'artisan anbg:planning-auto-archive'
        );
        $result = $operation['result'];

        $this->info('Mode: '.($result['mode'] ?? 'dry-run'));
        $this->line('Execution: #'.$operation['run']->id);
        $this->line('Archivage actif: '.((bool) ($result['enabled'] ?? false) ? 'oui' : 'non'));

        $archived = is_array($result['archived'] ?? null) ? $result['archived'] : [];
        foreach ($archived as $label => $count) {
            $this->line(sprintf('%s: %d archive(s)', $label, (int) $count));
        }

        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        if (is_array($summary['counts'] ?? null)) {
            $this->table(
                ['Categorie', 'Eligibles'],
                collect($summary['counts'])
                    ->map(fn ($count, $label): array => [$label, (int) $count])
                    ->values()
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
