<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PlatformSetting;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Workflow\ActionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests du cycle de suivi V2 : save → submit → validate.
 * Voir docs/WORKFLOW-SUIVI-V2.md.
 */
class WorkflowV2CycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_quantitative_action_save_then_submit_then_validate(): void
    {
        Storage::fake('local');
        $fixture = $this->createFixture(Action::TYPE_QUANTITATIVE, ['quantite_cible' => 100]);
        $workflow = app(ActionWorkflowService::class);

        // SAVE : enregistrement brouillon avec quantité partielle.
        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 60], $fixture['agent']);
        $this->assertEquals(60.0, (float) $action->progression_reelle);
        $this->assertEquals(0.0, (float) $action->official_progress_percent, 'Officielle reste 0 avant validation.');
        $this->assertSame(ActionTrackingService::VALIDATION_NON_SOUMISE, $action->statut_validation);

        // SAVE incrémental → remplacement (saisie totale à ce jour).
        $action = $workflow->recordActionProgress($action, ['quantite_realisee' => 90], $fixture['agent']);
        $this->assertEquals(90.0, (float) $action->progression_reelle);

        // SUBMIT (avec justificatif simulé via has_new_proof).
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);
        $this->assertEquals(0.0, (float) $action->official_progress_percent, 'Toujours pas officielle après submit.');

        // VALIDATE chef → officialise.
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);
        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);
        $this->assertEquals(90.0, (float) $action->official_progress_percent);
        $this->assertSame(ActionTrackingService::STATUS_CLOTUREE, $action->statut);
    }

    public function test_submit_without_quantity_is_blocked_for_quantitative(): void
    {
        $fixture = $this->createFixture(Action::TYPE_QUANTITATIVE, ['quantite_cible' => 100]);
        $workflow = app(ActionWorkflowService::class);

        $this->expectException(\InvalidArgumentException::class);
        $workflow->submitAction($fixture['action'], ['has_new_proof' => true], $fixture['agent']);
    }

    public function test_full_progress_submission_is_not_auto_closed_before_chef_validation(): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['group' => 'action_management', 'key' => 'actions_auto_complete_when_target_reached'],
            ['value' => '1']
        );

        $fixture = $this->createFixture(Action::TYPE_QUANTITATIVE, ['quantite_cible' => 100]);
        $workflow = app(ActionWorkflowService::class);
        $tracking = app(ActionTrackingService::class);

        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 100], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $tracking->refreshActionMetrics($action->fresh());

        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);
        $this->assertSame(ActionTrackingService::STATUS_EN_COURS, $action->statut_dynamique);
        $this->assertNotSame(ActionTrackingService::STATUS_CLOTUREE, $action->statut);
        $this->assertNull($action->date_fin_reelle);
        $this->assertNull($action->cloture_le);

        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);

        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);
        $this->assertSame(ActionTrackingService::STATUS_CLOTUREE, $action->statut_dynamique);
        $this->assertNotNull($action->date_fin_reelle);
        $this->assertNotNull($action->cloture_le);
    }

    public function test_stale_closed_status_is_reopened_until_chef_validation(): void
    {
        $fixture = $this->createFixture(Action::TYPE_QUANTITATIVE, ['quantite_cible' => 100]);
        $workflow = app(ActionWorkflowService::class);
        $tracking = app(ActionTrackingService::class);

        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 100], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action->forceFill([
            'statut' => ActionTrackingService::STATUS_CLOTUREE,
            'statut_dynamique' => ActionTrackingService::STATUS_CLOTUREE,
        ])->save();

        $action = $tracking->refreshActionMetrics($action->fresh());

        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);
        $this->assertSame(ActionTrackingService::STATUS_EN_COURS, $action->statut_dynamique);
        $this->assertSame(ActionTrackingService::STATUS_EN_COURS, $action->statut);
    }

    public function test_reject_returns_action_to_correction(): void
    {
        $fixture = $this->createFixture(Action::TYPE_QUANTITATIVE, ['quantite_cible' => 100]);
        $workflow = app(ActionWorkflowService::class);

        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 50], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, false, 'Données incomplètes', $fixture['chef']);

        $this->assertSame(ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE, $action->statut_validation);
        $this->assertSame('Données incomplètes', $action->motif_validation_chef);
        $this->assertEquals(0.0, (float) $action->official_progress_percent);
    }

    public function test_composite_action_requires_parent_validation_after_all_sub_actions_validated(): void
    {
        $fixture = $this->createFixture(Action::TYPE_COMPOSEE);
        $action = $fixture['action'];
        $workflow = app(ActionWorkflowService::class);

        // 2 sous-actions quantitatives, poids 60/40.
        $sa1 = $action->sousActions()->create([
            'agent_id' => $fixture['agent']->id, 'libelle' => 'SA1',
            'date_debut' => '2026-01-01', 'date_fin' => '2026-06-30',
            'sub_action_type' => SousAction::TYPE_QUANTITATIVE, 'cible_prevue' => 100, 'weight' => 60,
            'requires_proof' => false, 'statut' => 'non_demarre', 'validation_status' => SousAction::VALIDATION_NON_SOUMISE,
        ]);
        $sa2 = $action->sousActions()->create([
            'agent_id' => $fixture['agent']->id, 'libelle' => 'SA2',
            'date_debut' => '2026-01-01', 'date_fin' => '2026-06-30',
            'sub_action_type' => SousAction::TYPE_QUANTITATIVE, 'cible_prevue' => 100, 'weight' => 40,
            'requires_proof' => false, 'statut' => 'non_demarre', 'validation_status' => SousAction::VALIDATION_NON_SOUMISE,
        ]);

        // SA1 : 100% → submit → validate.
        $workflow->recordSubActionProgress($sa1, ['quantite_realisee' => 100], $fixture['agent']);
        $workflow->submitSubAction($sa1->fresh(), ['has_new_proof' => false], $fixture['agent']);
        $workflow->reviewSubAction($sa1->fresh(), true, null, $fixture['chef']);

        // Parent pas encore clôturé (SA2 en attente).
        $action->refresh();
        $this->assertNotSame(ActionTrackingService::STATUS_CLOTUREE, $action->statut);

        // SA2 : 50% → submit → validate.
        $workflow->recordSubActionProgress($sa2->fresh(), ['quantite_realisee' => 50], $fixture['agent']);
        $workflow->submitSubAction($sa2->fresh(), ['has_new_proof' => false], $fixture['agent']);
        $workflow->reviewSubAction($sa2->fresh(), true, null, $fixture['chef']);

        // Parent soumis au chef : perf ponderee = 100*0.6 + 50*0.4 = 80%.
        $action->refresh();
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $action->statut_validation);
        $this->assertNotSame(ActionTrackingService::STATUS_CLOTUREE, $action->statut);
        $this->assertEquals(0.0, (float) $action->official_progress_percent);

        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);

        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->statut_validation);
        $this->assertSame(ActionTrackingService::STATUS_CLOTUREE, $action->statut);
        $this->assertEquals(80.0, (float) $action->official_progress_percent);
    }

    public function test_suivi_page_renders_for_agent_quantitative(): void
    {
        $fixture = $this->createFixture(Action::TYPE_QUANTITATIVE, ['quantite_cible' => 100]);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertOk()
            ->assertSee('Suivi de l\'action', false)
            ->assertSee('Performance officielle', false)
            ->assertSee('Soumettre au chef', false);
    }

    public function test_chef_responsable_can_track_own_pilotage_action(): void
    {
        $fixture = $this->createFixture(Action::TYPE_QUANTITATIVE, [
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'quantite_cible' => 100,
        ]);
        $action = $fixture['action'];
        $action->forceFill(['responsable_id' => $fixture['chef']->id])->save();

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Soumettre au chef', false);

        $this->actingAs($fixture['chef'])
            ->post(route('workspace.actions.execution.update', $action), [
                'quantite_realisee' => 35,
                'commentaire' => 'Avancement saisi par le chef responsable.',
                'tracking_action' => 'save',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertSame('35.00', (string) $action->fresh()->progression_reelle);
    }

    public function test_suivi_page_renders_for_agent_composite(): void
    {
        $fixture = $this->createFixture(Action::TYPE_COMPOSEE);
        $fixture['action']->sousActions()->create([
            'agent_id' => $fixture['agent']->id, 'libelle' => 'SA test',
            'date_debut' => '2026-01-01', 'date_fin' => '2026-06-30',
            'sub_action_type' => SousAction::TYPE_QUANTITATIVE, 'cible_prevue' => 50, 'weight' => 100,
            'requires_proof' => false, 'statut' => 'non_demarre', 'validation_status' => SousAction::VALIDATION_NON_SOUMISE,
        ]);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertOk()
            ->assertSee('SA test', false)
            ->assertSee('Soumettre', false);
    }

    public function test_chef_sees_validation_when_action_submitted(): void
    {
        $fixture = $this->createFixture(Action::TYPE_QUANTITATIVE, ['quantite_cible' => 100]);
        $workflow = app(ActionWorkflowService::class);
        $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 100], $fixture['agent']);
        $workflow->submitAction($fixture['action']->fresh(), ['has_new_proof' => true], $fixture['agent']);

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertOk()
            ->assertSee('Décision du chef de service', false);
    }

    public function test_unit_chief_can_review_service_action_when_in_scope(): void
    {
        $fixture = $this->createFixture(Action::TYPE_QUANTITATIVE, ['quantite_cible' => 100]);
        $workflow = app(ActionWorkflowService::class);
        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 100], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $pta = $action->pta()->firstOrFail();
        $unitChief = User::factory()->create([
            'role' => User::ROLE_CHEF_UNITE_UCAS,
            'direction_id' => $pta->direction_id,
            'service_id' => $pta->service_id,
        ]);

        $this->actingAs($unitChief)
            ->post(route('workspace.actions.review', $action), [
                'decision' => 'valider',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CHEF, $action->fresh()->statut_validation);
    }

    public function test_unit_chief_cannot_open_action_from_another_service(): void
    {
        $fixture = $this->createFixture(Action::TYPE_QUANTITATIVE, ['quantite_cible' => 100]);
        $pta = $fixture['action']->pta()->firstOrFail();
        $otherService = Service::query()->create([
            'direction_id' => $pta->direction_id,
            'code' => 'SWF2',
            'libelle' => 'Service WF 2',
        ]);
        $otherUnitChief = User::factory()->create([
            'role' => User::ROLE_CHEF_UNITE_UCAS,
            'direction_id' => $pta->direction_id,
            'service_id' => $otherService->id,
        ]);

        $this->actingAs($otherUnitChief)
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertForbidden();
    }

    public function test_chef_sees_parent_validation_when_composite_action_is_submitted(): void
    {
        $fixture = $this->createFixture(Action::TYPE_COMPOSEE);
        $action = $fixture['action'];
        $workflow = app(ActionWorkflowService::class);

        $sousAction = $action->sousActions()->create([
            'agent_id' => $fixture['agent']->id,
            'libelle' => 'SA parent validation',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-06-30',
            'sub_action_type' => SousAction::TYPE_QUANTITATIVE,
            'cible_prevue' => 100,
            'weight' => 100,
            'requires_proof' => false,
            'statut' => 'non_demarre',
            'validation_status' => SousAction::VALIDATION_NON_SOUMISE,
        ]);

        $workflow->recordSubActionProgress($sousAction, ['quantite_realisee' => 100], $fixture['agent']);
        $workflow->submitSubAction($sousAction->fresh(), ['has_new_proof' => false], $fixture['agent']);
        $workflow->reviewSubAction($sousAction->fresh(), true, null, $fixture['chef']);

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Décision du chef de service', false)
            ->assertSee('Valider l\'action', false);
    }

    public function test_validation_tab_lists_only_actions_waiting_for_validation(): void
    {
        $fixture = $this->createFixture(Action::TYPE_COMPOSEE);
        $fixture['action']->sousActions()->create([
            'agent_id' => $fixture['agent']->id,
            'libelle' => 'Sous-action envoyee chef',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-06-30',
            'sub_action_type' => SousAction::TYPE_NON_QUANTITATIVE,
            'weight' => 100,
            'requires_proof' => false,
            'statut' => 'en_attente_validation_chef',
            'validation_status' => SousAction::VALIDATION_SOUMISE,
            'est_effectuee' => true,
        ]);
        Action::query()->create([
            'pta_id' => $fixture['action']->pta_id,
            'responsable_id' => $fixture['agent']->id,
            'libelle' => 'Action hors validation',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'statut_parametrage' => 'parametre',
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'quantite_cible' => 100,
            'justificatif_obligatoire' => false,
        ]);
        Action::query()->create([
            'pta_id' => $fixture['action']->pta_id,
            'responsable_id' => $fixture['chef']->id,
            'libelle' => 'Action personnelle du chef',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'statut_parametrage' => 'parametre',
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'quantite_cible' => 100,
            'justificatif_obligatoire' => false,
        ]);

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.index', [
                'vue' => 'validations',
            ]))
            ->assertOk()
            ->assertSee('Validations', false)
            ->assertSee('Action WF '.Action::TYPE_COMPOSEE)
            ->assertDontSee('Action hors validation')
            ->assertDontSee('Action personnelle du chef');
    }

    /**
     * @return array{action: Action, agent: User, chef: User}
     */
    private function createFixture(string $typeAction, array $actionOverrides = []): array
    {
        $direction = Direction::query()->create(['code' => 'DWF', 'libelle' => 'Direction WF']);
        $service = Service::query()->create(['direction_id' => $direction->id, 'code' => 'SWF', 'libelle' => 'Service WF']);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'direction_id' => $direction->id, 'service_id' => $service->id]);
        $chef = User::factory()->create(['role' => User::ROLE_SERVICE, 'direction_id' => $direction->id, 'service_id' => $service->id]);

        $pas = Pas::query()->create(['titre' => 'PAS WF', 'periode_debut' => 2026, 'periode_fin' => 2030]);
        $pao = Pao::query()->create(['pas_id' => $pas->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'titre' => 'PAO WF', 'annee' => 2026]);
        $pta = Pta::query()->create(['pao_id' => $pao->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'titre' => 'PTA WF']);

        $action = Action::query()->create(array_merge([
            'pta_id' => $pta->id,
            'responsable_id' => $agent->id,
            'libelle' => 'Action WF '.$typeAction,
            'type_action' => $typeAction,
            'statut_parametrage' => 'parametre',
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'justificatif_obligatoire' => false,
        ], $actionOverrides));

        return ['action' => $action, 'agent' => $agent, 'chef' => $chef];
    }
}
