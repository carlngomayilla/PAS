<?php

namespace Tests\Feature;

use App\Jobs\SendBrevoNotificationEmailsJob;
use App\Models\Action;
use App\Models\Direction;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use App\Services\Notifications\BrevoMailService;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Canal email Brevo — règle métier v1.1 :
 * - L'envoi email est complémentaire au canal in_app / database.
 * - Un échec d'envoi Brevo ne doit jamais bloquer l'action métier.
 * - Chaque tentative est journalisée dans brevo_email_log avec son statut.
 *
 * Couvre REC-14 du cahier des charges v1.1.
 */
class BrevoEmailChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_brevo_notification_job_uses_configured_queue_target(): void
    {
        config()->set('services.brevo.queue.connection', 'database');
        config()->set('services.brevo.queue.name', 'default');

        $job = new SendBrevoNotificationEmailsJob('action_assigned', [1, 2], [
            'title' => 'Test',
            'message' => 'Payload',
        ]);

        $this->assertSame('database', $job->connection);
        $this->assertSame('default', $job->queue);
    }

    public function test_web_dispatch_creates_one_job_per_unique_recipient(): void
    {
        $this->enableBrevoSmtp();
        config()->set('services.brevo.queue.connection', 'database');
        config()->set('services.brevo.queue.name', 'notifications');
        Bus::fake();

        $first = User::factory()->create(['password_changed_at' => now()]);
        $second = User::factory()->create(['password_changed_at' => now()]);
        $previousEnvironment = $this->app->environment();

        try {
            $this->app->instance('env', 'production');

            app(BrevoMailService::class)->dispatch(
                'action_assigned',
                collect([$first, $second, $first]),
                ['title' => 'Affectation', 'message' => 'Notification unitaire']
            );
        } finally {
            $this->app->instance('env', $previousEnvironment);
        }

        $jobs = Bus::dispatched(SendBrevoNotificationEmailsJob::class)->values();

        $this->assertCount(2, $jobs);
        $this->assertTrue($jobs->every(
            fn (SendBrevoNotificationEmailsJob $job): bool => count($job->recipientIds) === 1
                && $job->connection === 'database'
                && $job->queue === 'notifications'
        ));
        $this->assertSame(
            collect([$first->id, $second->id])->sort()->values()->all(),
            $jobs->flatMap->recipientIds->sort()->values()->all()
        );
    }

    public function test_production_console_dispatch_uses_the_queue_instead_of_sending_synchronously(): void
    {
        $this->enableBrevoSmtp();
        Bus::fake();
        Mail::shouldReceive('mailer')->never();

        $recipient = User::factory()->create(['password_changed_at' => now()]);
        $previousEnvironment = $this->app->environment();
        $consoleProperty = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $previousConsoleState = $consoleProperty->getValue($this->app);

        try {
            $this->app->instance('env', 'production');
            $consoleProperty->setValue($this->app, true);

            app(BrevoMailService::class)->dispatch(
                'action_assigned',
                collect([$recipient]),
                ['title' => 'Affectation', 'message' => 'Notification depuis une commande']
            );
        } finally {
            $this->app->instance('env', $previousEnvironment);
            $consoleProperty->setValue($this->app, $previousConsoleState);
        }

        Bus::assertDispatched(
            SendBrevoNotificationEmailsJob::class,
            fn (SendBrevoNotificationEmailsJob $job): bool => $job->recipientIds === [$recipient->id]
        );
    }

    public function test_legacy_batch_is_split_before_delivery_and_preserves_queue_target(): void
    {
        Queue::fake();
        Mail::shouldReceive('mailer')->never();

        $brevoMailService = Mockery::mock(BrevoMailService::class);
        $brevoMailService->shouldNotReceive('deliverQueued');
        $legacyJob = (new SendBrevoNotificationEmailsJob(
            'action_assigned',
            [42, 17, 42, 0],
            ['title' => 'Affectation', 'message' => 'Ancien lot']
        ))
            ->onConnection('legacy-connection')
            ->onQueue('legacy-notifications');

        $legacyJob->handle($brevoMailService, app(QueueFactory::class));

        $jobs = Queue::pushed(SendBrevoNotificationEmailsJob::class)->values();

        $this->assertCount(2, $jobs);
        $this->assertSame([[17], [42]], $jobs->pluck('recipientIds')->sort()->values()->all());
        $this->assertTrue($jobs->every(
            fn (SendBrevoNotificationEmailsJob $job): bool => $job->connection === 'legacy-connection'
                && $job->queue === 'legacy-notifications'
        ));
        Queue::assertPushedOn('legacy-notifications', SendBrevoNotificationEmailsJob::class);
        Queue::assertPushed(SendBrevoNotificationEmailsJob::class, 2);
    }

    public function test_legacy_batch_bulk_failure_is_sanitized_and_retried(): void
    {
        $brevoMailService = Mockery::mock(BrevoMailService::class);
        $brevoMailService->shouldNotReceive('deliverQueued');
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('bulk')
            ->once()
            ->andThrow(new RuntimeException('redis-credential-must-not-escape'));
        $queues = Mockery::mock(QueueFactory::class);
        $queues->shouldReceive('connection')
            ->once()
            ->with('redis')
            ->andReturn($queue);
        $legacyJob = (new SendBrevoNotificationEmailsJob(
            'action_assigned',
            [42, 17],
            ['title' => 'Affectation', 'message' => 'Ancien lot']
        ))->onConnection('redis');

        try {
            $legacyJob->handle($brevoMailService, $queues);
            $this->fail('Un échec du bulk doit faire réessayer le job parent.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Legacy Brevo batch queue fan-out failed.', $exception->getMessage());
            $this->assertStringNotContainsString('credential', $exception->getMessage());
        }

        $this->assertSame(3, $legacyJob->tries);
        $this->assertSame([10, 60], $legacyJob->backoff);
    }

    public function test_queue_job_skips_recipients_that_became_inactive_or_suspended(): void
    {
        $inactive = User::factory()->create([
            'is_active' => false,
            'suspended_until' => null,
        ]);
        $suspended = User::factory()->create([
            'is_active' => true,
            'suspended_until' => now()->addHour(),
        ]);
        $brevoMailService = Mockery::mock(BrevoMailService::class);
        $brevoMailService->shouldNotReceive('deliverQueued');
        $queues = app(QueueFactory::class);

        foreach ([$inactive, $suspended] as $recipient) {
            $job = new SendBrevoNotificationEmailsJob(
                'action_assigned',
                [(int) $recipient->id],
                ['title' => 'Affectation', 'message' => 'Notification devenue interdite']
            );

            $job->handle($brevoMailService, $queues);
        }
    }

    public function test_rec14_brevo_failure_does_not_block_internal_notification(): void
    {
        /*
         * Activation du canal Brevo pour ce test.
         * Important : on ne doit PAS utiliser Notification::fake() ici,
         * sinon Laravel n'exécute pas les vrais canaux de notification
         * et BrevoEmailChannel ne peut pas écrire dans brevo_email_log.
         */
        $this->enableBrevoSmtp();

        /*
         * Simulation d'une panne Brevo.
         * Le workflow métier ne doit pas planter.
         * Le canal Brevo doit attraper l'exception et écrire status = failed.
         */
        Mail::shouldReceive('mailer')
            ->with('brevo')
            ->andReturnSelf();

        Mail::shouldReceive('to')
            ->andReturnSelf();

        Mail::shouldReceive('send')
            ->andThrow(new RuntimeException('Brevo SMTP unreachable (test)'));

        $fixture = $this->createPlanningFixture();

        $service = app(WorkspaceNotificationService::class);

        $action = Action::query()->create([
            'pta_id' => $fixture['pta']->id,
            'pao_id' => $fixture['pao']->id,
            'libelle' => 'Action test Brevo',
            'description' => 'Vérification fail-safe canal email',
            'type_cible' => 'qualitative',
            'resultat_attendu' => 'OK',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'date_echeance' => '2026-12-31',
            'responsable_id' => $fixture['agent']->id,
            'financement_requis' => false,
        ]);

        /*
         * L'appel ne doit jamais propager l'exception Brevo.
         * Si Brevo échoue, la notification interne doit rester créée.
         */
        $service->notifyActionAssigned($action, $fixture['chef']);

        /*
         * 1) La notification interne doit exister.
         *
         * Cette assertion suppose que WorkspaceModuleNotification utilise
         * le canal Laravel database pour les notifications internes.
         */
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $fixture['agent']->id,
            'type' => WorkspaceModuleNotification::class,
        ]);

        /*
         * 2) Une trace d'échec Brevo doit exister.
         */
        $this->assertDatabaseHas('brevo_email_log', [
            'user_id' => $fixture['agent']->id,
            'event_type' => 'action_assigned',
            'recipient_email' => $fixture['agent']->email,
            'status' => 'failed',
        ]);

        $log = DB::table('brevo_email_log')
            ->where('user_id', $fixture['agent']->id)
            ->where('event_type', 'action_assigned')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Brevo delivery failed (RuntimeException).', (string) $log->error_message);
        $this->assertStringNotContainsString('Brevo SMTP unreachable', (string) $log->error_message);
    }

    public function test_brevo_disabled_by_default_skips_email_channel_silently(): void
    {
        /*
         * Ici Brevo est désactivé.
         * On peut utiliser Notification::fake() parce qu'on ne veut pas tester
         * l'exécution réelle du canal Brevo.
         */
        config()->set('services.brevo.enabled', false);

        Mail::shouldReceive('mailer')->never();

        $fixture = $this->createPlanningFixture();

        Notification::fake();

        $action = Action::query()->create([
            'pta_id' => $fixture['pta']->id,
            'pao_id' => $fixture['pao']->id,
            'libelle' => 'Action sans Brevo',
            'description' => 'Vérification opt-out',
            'type_cible' => 'qualitative',
            'resultat_attendu' => 'OK',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'date_echeance' => '2026-12-31',
            'responsable_id' => $fixture['agent']->id,
            'financement_requis' => false,
        ]);

        app(WorkspaceNotificationService::class)
            ->notifyActionAssigned($action, $fixture['chef']);

        Notification::assertSentTo(
            $fixture['agent'],
            WorkspaceModuleNotification::class
        );

        $this->assertDatabaseMissing('brevo_email_log', [
            'user_id' => $fixture['agent']->id,
            'event_type' => 'action_assigned',
        ]);
    }

    public function test_queue_job_propagates_a_sanitized_delivery_failure_for_retry(): void
    {
        $this->enableBrevoSmtp();
        Log::spy();

        Mail::shouldReceive('mailer')->with('brevo')->andReturnSelf();
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new RuntimeException('smtp-secret-must-not-escape'));

        $recipient = User::factory()->create(['password_changed_at' => now()]);
        $job = new SendBrevoNotificationEmailsJob('action_assigned', [$recipient->id], [
            'title' => 'Affectation',
            'message' => 'payload-secret-must-not-escape',
        ]);

        try {
            $job->handle(app(BrevoMailService::class), app(QueueFactory::class));
            $this->fail('Le worker doit recevoir une exception afin de réessayer le job.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Brevo delivery failed for 1 recipient(s).', $exception->getMessage());
            $this->assertStringNotContainsString('smtp-secret', $exception->getMessage());
            $this->assertStringNotContainsString('payload-secret', $exception->getMessage());
        }

        $this->assertDatabaseHas('brevo_email_log', [
            'user_id' => $recipient->id,
            'event_type' => 'action_assigned',
            'status' => 'failed',
            'error_message' => 'Brevo delivery failed (RuntimeException).',
        ]);
        $databaseMessage = (string) DB::table('brevo_email_log')
            ->where('user_id', $recipient->id)
            ->where('event_type', 'action_assigned')
            ->value('error_message');

        $this->assertStringNotContainsString('smtp-secret', $databaseMessage);
        $this->assertStringNotContainsString('payload-secret', $databaseMessage);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Brevo email send failed (non-blocking).',
                Mockery::on(static fn (array $context): bool => ($context['exception_type'] ?? null) === RuntimeException::class
                    && ! array_key_exists('exception', $context)
                    && ! array_key_exists('recipient_email', $context))
            );
    }

    public function test_enabled_brevo_without_api_key_forces_a_queued_delivery_retry(): void
    {
        config()->set('services.brevo.enabled', true);
        config()->set('services.brevo.transport', 'api');
        config()->set('services.brevo.api_key', '');

        $recipient = User::factory()->create(['password_changed_at' => now()]);
        $configuration = app(BrevoMailService::class)->configurationStatus();

        $this->assertTrue($configuration['enabled']);
        $this->assertFalse($configuration['configured']);
        $this->assertSame('api_key_missing', $configuration['issue']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Brevo email channel is enabled but not configured.');

        app(BrevoMailService::class)->deliverQueued(
            'action_assigned',
            collect([$recipient]),
            ['title' => 'Affectation', 'message' => 'Notification']
        );
    }

    public function test_retrying_one_failed_recipient_does_not_resend_a_successful_recipient(): void
    {
        $this->enableBrevoSmtp();

        $successfulRecipient = User::factory()->create([
            'email' => 'brevo-success@example.test',
            'password_changed_at' => now(),
        ]);
        $retryingRecipient = User::factory()->create([
            'email' => 'brevo-retry@example.test',
            'password_changed_at' => now(),
        ]);
        $currentRecipient = '';
        $attemptsByRecipient = [];

        Mail::shouldReceive('mailer')->with('brevo')->times(3)->andReturnSelf();
        Mail::shouldReceive('to')
            ->times(3)
            ->andReturnUsing(function (string $email) use (&$currentRecipient) {
                $currentRecipient = $email;

                return Mail::getFacadeRoot();
            });
        Mail::shouldReceive('send')
            ->times(3)
            ->andReturnUsing(function () use (&$attemptsByRecipient, &$currentRecipient, $retryingRecipient): void {
                $attemptsByRecipient[$currentRecipient] = ($attemptsByRecipient[$currentRecipient] ?? 0) + 1;

                if ($currentRecipient === $retryingRecipient->email
                    && $attemptsByRecipient[$currentRecipient] === 1) {
                    throw new RuntimeException('temporary-smtp-failure');
                }
            });

        $payload = ['title' => 'Affectation', 'message' => 'Relance isolée'];
        $successfulJob = new SendBrevoNotificationEmailsJob(
            'action_assigned',
            [$successfulRecipient->id],
            $payload
        );
        $retryingJob = new SendBrevoNotificationEmailsJob(
            'action_assigned',
            [$retryingRecipient->id],
            $payload
        );
        $service = app(BrevoMailService::class);
        $queues = app(QueueFactory::class);

        $successfulJob->handle($service, $queues);

        try {
            $retryingJob->handle($service, $queues);
            $this->fail('La première tentative du destinataire en échec doit être réessayée.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Brevo delivery failed for 1 recipient(s).', $exception->getMessage());
        }

        $retryingJob->handle($service, $queues);

        $this->assertSame(1, $attemptsByRecipient[$successfulRecipient->email] ?? 0);
        $this->assertSame(2, $attemptsByRecipient[$retryingRecipient->email] ?? 0);
        $this->assertSame(1, DB::table('brevo_email_log')
            ->where('user_id', $successfulRecipient->id)
            ->where('status', 'sent')
            ->count());
        $this->assertSame(0, DB::table('brevo_email_log')
            ->where('user_id', $successfulRecipient->id)
            ->where('status', 'failed')
            ->count());
        $this->assertSame(1, DB::table('brevo_email_log')
            ->where('user_id', $retryingRecipient->id)
            ->where('status', 'failed')
            ->count());
        $this->assertSame(1, DB::table('brevo_email_log')
            ->where('user_id', $retryingRecipient->id)
            ->where('status', 'sent')
            ->count());
    }

    private function enableBrevoSmtp(): void
    {
        config()->set('services.brevo.enabled', true);
        config()->set('services.brevo.transport', 'smtp');
        config()->set('services.brevo.mailer', 'brevo');
        config()->set('mail.mailers.brevo', [
            'transport' => 'smtp',
            'host' => 'smtp-relay.brevo.com',
            'port' => 587,
            'username' => 'fake',
            'password' => 'fake',
            'encryption' => 'tls',
        ]);
    }

    /**
     * @return array{
     *     direction: Direction,
     *     service: Service,
     *     agent: User,
     *     chef: User,
     *     pao: Pao,
     *     pta: Pta
     * }
     */
    private function createPlanningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-BRV',
            'libelle' => 'Direction Brevo',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SRV-BRV',
            'libelle' => 'Service Brevo',
            'actif' => true,
        ]);

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $chef = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS Brevo',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'actif',
        ]);

        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-BRV',
            'libelle' => 'Axe Brevo',
            'ordre' => 1,
        ]);

        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-BRV',
            'libelle' => 'Objectif Brevo',
            'date_echeance' => '2028-12-31',
            'ordre' => 1,
        ]);

        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO Brevo',
            'statut' => 'valide',
        ]);

        $objectifOp = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axe->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif opérationnel Brevo',
            'echeance' => '2026-12-31',
            'statut' => 'valide',
        ]);

        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectifOp->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA Brevo',
            'statut' => 'en_cours',
        ]);

        return compact(
            'direction',
            'service',
            'agent',
            'chef',
            'pao',
            'pta'
        );
    }
}
