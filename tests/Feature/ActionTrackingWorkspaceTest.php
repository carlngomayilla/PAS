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
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Workflow\ActionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActionTrackingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private int $fixtureSequence = 0;

    public function test_actions_table_exposes_only_tracking_and_deadline_report_commands(): void
    {
        $fixture = $this->createFixture();

        $response = $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.index'));

        $response
            ->assertOk()
            ->assertSee('Faire le suivi', false)
            ->assertSee("Report de l'action", false)
            ->assertSee(route('workspace.actions.suivi', $fixture['action']), false)
            ->assertSee(route('workspace.actions.suivi', $fixture['action']).'#action-echeances', false)
            ->assertDontSee(route('workspace.actions.edit', $fixture['action']), false);

        $this->assertSame(1, substr_count($response->getContent(), 'Faire le suivi'));
        $this->assertSame(1, substr_count($response->getContent(), "Report de l'action"));
    }

    public function test_agent_workspace_shows_operational_command_and_complete_hierarchy(): void
    {
        $fixture = $this->createFixture();

        $response = $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertOk()
            ->assertSee('Poste de traitement', false)
            ->assertSee('Faire le suivi', false)
            ->assertSee('PAS Suivi 2026-2030')
            ->assertSee('AXE-01 - Qualite de service')
            ->assertSee('OS-01 - Simplifier le parcours')
            ->assertSee('PAO Suivi 2026')
            ->assertSee('Delivrer le service numerique')
            ->assertSee('PTA Suivi')
            ->assertSee('id="action-validation"', false)
            ->assertSee('id="action-status"', false)
            ->assertSee('id="action-controle"', false)
            ->assertSee('data-action-detail-tabs', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('role="tabpanel"', false)
            ->assertDontSee(route('workspace.actions.edit', $fixture['action']), false);

        $this->assertSame(7, substr_count($response->getContent(), 'data-action-tab-panel'));
        $this->assertSame(1, substr_count($response->getContent(), 'action-detail-tab-panel is-active'));
    }

    public function test_action_tracking_tabs_handle_direct_links_errors_and_keyboard_navigation(): void
    {
        $script = (string) file_get_contents(resource_path('js/action-detail-tabs.js'));
        $appScript = (string) file_get_contents(resource_path('js/app.js'));
        $styles = (string) file_get_contents(resource_path('css/anbg-glass.css'));

        $this->assertStringContainsString("'action-echeances'", (string) file_get_contents(resource_path('views/workspace/actions/suivi.blade.php')));
        $this->assertStringContainsString("'action-status': 'action-validation'", $script);
        $this->assertStringContainsString("'action-controle': 'action-validation'", $script);
        $this->assertStringContainsString('.field-error, [aria-invalid="true"]', $script);
        $this->assertStringContainsString("panel.dataset.hasErrors === 'true'", $script);
        $this->assertStringContainsString("event.key === 'ArrowRight'", $script);
        $this->assertStringContainsString('activatePanel(panelId, { focusTab: true })', $script);
        $this->assertStringNotContainsString('tabs[nextIndex].click()', $script);
        $this->assertStringContainsString("window.addEventListener('hashchange'", $script);
        $this->assertStringContainsString("import './action-detail-tabs';", $appScript);
        $this->assertStringContainsString('[data-action-tab-panel][hidden]', $styles);
        $this->assertStringContainsString('.action-stepper-panel', $styles);
        $this->assertStringContainsString('.action-detail-hero,', $styles);
    }

    public function test_chef_workspace_prioritizes_the_submitted_action_review(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);
        $action = $workflow->recordActionProgress(
            $fixture['action'],
            ['quantite_realisee' => 70],
            $fixture['agent']
        );
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Visa hierarchique', false)
            ->assertSeeText("Verifier l'execution soumise")
            ->assertSee('Examiner la soumission', false);
    }

    public function test_controller_workspace_prioritizes_the_final_control(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);
        $action = $workflow->recordActionProgress(
            $fixture['action'],
            ['quantite_realisee' => 85],
            $fixture['agent']
        );
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);

        $this->actingAs($fixture['controller'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Controle final', false)
            ->assertSeeText("Valider ou renvoyer l'execution")
            ->assertSee('Ouvrir le controle', false);
    }

    public function test_agent_actions_table_releases_metrics_only_after_final_control(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);
        $action = $workflow->recordActionProgress(
            $fixture['action'],
            ['quantite_realisee' => 85],
            $fixture['agent']
        );
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.index', ['vue' => 'mes_actions']))
            ->assertOk()
            ->assertSee('En attente de validation.', false)
            ->assertDontSee('85%', false);

        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);
        // Circuit a 3 visas : le controle transmet, la planification cloture.
        $action = $workflow->reviewActionByController($action, true, null, $fixture['controller']);

        $planification = User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION]);
        $action = $workflow->reviewActionByPlanification($action, true, null, $planification);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.index', ['vue' => 'mes_actions']))
            ->assertOk()
            ->assertSee('85%', false)
            ->assertDontSee('En attente de validation.', false);
    }

    /**
     * Regression : `hasFinalValidation()` ignorait `validee_planification`, donc
     * le recalcul du statut dynamique ecrasait la cloture posee par la
     * planification et remettait l'action en `en_retard`. L'action n'etait alors
     * comptee nulle part comme terminee.
     */
    public function test_planification_closure_survives_the_dynamic_status_recalculation(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);
        $tracking = app(ActionTrackingService::class);

        $action = $workflow->recordActionProgress(
            $fixture['action'],
            ['quantite_realisee' => 100],
            $fixture['agent']
        );
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);
        $action = $workflow->reviewActionByController($action, true, null, $fixture['controller']);

        $planification = User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION]);
        $action = $workflow->reviewActionByPlanification($action, true, null, $planification);

        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_PLANIFICATION, $action->statut_validation);
        $this->assertSame(ActionTrackingService::STATUS_CLOTUREE, $action->statut_dynamique);

        // Le recalcul des metriques ne doit pas rouvrir l'action.
        $tracking->refreshActionMetrics($action->fresh());

        $refreshed = $action->fresh();
        $this->assertSame(ActionTrackingService::STATUS_CLOTUREE, $refreshed->statut_dynamique);
        $this->assertContains($refreshed->statut, [
            ActionTrackingService::STATUS_CLOTUREE,
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
        ]);
    }

    public function test_correction_workspace_tells_the_responsible_what_to_do_next(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);
        $action = $workflow->recordActionProgress(
            $fixture['action'],
            ['quantite_realisee' => 45],
            $fixture['agent']
        );
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, false, 'Completer la preuve', $fixture['chef']);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Correction attendue', false)
            ->assertSee('Corriger puis resoumettre', false)
            ->assertSee('Traiter la correction', false)
            ->assertSee('Completer la preuve');
    }

    public function test_unconfigured_action_keeps_execution_and_report_forms_unavailable(): void
    {
        $fixture = $this->createFixture(['statut_parametrage' => 'a_parametrer']);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertOk()
            ->assertSee('Parametrage requis dans le PTA', false)
            ->assertDontSee(route('workspace.actions.execution.update', $fixture['action']), false)
            ->assertDontSee(route('workspace.actions.deadline-extension.store', $fixture['action']), false);
    }

    public function test_service_user_cannot_open_an_action_outside_their_scope(): void
    {
        $fixture = $this->createFixture();
        $otherDirection = Direction::query()->create(['code' => 'D-OTHER', 'libelle' => 'Autre direction']);
        $otherService = Service::query()->create([
            'direction_id' => $otherDirection->id,
            'code' => 'S-OTHER',
            'libelle' => 'Autre service',
        ]);
        $otherChef = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $otherDirection->id,
            'service_id' => $otherService->id,
        ]);

        $this->actingAs($otherChef)
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertForbidden();
    }

    public function test_discussion_polling_uses_the_versioned_api_and_safe_dom_nodes(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertOk()
            ->assertSee(json_encode(route('v1.actions.logs', $fixture['action'])), false)
            ->assertSee(route('workspace.actions.comment', $fixture['action']), false)
            ->assertDontSee("fetch('/api/actions/", false)
            ->assertDontSee('el.innerHTML =', false)
            ->assertSee('element.textContent = value', false);
    }

    public function test_assigned_sub_action_agent_can_read_logs_and_report_only_their_own_deadline(): void
    {
        Storage::fake('local');
        $fixture = $this->createFixture(['type_action' => Action::TYPE_COMPOSEE]);
        $assignedAgent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $fixture['service']->direction_id,
            'service_id' => $fixture['service']->id,
        ]);
        $otherAgent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $fixture['service']->direction_id,
            'service_id' => $fixture['service']->id,
        ]);
        $assignedSubAction = $this->createSubAction($fixture['action'], $assignedAgent, 'Sous-action affectee');
        $otherSubAction = $this->createSubAction($fixture['action'], $otherAgent, 'Sous-action autre agent');

        $this->actingAs($assignedAgent)
            ->getJson(route('v1.actions.logs', $fixture['action']))
            ->assertOk();

        $this->actingAs($assignedAgent)
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertOk()
            ->assertSee('Sous-action affectee')
            ->assertDontSee('<option value="">Action principale</option>', false)
            ->assertDontSee('Sous-action autre agent</option>', false);

        $basePayload = [
            'requested_deadline' => now()->addMonths(3)->toDateString(),
            'motif' => 'Dependance externe confirmee',
            'justification' => 'Le fournisseur a transmis un calendrier revise et documente.',
            'piece_justificative' => UploadedFile::fake()->create('preuve.pdf', 100, 'application/pdf'),
        ];

        $this->actingAs($assignedAgent)
            ->post(route('workspace.actions.deadline-extension.store', $fixture['action']), $basePayload)
            ->assertSessionHasErrors('sous_action_id');

        $this->actingAs($assignedAgent)
            ->post(route('workspace.actions.deadline-extension.store', $fixture['action']), [
                ...$basePayload,
                'sous_action_id' => $otherSubAction->id,
                'piece_justificative' => UploadedFile::fake()->create('preuve-autre.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('sous_action_id');

        $this->actingAs($assignedAgent)
            ->post(route('workspace.actions.deadline-extension.store', $fixture['action']), [
                ...$basePayload,
                'sous_action_id' => $assignedSubAction->id,
                'piece_justificative' => UploadedFile::fake()->create('preuve-affectee.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $fixture['action']));

        $this->assertDatabaseHas('deadline_extension_requests', [
            'action_id' => $fixture['action']->id,
            'sous_action_id' => $assignedSubAction->id,
            'requested_by' => $assignedAgent->id,
        ]);
    }

    public function test_submitted_sub_action_and_suspended_action_reject_forged_execution_updates(): void
    {
        $fixture = $this->createFixture(['type_action' => Action::TYPE_COMPOSEE]);
        $subAction = $this->createSubAction($fixture['action'], $fixture['agent'], 'Sous-action protegee');
        $workflow = app(ActionWorkflowService::class);
        $subAction = $workflow->recordSubActionProgress($subAction, ['quantite_realisee' => 40], $fixture['agent']);
        $subAction = $workflow->submitSubAction($subAction, ['has_new_proof' => false], $fixture['agent']);

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.sub-actions.update', [$fixture['action'], $subAction]), [
                'quantite_realisee' => 80,
                'tracking_action' => 'save',
            ])
            ->assertForbidden();

        try {
            $workflow->recordSubActionProgress($subAction->fresh(), ['quantite_realisee' => 80], $fixture['agent']);
            $this->fail('Une sous-action soumise ne doit pas pouvoir etre modifiee.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('gelee', $exception->getMessage());
        }

        $workflow->reviewSubAction($subAction->fresh(), true, null, $fixture['chef']);

        try {
            $workflow->reviewSubAction($subAction->fresh(), true, null, $fixture['chef']);
            $this->fail('Une decision chef ne doit pas pouvoir etre rejouee.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('pas en attente', $exception->getMessage());
        }

        $simpleFixture = $this->createFixture();
        $simpleFixture['action']->forceFill([
            'statut' => ActionTrackingService::STATUS_SUSPENDU,
            'statut_dynamique' => ActionTrackingService::STATUS_SUSPENDU,
        ])->save();

        $this->actingAs($simpleFixture['agent'])
            ->post(route('workspace.actions.execution.update', $simpleFixture['action']), [
                'quantite_realisee' => 50,
                'tracking_action' => 'save',
            ])
            ->assertForbidden();

        $this->expectException(\InvalidArgumentException::class);
        $workflow->recordActionProgress($simpleFixture['action']->fresh(), ['quantite_realisee' => 50], $simpleFixture['agent']);
    }

    /**
     * @param  array<string, mixed>  $actionOverrides
     * @return array{action: Action, agent: User, chef: User, controller: User, service: Service}
     */
    private function createFixture(array $actionOverrides = []): array
    {
        $this->fixtureSequence++;
        $suffix = (string) $this->fixtureSequence;
        $direction = Direction::query()->create(['code' => 'D-SUIVI-'.$suffix, 'libelle' => 'Direction Suivi '.$suffix]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'S-SUIVI-'.$suffix,
            'libelle' => 'Service Suivi '.$suffix,
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
        $controller = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);

        $pas = Pas::query()->create([
            'titre' => 'PAS Suivi 2026-2030',
            'periode_debut' => 2026,
            'periode_fin' => 2030,
        ]);
        $axis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'code' => 'AXE-01',
            'libelle' => 'Qualite de service',
            'ordre' => 1,
        ]);
        $strategicObjective = PasObjectif::query()->create([
            'pas_axe_id' => $axis->id,
            'code' => 'OS-01',
            'libelle' => 'Simplifier le parcours',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PAO Suivi 2026',
            'annee' => 2026,
        ]);
        $operationalObjective = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axis->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Delivrer le service numerique',
            'echeance' => now()->addYear()->toDateString(),
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operationalObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA Suivi',
        ]);
        $action = Action::query()->create(array_merge([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operationalObjective->id,
            'responsable_id' => $agent->id,
            'libelle' => 'Action suivi numerique',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'statut_parametrage' => 'parametre',
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'quantite_cible' => 100,
            'quantite_realisee' => 0,
            'unite_cible' => 'dossiers',
            'date_debut' => now()->subMonth()->toDateString(),
            'date_fin' => now()->addMonth()->toDateString(),
            'justificatif_obligatoire' => false,
        ], $actionOverrides));

        return compact('action', 'agent', 'chef', 'controller', 'service');
    }

    private function createSubAction(Action $action, User $agent, string $label): SousAction
    {
        return $action->sousActions()->create([
            'agent_id' => $agent->id,
            'libelle' => $label,
            'date_debut' => now()->subWeek()->toDateString(),
            'date_fin' => now()->addMonths(2)->toDateString(),
            'sub_action_type' => SousAction::TYPE_QUANTITATIVE,
            'cible_prevue' => 100,
            'weight' => 100,
            'requires_proof' => false,
            'statut' => 'non_demarre',
            'validation_status' => SousAction::VALIDATION_NON_SOUMISE,
        ]);
    }
}
