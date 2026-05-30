<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActionNotificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_action_validation_workflow_sends_notifications_to_expected_recipients(): void
    {
        $fixture = $this->createActionFixture();
        $action = $fixture['action'];

        $action->forceFill(['quantite_realisee' => 100])->save();
        $action = app(ActionTrackingService::class)->submitClosureForReview($action->fresh(), [
            'date_fin_reelle' => '2026-01-07',
            'rapport_final' => 'Travail termine',
        ], $fixture['agent']);
        app(WorkspaceNotificationService::class)->notifyActionSubmittedToChef($action, $fixture['agent']);

        $this->assertNotNull($fixture['service_user']->fresh()->notifications->first(
            fn ($notification) => ($notification->data['title'] ?? null) === 'Action en attente de validation'
        ));
        $this->assertNull($fixture['direction_user']->fresh()->notifications->first(
            fn ($notification) => ($notification->data['title'] ?? null) === 'Action en attente de validation'
        ));
        $this->assertNull($fixture['agent']->fresh()->notifications->first(
            fn ($notification) => ($notification->data['title'] ?? null) === 'Action en attente de validation'
        ));

        Sanctum::actingAs($fixture['service_user']);
        $this->postJson('/api/v1/actions/'.$action->id.'/review', [
            'decision_validation' => 'valider',
            'motif_validation_chef' => 'Validation chef',
            'validation_sans_correction' => 1,
        ])->assertOk();

        // validation_sans_correction=1 -> notifyActionFinalizedByChef -> titre "Action finalisée par le chef"
        $this->assertNotNull($fixture['direction_user']->fresh()->notifications->first(
            fn ($notification) => in_array(($notification->data['title'] ?? null), ['Action finalisée par le chef', 'Action validée par le chef de service', 'Action validée par le chef'], true)
        ));
        $this->assertNotNull($fixture['agent']->fresh()->notifications->first(
            fn ($notification) => in_array(($notification->data['title'] ?? null), ['Action finalisée par le chef', 'Votre action a été validée', 'Action validée par le chef de service', 'Action validée par le chef'], true)
        ));

        Sanctum::actingAs($fixture['direction_user']);
        $this->postJson('/api/v1/actions/'.$action->id.'/review-direction', [
            'decision_validation' => 'valider',
            'motif_validation_chef' => 'Validation finale',
        ])->assertForbidden();

        $this->assertNull($fixture['agent']->fresh()->notifications->first(
            fn ($notification) => ($notification->data['title'] ?? null) === 'Action validée par la direction'
        ));
        $this->assertNull($fixture['service_user']->fresh()->notifications->first(
            fn ($notification) => ($notification->data['title'] ?? null) === 'Action validée par la direction'
        ));
    }

    /**
     * @return array{action: Action, agent: User, service_user: User, direction_user: User}
     */
    private function createActionFixture(): array
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

        $serviceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $directionUser = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $direction->id,
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

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action notification test',
            'description' => 'Action test notification',
            'type_cible' => 'quantitative',
            'unite_cible' => 'taches',
            'quantite_cible' => 100,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-14',
            'date_echeance' => '2026-01-14',
            'responsable_id' => $agent->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
        ]);

        app(ActionTrackingService::class)->initializeActionTracking($action, $serviceUser);

        return [
            'action' => $action->fresh(),
            'agent' => $agent,
            'service_user' => $serviceUser,
            'direction_user' => $directionUser,
        ];
    }
}
