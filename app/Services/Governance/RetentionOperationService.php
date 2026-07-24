<?php

namespace App\Services\Governance;

use App\Models\JournalAudit;
use App\Models\RetentionRun;
use App\Models\User;
use App\Services\PlanningAutoArchiveService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RetentionOperationService
{
    public function __construct(
        private readonly RetentionService $retentionService,
        private readonly PlanningAutoArchiveService $planningAutoArchiveService
    ) {}

    /**
     * @return array{run:RetentionRun,result:array<string, mixed>}
     */
    public function run(
        string $scope,
        bool $execute,
        ?User $actor = null,
        string $source = 'web',
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $this->validateScope($scope);
        $mode = $execute ? RetentionRun::MODE_EXECUTE : RetentionRun::MODE_DRY_RUN;
        $run = RetentionRun::query()->create([
            'scope' => $scope,
            'mode' => $mode,
            'status' => RetentionRun::STATUS_RUNNING,
            'source' => in_array($source, ['web', 'console', 'scheduler'], true) ? $source : 'web',
            'initiated_by' => $actor?->id,
            'started_at' => now(),
        ]);

        $lock = $execute ? Cache::lock($this->lockKey($scope), 600) : null;
        $lockAcquired = false;
        $result = [];

        try {
            if ($lock instanceof Lock) {
                $lockAcquired = $lock->get();
                if (! $lockAcquired) {
                    throw ValidationException::withMessages([
                        'mode' => 'Une exécution est déjà en cours pour ce périmètre de rétention.',
                    ]);
                }
            }

            $candidates = $this->candidateCounts($scope);
            $result = $scope === RetentionRun::SCOPE_PLANNING
                ? $this->planningAutoArchiveService->run($execute, $actor)
                : $this->retentionService->archive($execute, $actor);
            $processed = $this->processedCounts($scope, $execute, $result, $candidates);

            $run->forceFill([
                'status' => RetentionRun::STATUS_COMPLETED,
                'batch_key' => $result['batch_key'] ?? null,
                'candidates' => $candidates,
                'processed' => $processed,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => RetentionRun::STATUS_FAILED,
                'error_message' => Str::limit($exception->getMessage(), 2000, ''),
                'completed_at' => now(),
            ])->save();
            $this->auditSafely($run, $actor, $ipAddress, $userAgent);

            throw $exception;
        } finally {
            if ($lock instanceof Lock && $lockAcquired) {
                $lock->release();
            }
        }

        $this->auditSafely($run, $actor, $ipAddress, $userAgent);

        return [
            'run' => $run->refresh(),
            'result' => $result,
        ];
    }

    public function lockKey(string $scope): string
    {
        return 'retention-operation:'.$scope;
    }

    private function validateScope(string $scope): void
    {
        if (! in_array($scope, [RetentionRun::SCOPE_DATA, RetentionRun::SCOPE_PLANNING], true)) {
            throw ValidationException::withMessages([
                'scope' => 'Le périmètre de rétention est invalide.',
            ]);
        }
    }

    /** @return array<string, int> */
    private function candidateCounts(string $scope): array
    {
        $summary = $scope === RetentionRun::SCOPE_PLANNING
            ? $this->planningAutoArchiveService->summary()
            : $this->retentionService->summary();

        return collect($summary['counts'] ?? [])
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, int>  $candidates
     * @return array<string, int>
     */
    private function processedCounts(string $scope, bool $execute, array $result, array $candidates): array
    {
        if (! $execute) {
            return collect($candidates)
                ->map(fn (): int => 0)
                ->all();
        }

        $counts = $scope === RetentionRun::SCOPE_PLANNING
            ? ($result['archived'] ?? [])
            : ($result['created'] ?? []);

        return collect(is_array($counts) ? $counts : [])
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    private function audit(
        RetentionRun $run,
        ?User $actor,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        JournalAudit::query()->create([
            'user_id' => $actor?->id,
            'module' => 'retention',
            'entite_type' => RetentionRun::class,
            'entite_id' => (int) $run->id,
            'action' => implode('_', ['retention', $run->scope, $run->mode, $run->status]),
            'ancienne_valeur' => null,
            'nouvelle_valeur' => $run->only([
                'scope',
                'mode',
                'status',
                'source',
                'batch_key',
                'candidates',
                'processed',
                'error_message',
                'started_at',
                'completed_at',
            ]),
            'adresse_ip' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    private function auditSafely(
        RetentionRun $run,
        ?User $actor,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        try {
            $this->audit($run, $actor, $ipAddress, $userAgent);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
