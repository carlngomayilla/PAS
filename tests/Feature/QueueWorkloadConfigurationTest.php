<?php

namespace Tests\Feature;

use App\Jobs\AnalyzePasPaoPtaWithAiJob;
use App\Jobs\AnalyzePtaImportBatch;
use App\Jobs\GenerateReportJob;
use App\Jobs\SendBrevoNotificationEmailsJob;
use App\Jobs\ValidateImportRowsJob;
use App\Models\AiImportBatch;
use Tests\TestCase;

class QueueWorkloadConfigurationTest extends TestCase
{
    public function test_active_jobs_use_their_canonical_queue_without_forcing_a_connection(): void
    {
        config()->set('services.brevo.queue.connection', null);
        config()->set('services.brevo.queue.name', 'notifications');

        foreach ($this->activeJobsByWorkload() as $queue => $jobs) {
            foreach ($jobs as $job) {
                $this->assertSame($queue, $job->queue, $job::class.' doit définir sa file dans son constructeur.');
                $this->assertNull($job->connection, $job::class.' doit hériter de la connexion de file de l application.');
            }
        }
    }

    public function test_active_job_timeouts_and_retries_respect_their_workload_profile(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach ($this->activeJobsByWorkload() as $workload => $jobs) {
            $jobTimeout = (int) config('queue.workloads.'.$workload.'.job_timeout');
            $supervisorTimeout = (int) config('queue.workloads.'.$workload.'.supervisor_timeout');

            $this->assertGreaterThan(0, $jobTimeout);
            $this->assertGreaterThan($jobTimeout, $supervisorTimeout);
            $this->assertGreaterThan($supervisorTimeout, $retryAfter);

            foreach ($jobs as $job) {
                $this->assertGreaterThan(0, $job->tries, $job::class.' doit limiter ses tentatives.');
                $this->assertGreaterThan(0, $job->timeout, $job::class.' doit définir un timeout.');
                $this->assertLessThanOrEqual($jobTimeout, $job->timeout, $job::class.' dépasse le timeout de son workload.');
                $this->assertProgressiveBackoff($job::class, $job->backoff);
            }
        }
    }

    /**
     * @return array<string, list<object>>
     */
    private function activeJobsByWorkload(): array
    {
        $batch = new AiImportBatch;
        $batch->setAttribute('id', 1);

        return [
            'ai-imports' => [
                new AnalyzePtaImportBatch($batch),
                new AnalyzePasPaoPtaWithAiJob(1),
                new ValidateImportRowsJob(1),
            ],
            'exports' => [
                new GenerateReportJob(1, 'pdf'),
            ],
            'notifications' => [
                new SendBrevoNotificationEmailsJob('action_assigned', [1], []),
            ],
        ];
    }

    /**
     * @param  list<int>  $backoff
     */
    private function assertProgressiveBackoff(string $jobClass, array $backoff): void
    {
        $this->assertNotEmpty($backoff, $jobClass.' doit définir un backoff progressif.');

        $previousDelay = 0;
        foreach ($backoff as $delay) {
            $this->assertGreaterThan($previousDelay, $delay, $jobClass.' doit augmenter le délai entre les tentatives.');
            $previousDelay = $delay;
        }
    }
}
