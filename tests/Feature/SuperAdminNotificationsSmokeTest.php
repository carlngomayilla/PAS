<?php

namespace Tests\Feature;

use App\Mail\BrevoNotificationMail;
use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SuperAdminNotificationsSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_flow_workspace_and_notifications(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'name' => 'Super Administrateur PAS',
            'email' => 'superadmin@anbg.ga',
        ]);

        $this->actingAs($admin);

        $this->get(route('workspace.index'))->assertOk();
        $this->get(route('workspace.notifications.index'))->assertOk();
        $this->get(route('workspace.pas.index'))->assertOk();
        $this->get(route('workspace.pao.index'))->assertOk();
        $this->get(route('workspace.pta.index'))->assertOk();

        $service = app(WorkspaceNotificationService::class);
        $this->assertInstanceOf(
            WorkspaceNotificationService::class,
            $service,
            'WorkspaceNotificationService must be resolvable from the container.'
        );
    }

    public function test_action_assigned_notification_uses_personalized_french_label(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'name' => 'Super Administrateur PAS',
            'email' => 'superadmin@anbg.ga',
        ]);

        $direction = Direction::query()->create([
            'code' => 'TST',
            'libelle' => 'Direction Test',
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'TST-SRV',
            'libelle' => 'Service Test',
        ]);

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS Test',
            'periode_debut' => 2026,
            'periode_fin' => 2030,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PAO Test',
            'annee' => 2026,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA Test',
        ]);

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'responsable_id' => $agent->id,
            'libelle' => 'Cartographier les zones humides du Bassin de l\'Ogooué',
        ]);

        Notification::fake();

        $svc = app(WorkspaceNotificationService::class);
        $svc->notifyActionAssigned($action, $admin);

        Notification::assertSentTo(
            $agent,
            WorkspaceModuleNotification::class,
            function (WorkspaceModuleNotification $notification) use ($agent): bool {
                $payload = $notification->toArray($agent);

                return ($payload['title'] ?? null) === 'Nouvelle action attribuée'
                    && str_contains((string) ($payload['message'] ?? ''), 'Cartographier les zones humides')
                    && str_contains((string) ($payload['message'] ?? ''), 'été attribuée')
                    && str_contains((string) ($payload['message'] ?? ''), '« ');
            }
        );
    }

    public function test_super_admin_receives_personalized_brevo_email_when_action_is_assigned(): void
    {
        // Force le canal email en mode SMTP pour ce test (Mail::fake).
        config()->set('services.brevo.enabled', true);
        config()->set('services.brevo.transport', 'smtp');
        config()->set('mail.mailers.brevo', config('mail.mailers.smtp'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'name' => 'Super Administrateur PAS',
            'email' => 'superadmin@anbg.ga',
        ]);

        $direction = Direction::query()->create(['code' => 'TST-EMAIL', 'libelle' => 'Direction Email Test']);
        $service = Service::query()->create([
            'direction_id' => $direction->id, 'code' => 'TST-EMAIL-SRV', 'libelle' => 'Service Email Test',
        ]);

        // L'action est attribuée au Super Admin -> il reçoit la notif (canal email actif).
        $pas = Pas::query()->create([
            'titre' => 'PAS Test Email',
            'periode_debut' => 2026,
            'periode_fin' => 2030,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id, 'direction_id' => $direction->id, 'service_id' => $service->id,
            'titre' => 'PAO Test Email', 'annee' => 2026,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id, 'direction_id' => $direction->id, 'service_id' => $service->id,
            'titre' => 'PTA Test Email',
        ]);
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'responsable_id' => $admin->id,
            'libelle' => 'Action stratégique pour superadmin',
        ]);

        Mail::fake();
        // On contourne Notification::fake pour laisser le canal in_app + le canal email s'exécuter.

        app(WorkspaceNotificationService::class)->notifyActionAssigned($action, $admin);

        Mail::assertSent(
            BrevoNotificationMail::class,
            function (BrevoNotificationMail $mail) use ($admin): bool {
                if (! $mail->hasTo($admin->email)) {
                    return false;
                }

                $envelope = $mail->envelope();

                return str_contains((string) $envelope->subject, '[ANBG]')
                    && str_contains((string) $envelope->subject, 'attribuée')
                    && str_contains($mail->title, 'Nouvelle action attribuée')
                    && str_contains($mail->message, 'Action stratégique pour superadmin')
                    && str_contains($mail->message, '« ');
            }
        );
    }

    public function test_super_admin_email_dispatched_via_brevo_http_api_when_transport_api(): void
    {
        // Mode API HTTP : pas de SMTP, pas de restriction d'IP.
        config()->set('services.brevo.enabled', true);
        config()->set('services.brevo.transport', 'api');
        config()->set('services.brevo.api_key', 'xkeysib-test-fake-key');
        config()->set('services.brevo.api_endpoint', 'https://api.brevo.com/v3/smtp/email');
        config()->set('services.brevo.from.address', 'test@anbg.ga');
        config()->set('services.brevo.from.name', 'ANBG · Test');

        $admin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'name' => 'Super Administrateur PAS',
            'email' => 'superadmin@anbg.ga',
        ]);

        $direction = Direction::query()->create(['code' => 'TST-API', 'libelle' => 'Direction API Test']);
        $service = Service::query()->create([
            'direction_id' => $direction->id, 'code' => 'TST-API-SRV', 'libelle' => 'Service API Test',
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS Test API', 'periode_debut' => 2026, 'periode_fin' => 2030,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id, 'direction_id' => $direction->id, 'service_id' => $service->id,
            'titre' => 'PAO Test API', 'annee' => 2026,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id, 'direction_id' => $direction->id, 'service_id' => $service->id,
            'titre' => 'PTA Test API',
        ]);
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'responsable_id' => $admin->id,
            'libelle' => 'Action super admin via API HTTP',
        ]);

        // Fake l'endpoint Brevo : retourne 201 Created (réponse classique de l'API Brevo).
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'fake-uuid-1234'], 201),
        ]);

        app(WorkspaceNotificationService::class)->notifyActionAssigned($action, $admin);

        Http::assertSent(function (HttpRequest $request) use ($admin): bool {
            if ($request->url() !== 'https://api.brevo.com/v3/smtp/email') {
                return false;
            }

            if (! $request->hasHeader('api-key', 'xkeysib-test-fake-key')) {
                return false;
            }

            $body = $request->data();

            return ($body['sender']['email'] ?? null) === 'test@anbg.ga'
                && ($body['to'][0]['email'] ?? null) === $admin->email
                && str_contains((string) ($body['subject'] ?? ''), '[ANBG]')
                && str_contains((string) ($body['subject'] ?? ''), 'attribuée')
                && str_contains((string) ($body['htmlContent'] ?? ''), 'Action super admin via API HTTP')
                && in_array('action_assigned', (array) ($body['tags'] ?? []), true);
        });
    }
}
