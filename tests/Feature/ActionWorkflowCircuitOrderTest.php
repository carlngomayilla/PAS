<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Workflow\ActionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionWorkflowCircuitOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_chef_planification_sciq_closes_in_that_order(): void
    {
        $fixture = $this->fixture();
        $workflow = app(ActionWorkflowService::class);

        $action = $workflow->recordActionProgress(
            $fixture['action'],
            ['quantite_realisee' => 100],
            $fixture['agent']
        );
        $action = $workflow->submitAction(
            $action,
            ['commentaire' => 'Resultat et preuve transmis.', 'has_new_proof' => true],
            $fixture['agent']
        );
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);

        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_PLANIFICATION, $action->statut_validation);
        $this->assertSame(ActionTrackingService::STATUS_EN_COURS, $action->statut);

        $action = $workflow->reviewActionByPlanification($action, true, 'Controle de coherence.', $fixture['planification']);
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CONTROLE, $action->statut_validation);
        $this->assertSame(ActionTrackingService::STATUS_EN_COURS, $action->statut);

        $action = $workflow->reviewActionByController($action, true, 'Visa SCIQ final.', $fixture['sciq']);
        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CONTROLE, $action->statut_validation);
        $this->assertSame(ActionTrackingService::STATUS_CLOTUREE, $action->statut);
        $this->assertSame('100.00', (string) $action->official_progress_percent);
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'action_validee_controle',
        ]);
    }

    public function test_validation_cannot_be_skipped_or_repeated_by_wrong_queue(): void
    {
        $fixture = $this->fixture();
        $workflow = app(ActionWorkflowService::class);
        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 50], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('controle final SCIQ');
        $workflow->reviewActionByController($action, true, null, $fixture['sciq']);
    }

    public function test_submitted_execution_is_locked_until_a_motivated_return(): void
    {
        $fixture = $this->fixture();
        $workflow = app(ActionWorkflowService::class);
        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 50], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);

        $this->expectException(\InvalidArgumentException::class);
        $workflow->recordActionProgress($action, ['quantite_realisee' => 80], $fixture['agent']);
    }

    public function test_sciq_return_can_be_contested_for_a_sciq_reexamination(): void
    {
        $fixture = $this->fixture();
        $workflow = app(ActionWorkflowService::class);
        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 80], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);
        $action = $workflow->reviewActionByPlanification($action, true, 'Controle de coherence.', $fixture['planification']);
        $action = $workflow->reviewActionByController($action, false, 'Verifier la preuve finale.', $fixture['sciq']);

        $this->assertSame(ActionTrackingService::VALIDATION_RETOUR_SCIQ, $action->statut_validation);
        $action = $workflow->reviewSciqReturnByPlanification(
            $action,
            'contester',
            'La preuve est conforme au dossier transmis.',
            $fixture['planification']
        );

        $this->assertSame(ActionTrackingService::VALIDATION_REEXAMEN_SCIQ, $action->statut_validation);
        $action = $workflow->reviewActionByController($action, true, 'Réexamen terminé.', $fixture['sciq']);

        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CONTROLE, $action->statut_validation);
        $this->assertSame(ActionTrackingService::STATUS_CLOTUREE, $action->statut);
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'retour_sciq_conteste_planification',
        ]);
    }

    public function test_sciq_return_reaches_the_agent_only_after_planification_and_chef_accept_it(): void
    {
        $fixture = $this->fixture();
        $workflow = app(ActionWorkflowService::class);
        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 80], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);
        $action = $workflow->reviewActionByPlanification($action, true, 'Controle de coherence.', $fixture['planification']);
        $action = $workflow->reviewActionByController($action, false, 'Preuve à compléter.', $fixture['sciq']);
        $action = $workflow->reviewSciqReturnByPlanification($action, 'accepter_rejet', 'Retour justifié.', $fixture['planification']);

        $this->assertSame(ActionTrackingService::VALIDATION_RETOUR_PLANIFICATION, $action->statut_validation);
        $action = $workflow->reviewAction($action, true, 'Retour confirmé par le Chef.', $fixture['chef']);
        $this->assertSame(ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE, $action->statut_validation);

        $action = $workflow->recordActionProgress($action, ['quantite_realisee' => 100], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);

        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'retour_planification_accepte_chef',
        ]);
    }

    public function test_planification_cannot_call_the_sciq_final_route(): void
    {
        $fixture = $this->fixture();
        $workflow = app(ActionWorkflowService::class);
        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 50], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);

        $this->actingAs($fixture['planification'])
            ->post(route('workspace.actions.control.review', $action), ['decision' => 'valider'])
            ->assertForbidden();
    }

    /**
     * @return array{action: Action, agent: User, chef: User, planification: User, sciq: User}
     */
    private function fixture(): array
    {
        $direction = Direction::query()->create(['code' => 'D-CIRCUIT', 'libelle' => 'Direction circuit']);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'S-CIRCUIT',
            'libelle' => 'Service circuit',
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        $chef = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        $planification = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $sciq = User::factory()->create(['role' => User::ROLE_SCIQ]);
        $pas = Pas::query()->create(['titre' => 'PAS circuit', 'periode_debut' => 2026, 'periode_fin' => 2030]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PAO circuit',
            'annee' => 2026,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA circuit',
        ]);
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'responsable_id' => $agent->id,
            'libelle' => 'Action circuit stricte',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'quantite_cible' => 100,
            'statut_parametrage' => 'parametre',
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'justificatif_obligatoire' => false,
        ]);

        return compact('action', 'agent', 'chef', 'planification', 'sciq');
    }
}
