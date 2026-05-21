<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Analytics\ReportingAnalyticsService;
use App\Services\NotificationPolicySettings;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Couvre les patchs de la sous-phase 2.D :
 *   - A16 : GenerateReportJob re-verifie l autorisation (cible indirecte ici :
 *     on s assure que la methode `stillAuthorizedToExport` existe sur le job).
 *   - A21 : 3 nouveaux events declares dans NotificationPolicySettings.
 *   - A25 : ReportingAnalyticsService expose des constantes de limite et la
 *     meta-info `truncation` dans son payload `details`.
 *   - A27 : SessionController logue en critical (pas warning) en cas d echec
 *     d audit (validation indirecte via la presence du marqueur dans le code).
 */
class Phase2DCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a21_new_events_are_registered(): void
    {
        /** @var NotificationPolicySettings $policy */
        $policy = app(NotificationPolicySettings::class);
        $events = array_keys($policy->eventDefinitions());

        $this->assertContains('justificatif_manquant', $events);
        $this->assertContains('pao_en_retard', $events);
        $this->assertContains('validation_bloquee_5j', $events);
    }

    public function test_a25_reporting_analytics_exposes_explicit_limits(): void
    {
        $this->assertSame(200, ReportingAnalyticsService::DETAIL_LIMIT_LATE_ACTIONS);
        $this->assertSame(200, ReportingAnalyticsService::DETAIL_LIMIT_KPI_BELOW_THRESHOLD);
        $this->assertSame(300, ReportingAnalyticsService::DETAIL_LIMIT_STRUCTURE);
    }

    public function test_a16_generate_report_job_has_authorization_recheck(): void
    {
        $reflection = new \ReflectionClass(\App\Jobs\GenerateReportJob::class);
        $this->assertTrue(
            $reflection->hasMethod('stillAuthorizedToExport'),
            'A16 — GenerateReportJob doit re-verifier l autorisation au runtime via stillAuthorizedToExport().'
        );
    }

    public function test_a27_authentication_audit_logger_uses_critical_in_session_controller(): void
    {
        $file = file_get_contents(base_path('app/Http/Controllers/SessionController.php'));
        $this->assertStringContainsString(
            'Log::critical(\'Authentication audit could not be recorded (A27).\'',
            $file,
            'A27 — La perte d audit d authentification doit etre loggee en critical, pas warning.'
        );
    }

    public function test_a26_alert_digest_command_uses_queue(): void
    {
        $file = file_get_contents(base_path('app/Console/Commands/SendAlertDigestCommand.php'));
        $this->assertStringContainsString(
            '->queue((new AlertDigestMail',
            $file,
            'A26 — SendAlertDigestCommand doit utiliser Mail::to()->queue() au lieu de ->send().'
        );
    }
}
