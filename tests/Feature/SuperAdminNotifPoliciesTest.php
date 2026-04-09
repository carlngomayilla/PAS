<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\JournalAudit;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\NotificationPolicySettings;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminNotifPoliciesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_define_timeline_rules_for_deadline_alerts(): void
    {
        $fixture = $this->createActionFixture();

        app(ActionTrackingService::class)->refreshActionMetrics($fixture, Carbon::parse('2026-01-11'));
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $fixture->id,
            'type_evenement' => 'alerte_temporelle_j_minus_3',
            'niveau' => 'warning',
            'cible_role' => 'service',
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($fixture->fresh(), Carbon::parse('2026-01-21'));
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $fixture->id,
            'type_evenement' => 'alerte_temporelle_j_plus_7',
            'niveau' => 'critical',
            'cible_role' => 'direction',
        ]);
    }

    public function test_notification_event_templates_and_channels_are_applied(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $fixture = $this->createActionFixture();

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.notifications.update'), [
                'event_action_assigned_enabled' => '1',
                'event_action_assigned_title' => 'Attribution pilotee',
                'event_action_assigned_message' => 'Traitement de {action_label} pour {actor_name}',
                'event_action_assigned_channels' => ['in_app', 'audit'],
            ])
            ->assertRedirect(route('workspace.super-admin.notifications.edit'));

        app(WorkspaceNotificationService::class)->notifyActionAssigned($fixture, $superAdmin);

        $notification = $fixture->responsable->fresh()->notifications()->latest()->first();
        $this->assertNotNull($notification);
        $this->assertSame('Attribution pilotee', $notification->data['title'] ?? null);
        $this->assertStringContainsString('Action notification test', (string) ($notification->data['message'] ?? ''));

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'actions',
            'action' => 'notification_action_assigned',
        ]);
    }

    private function createActionFixture(): Action
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-NOTIF',
            'libelle' => 'Direction Notification',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-NOTIF',
            'libelle' => 'Service Notification',
            'actif' => true,
        ]);

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'AG-NOTIF-01',
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS notifications',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-NOTIF',
            'libelle' => 'Axe notifications',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-NOTIF',
            'libelle' => 'Objectif notifications',
            'ordre' => 1,
        ]);

        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO notifications',
            'statut' => 'brouillon',
        ]);

        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA notifications',
            'statut' => 'brouillon',
        ]);

        return Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action notification test',
            'description' => 'Action test notification',
            'type_cible' => 'quantitative',
            'unite_cible' => 'taches',
            'quantite_cible' => 100,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'date_echeance' => '2026-01-14',
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $agent->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
        ]);
    }
}
