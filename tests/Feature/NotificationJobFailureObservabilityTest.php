<?php

namespace Tests\Feature;

use App\Jobs\NotifyImportedParametreActionsJob;
use App\Jobs\SendBrevoNotificationEmailsJob;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NotificationJobFailureObservabilityTest extends TestCase
{
    public function test_brevo_job_failure_logs_only_safe_structured_context(): void
    {
        $secret = 'api-key=never-log-this-value';
        $loggedContext = null;

        Log::shouldReceive('error')
            ->once()
            ->with(
                'Brevo notification email job failed.',
                Mockery::on(static function (array $context) use (&$loggedContext): bool {
                    $loggedContext = $context;

                    return true;
                })
            );

        $job = new SendBrevoNotificationEmailsJob(
            'action_assigned',
            [7, 7, 0, -4, 11],
            ['message' => $secret, 'api_key' => $secret]
        );

        $job->failed(new RuntimeException($secret));

        $this->assertSame([
            'event' => 'action_assigned',
            'recipient_count' => 2,
            'exception_type' => RuntimeException::class,
        ], $loggedContext);
        $this->assertStringNotContainsString(
            $secret,
            json_encode($loggedContext, JSON_THROW_ON_ERROR)
        );
    }

    public function test_import_notification_job_failure_logs_only_identifiers_and_counts(): void
    {
        $secret = 'database-password=never-log-this-value';
        $loggedContext = null;

        Log::shouldReceive('error')
            ->once()
            ->with(
                'Imported parameter action notification job failed.',
                Mockery::on(static function (array $context) use (&$loggedContext): bool {
                    $loggedContext = $context;

                    return true;
                })
            );

        $job = new NotifyImportedParametreActionsJob([31, 31, 45, 0, -9], 18);

        $job->failed(new RuntimeException($secret));

        $this->assertSame([
            'actor_id' => 18,
            'action_count' => 2,
            'exception_type' => RuntimeException::class,
        ], $loggedContext);
        $this->assertStringNotContainsString(
            $secret,
            json_encode($loggedContext, JSON_THROW_ON_ERROR)
        );
    }
}
