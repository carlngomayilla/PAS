<?php

namespace Tests\Feature;

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
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
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

    public function test_rec14_brevo_failure_does_not_block_internal_notification(): void
    {
        /*
         * Activation du canal Brevo pour ce test.
         * Important : on ne doit PAS utiliser Notification::fake() ici,
         * sinon Laravel n'exécute pas les vrais canaux de notification
         * et BrevoEmailChannel ne peut pas écrire dans brevo_email_log.
         */
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
            'type' => \App\Notifications\WorkspaceModuleNotification::class,
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
        $this->assertStringContainsString(
            'Brevo SMTP unreachable',
            (string) $log->error_message
        );
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
            \App\Notifications\WorkspaceModuleNotification::class
        );

        $this->assertDatabaseMissing('brevo_email_log', [
            'user_id' => $fixture['agent']->id,
            'event_type' => 'action_assigned',
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