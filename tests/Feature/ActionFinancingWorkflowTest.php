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
use Tests\TestCase;

class ActionFinancingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_daf_and_dg_can_process_action_financing_with_transparency_notifications(): void
    {
        $fixture = $this->createFixture();
        $action = $fixture['action'];

        $trackingService = app(ActionTrackingService::class);
        $trackingService->syncFinancingRequest($action, $fixture['service_user']);
        app(WorkspaceNotificationService::class)->notifyActionFinancingRequested($action->fresh(), $fixture['service_user']);

        $this->assertNotNull($fixture['daf_director']->fresh()->notifications()->first());

        $trackingService->reviewClosureByChef($action->fresh(), [
            'decision_validation' => 'valider',
            'motif_validation_chef' => 'Validation chef avant transmission DAF.',
        ], $fixture['service_user']);
        $action->refresh();
        $this->assertSame(Action::FINANCEMENT_SOUMIS_DAF, $action->financement_statut);

        $this->actingAs($fixture['daf_director'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Traitement DAF');

        $this->actingAs($fixture['daf_director'])
            ->post(route('workspace.actions.financement.daf', $action), [
                'decision_financement' => ActionTrackingService::FINANCEMENT_DECISION_VALIDER,
                'montant_valide' => 1750000,
                'reference_financement' => 'DAF-2026-001',
                'commentaire_financement' => 'Budget recevable pour accord DG.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(Action::FINANCEMENT_TRANSMIS_DG, $action->financement_statut);
        $this->assertSame((int) $fixture['daf_director']->id, (int) $action->financement_daf_par);
        $this->assertSame('DAF-2026-001', $action->financement_reference);
        $this->assertNotNull($fixture['dg']->fresh()->notifications()->first());
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'financement_valide_daf',
        ]);

        $this->actingAs($fixture['dg'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Accord DG');

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.actions.financement.dg', $action), [
                'decision_financement' => ActionTrackingService::FINANCEMENT_DECISION_ACCORDER,
                'commentaire_financement' => 'Accord DG donne.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(Action::FINANCEMENT_ACCORDE_DG, $action->financement_statut);
        $this->assertSame((int) $fixture['dg']->id, (int) $action->financement_dg_par);
        $this->assertNotNull($fixture['service_user']->fresh()->notifications()->first());
        $this->assertNotNull($fixture['direction_user']->fresh()->notifications()->first());
        $this->assertNotNull($fixture['agent']->fresh()->notifications()->first());
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'financement_accord_dg',
        ]);
    }

    public function test_daf_financing_requests_page_lists_only_required_financing_actions(): void
    {
        $fixture = $this->createFixture();
        $action = $fixture['action'];

        app(ActionTrackingService::class)->syncFinancingRequest($action, $fixture['service_user']);

        Action::query()->create([
            'pta_id' => $action->pta_id,
            'libelle' => 'Action sans financement DAF',
            'description' => 'Action sans demande financiere.',
            'date_debut' => '2026-02-01',
            'date_fin' => '2026-02-28',
            'statut' => 'non_demarre',
            'responsable_id' => $fixture['agent']->id,
            'financement_requis' => false,
            'financement_statut' => Action::FINANCEMENT_NON_REQUIS,
        ]);

        $this->actingAs($fixture['daf_director'])
            ->get(route('workspace.daf.financements.index'))
            ->assertOk()
            ->assertSee('Demandes de financement des actions')
            ->assertSee('Action avec besoin de financement')
            ->assertSee('Budget interne')
            ->assertDontSee('Action sans financement DAF');
    }

    public function test_daf_can_request_financing_complement_without_transmitting_to_dg(): void
    {
        $fixture = $this->createFixture();
        $action = $fixture['action'];

        $trackingService = app(ActionTrackingService::class);
        $trackingService->reviewClosureByChef($action->fresh(), [
            'decision_validation' => 'valider',
            'motif_validation_chef' => 'Validation chef avant analyse DAF.',
        ], $fixture['service_user']);

        $action->refresh();
        $this->assertSame(Action::FINANCEMENT_SOUMIS_DAF, $action->financement_statut);

        $this->actingAs($fixture['daf_director'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Demander un complement');

        $this->actingAs($fixture['daf_director'])
            ->post(route('workspace.actions.financement.daf', $action), [
                'decision_financement' => ActionTrackingService::FINANCEMENT_DECISION_COMPLEMENT,
                'commentaire_financement' => 'Merci de joindre le devis fournisseur.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(Action::FINANCEMENT_COMPLEMENT_DEMANDE, $action->financement_statut);
        $this->assertSame(ActionTrackingService::FINANCEMENT_DECISION_COMPLEMENT, $action->financement_daf_decision);
        $this->assertNull($action->financement_dg_par);
        $this->assertNotNull($fixture['agent']->fresh()->notifications()->first());
        $this->assertNotNull($fixture['service_user']->fresh()->notifications()->first());
        $this->assertFalse($fixture['dg']->fresh()->notifications->contains(
            fn ($notification): bool => ($notification->data['title'] ?? '') === 'Complement demande par la DAF'
        ));
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'financement_complement_demande',
        ]);
    }

    /**
     * @return array{action:Action,agent:User,service_user:User,direction_user:User,daf_director:User,dg:User}
     */
    private function createFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-FIN',
            'libelle' => 'Direction Metier Financement',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-FIN',
            'libelle' => 'Service Metier Financement',
            'actif' => true,
        ]);
        $daf = Direction::query()->create([
            'code' => 'DAF',
            'libelle' => 'Direction Administrative et Financiere',
            'actif' => true,
        ]);

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'AG-FIN-01',
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
        $dafDirector = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $daf->id,
            'password_changed_at' => now(),
        ]);
        $dg = User::factory()->create([
            'role' => User::ROLE_DG,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS financement',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-FIN',
            'libelle' => 'Axe financement',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-FIN',
            'libelle' => 'Objectif financement',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO financement',
            'statut' => 'brouillon',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA financement',
            'statut' => 'brouillon',
        ]);

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action avec besoin de financement',
            'description' => 'Action test financement',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-31',
            'date_echeance' => '2026-01-31',
            'responsable_id' => $agent->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => true,
            'description_financement' => 'Besoin logistique et budgetaire.',
            'source_financement' => 'Budget interne',
            'montant_estime' => 1800000,
            'ressource_main_oeuvre' => true,
        ]);

        app(ActionTrackingService::class)->initializeActionTracking($action, $serviceUser);

        return [
            'action' => $action->fresh(),
            'agent' => $agent,
            'service_user' => $serviceUser,
            'direction_user' => $directionUser,
            'daf_director' => $dafDirector,
            'dg' => $dg,
        ];
    }
}
