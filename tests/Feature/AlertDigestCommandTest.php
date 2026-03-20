<?php

namespace Tests\Feature;

use App\Mail\AlertDigestMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class AlertDigestCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_alert_digest_command_sends_emails_to_operational_profiles_with_alerts(): void
    {
        Mail::fake();
        $admin = $this->createAdminUser();

        $this->artisan('alertes:notifier --limit=10')
            ->assertSuccessful();

        Mail::assertSent(AlertDigestMail::class, function (AlertDigestMail $mail) use ($admin): bool {
            return $mail->hasTo($admin->email);
        });

        Mail::assertSent(AlertDigestMail::class, function (AlertDigestMail $mail): bool {
            return $mail->hasTo('ingrid@anbg.ga');
        });

        Mail::assertNotSent(AlertDigestMail::class, function (AlertDigestMail $mail): bool {
            return $mail->hasTo('loick.adan@anbg.ga');
        });
    }

    public function test_alert_digest_command_dry_run_does_not_send_emails(): void
    {
        Mail::fake();

        $this->artisan('alertes:notifier --dry-run')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_alert_digest_command_creates_database_notifications_for_profiles_with_alerts(): void
    {
        Mail::fake();
        $admin = $this->createAdminUser();

        $this->artisan('alertes:notifier --limit=10')
            ->assertSuccessful();

        $dg = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();
        $cabinet = User::query()->where('email', 'loick.adan@anbg.ga')->firstOrFail();

        $adminNotification = $admin->notifications()->latest()->first();
        $dgNotification = $dg->notifications()->latest()->first();

        $this->assertNotNull($adminNotification);
        $this->assertNotNull($dgNotification);
        $this->assertSame('alertes', (string) ($adminNotification?->data['module'] ?? ''));
        $this->assertSame('alert_digest', (string) ($adminNotification?->data['meta']['event'] ?? ''));
        $this->assertSame('alertes', (string) ($dgNotification?->data['module'] ?? ''));
        $this->assertSame('alert_digest', (string) ($dgNotification?->data['meta']['event'] ?? ''));
        $this->assertSame(0, $cabinet->notifications()->count());
    }

    public function test_alert_digest_command_does_not_duplicate_daily_database_notification(): void
    {
        Mail::fake();
        $admin = $this->createAdminUser();

        $this->artisan('alertes:notifier --limit=10')
            ->assertSuccessful();

        $this->artisan('alertes:notifier --limit=10')
            ->assertSuccessful();

        $todayDigestCount = $admin->notifications()
            ->whereDate('created_at', now()->toDateString())
            ->get()
            ->filter(static function ($notification): bool {
                return (string) ($notification->data['module'] ?? '') === 'alertes'
                    && (string) ($notification->data['meta']['event'] ?? '') === 'alert_digest';
            })
            ->count();

        $this->assertSame(1, $todayDigestCount);
    }
}
