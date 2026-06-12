<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Couvre A07 : si l envoi Notification ou la trace d audit echoue, le service
 * doit logger en `critical` sans casser le workflow metier appelant.
 */
class WorkspaceNotificationFailSafeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_failure_is_logged_and_does_not_throw(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        Notification::shouldReceive('sendNow')
            ->once()
            ->andThrow(new RuntimeException('queue connection refused'));

        Log::shouldReceive('critical')
            ->once()
            ->with(
                'Workspace notification dispatch failed (A07).',
                Mockery::on(static function (array $context): bool {
                    return ($context['module'] ?? null) === 'test_module'
                        && ($context['exception_class'] ?? null) === RuntimeException::class
                        && str_contains((string) ($context['exception_message'] ?? ''), 'queue connection refused');
                })
            );

        $service = app(WorkspaceNotificationService::class);
        $this->invokePrivate($service, 'dispatch', [
            collect([$user]),
            [
                'title' => 'Alerte critique',
                'message' => 'Test',
                'module' => 'test_module',
                'entity_type' => 'test_entity',
                'entity_id' => 42,
            ],
            null,
        ]);

        // Si on arrive ici, la methode n a pas leve d exception : le metier
        // appelant continuerait son traitement.
        $this->assertTrue(true);
    }

    public function test_audit_trace_failure_is_logged_and_does_not_throw(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        // On simule une indisponibilite de la table journal_audit pour que
        // JournalAudit::query()->create() echoue.
        DB::statement('DROP TABLE journal_audit');

        Log::shouldReceive('critical')
            ->once()
            ->with(
                'Audit trace notification failed (A07).',
                Mockery::on(static function (array $context): bool {
                    return ($context['event'] ?? null) === 'test_event'
                        && ($context['module'] ?? null) === 'test_module';
                })
            );

        $service = app(WorkspaceNotificationService::class);
        $this->invokePrivate($service, 'dispatchAuditTrace', [
            'test_event',
            collect([$user]),
            [
                'title' => 'Audit',
                'message' => 'X',
                'module' => 'test_module',
                'entity_type' => 'test_entity',
                'entity_id' => 7,
                'channels' => ['audit'],
            ],
            null,
        ]);

        $this->assertTrue(true);
    }

    private function invokePrivate(object $target, string $method, array $args): mixed
    {
        $reflection = new ReflectionClass($target);
        $reflectedMethod = $reflection->getMethod($method);
        $reflectedMethod->setAccessible(true);

        return $reflectedMethod->invokeArgs($target, $args);
    }
}
