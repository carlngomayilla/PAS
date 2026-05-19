<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\ObjectifOperationnel;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\UniteDg;
use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use App\Services\Actions\ActionTrackingService;
use App\Services\Notifications\WorkspaceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BusinessWorkflowNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pao_and_pta_hierarchy_notifications_target_the_operational_chain(): void
    {
        Notification::fake();

        $fixture = $this->createPlanningFixture();
        $notificationService = app(WorkspaceNotificationService::class);

        $notificationService->notifyPaoTransmittedToServices($fixture['pao'], $fixture['direction_user']);
        Notification::assertSentTo($fixture['service_user'], WorkspaceModuleNotification::class, function (WorkspaceModuleNotification $notification) use ($fixture): bool {
            $data = $notification->toArray($fixture['service_user']);

            return ($data['title'] ?? null) === 'Nouveau PAO reçu'
                && (int) ($data['user_id_declencheur'] ?? 0) === (int) $fixture['direction_user']->id
                && (int) ($data['direction_id'] ?? 0) === (int) $fixture['direction']->id
                && (int) ($data['service_id'] ?? 0) === (int) $fixture['service']->id;
        });

        $notificationService->notifyPaoUpdatedForServices($fixture['pao'], $fixture['direction_user']);
        Notification::assertSentTo($fixture['service_user'], WorkspaceModuleNotification::class, fn (WorkspaceModuleNotification $notification): bool => $notification->toArray($fixture['service_user'])['title'] === 'PAO mis à jour');

        $notificationService->notifyPtaCreatedToDirection($fixture['pta'], $fixture['service_user']);
        Notification::assertSentTo($fixture['direction_user'], WorkspaceModuleNotification::class, fn (WorkspaceModuleNotification $notification): bool => $notification->toArray($fixture['direction_user'])['title'] === 'Nouveau PTA créé');

        $notificationService->notifyPtaSubmittedForValidation($fixture['pta'], $fixture['service_user']);
        Notification::assertSentTo($fixture['direction_user'], WorkspaceModuleNotification::class, fn (WorkspaceModuleNotification $notification): bool => $notification->toArray($fixture['direction_user'])['title'] === 'PTA soumis pour validation');

        $notificationService->notifyPtaReviewedByDirection($fixture['pta'], true, $fixture['direction_user']);
        Notification::assertSentTo($fixture['service_user'], WorkspaceModuleNotification::class, fn (WorkspaceModuleNotification $notification): bool => $notification->toArray($fixture['service_user'])['title'] === 'PTA validé');

        $notificationService->notifyPtaReviewedByDirection($fixture['pta'], false, $fixture['direction_user']);
        Notification::assertSentTo($fixture['service_user'], WorkspaceModuleNotification::class, fn (WorkspaceModuleNotification $notification): bool => $notification->toArray($fixture['service_user'])['title'] === 'PTA rejeté');
    }

    public function test_agent_and_unit_dg_action_notifications_target_service_and_unit_chiefs(): void
    {
        Notification::fake();

        $fixture = $this->createPlanningFixture();
        $unit = UniteDg::query()->create([
            'direction_id' => $fixture['direction']->id,
            'code' => UniteDg::CODE_UCAS,
            'libelle' => 'Unité test',
            'actif' => true,
            'portee_globale' => false,
        ]);
        $unitChief = User::factory()->create([
            'role' => User::ROLE_CHEF_UNITE_UCAS,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'unite_dg_id' => $unit->id,
            'password_changed_at' => now(),
        ]);
        $unit->forceFill(['chef_user_id' => $unitChief->id])->save();

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'unite_dg_id' => $unit->id,
            'password_changed_at' => now(),
        ]);

        $action = Action::query()->create([
            'pta_id' => $fixture['pta']->id,
            'pao_id' => $fixture['pao']->id,
            'objectif_operationnel_id' => $fixture['objectif_operationnel']->id,
            'unite_dg_id' => $unit->id,
            'libelle' => 'Action unité DG',
            'description' => 'Action test unité DG',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-31',
            'date_echeance' => '2026-01-31',
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $agent->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
        ]);
        $sousAction = SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $agent->id,
            'libelle' => 'Sous-action test',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-15',
            'statut' => 'effectuee',
            'est_effectuee' => true,
            'taux_execution' => 100,
        ]);

        $notificationService = app(WorkspaceNotificationService::class);
        $notificationService->notifyActionSubmittedToChef($action, $agent);
        $notificationService->notifySubActionCreated($action, $sousAction, $agent);
        $notificationService->notifySubActionCompleted($action, $sousAction, $agent);
        $notificationService->notifyJustificatifAdded($action, $agent, $sousAction, 'sous_action');

        foreach ([$fixture['service_user'], $unitChief] as $recipient) {
            Notification::assertSentTo($recipient, WorkspaceModuleNotification::class, fn (WorkspaceModuleNotification $notification): bool => $notification->toArray($recipient)['title'] === 'Action soumise pour validation');
            Notification::assertSentTo($recipient, WorkspaceModuleNotification::class, fn (WorkspaceModuleNotification $notification): bool => $notification->toArray($recipient)['title'] === 'Nouvelle sous-action créée');
            Notification::assertSentTo($recipient, WorkspaceModuleNotification::class, fn (WorkspaceModuleNotification $notification): bool => $notification->toArray($recipient)['title'] === 'Action soumise pour vérification');
            Notification::assertSentTo($recipient, WorkspaceModuleNotification::class, fn (WorkspaceModuleNotification $notification): bool => $notification->toArray($recipient)['title'] === 'Justificatif ajouté');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function createPlanningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-BIZ',
            'libelle' => 'Direction métier',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-BIZ',
            'libelle' => 'Service métier',
            'actif' => true,
        ]);
        $directionUser = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $direction->id,
            'password_changed_at' => now(),
        ]);
        $serviceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS métier',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-BIZ',
            'libelle' => 'Axe métier',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-BIZ',
            'libelle' => 'Objectif métier',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO métier',
            'objectif_operationnel' => 'Objectif opérationnel',
            'statut' => 'brouillon',
        ]);
        $objectifOperationnel = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axe->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif opérationnel',
            'echeance' => '2026-12-31',
            'statut' => 'brouillon',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectifOperationnel->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA métier',
            'statut' => 'brouillon',
        ]);

        return [
            'direction' => $direction,
            'service' => $service,
            'direction_user' => $directionUser,
            'service_user' => $serviceUser,
            'pas' => $pas,
            'pao' => $pao,
            'objectif_operationnel' => $objectifOperationnel,
            'pta' => $pta,
        ];
    }
}
