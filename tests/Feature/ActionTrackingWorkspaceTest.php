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
use App\Services\Actions\ActionStatusService;
use App\Services\Actions\ActionTrackingService;
use App\Services\Workflow\ActionWorkflowService;
use App\Services\Workflow\DeadlineExtensionChangeSet;
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
            ->assertSee('Justificatif', false)
            ->assertSee('Prochaine étape', false)
            ->assertSee('Selon exécution')
            ->assertSee('Renseigner l’avancement')
            ->assertSee('Faire le suivi', false)
            ->assertSee("Report de l'action", false)
            ->assertSee(route('workspace.actions.suivi', $fixture['action']), false)
            ->assertSee(route('workspace.actions.suivi', $fixture['action']).'#action-echeances', false)
            ->assertDontSee(route('workspace.actions.edit', $fixture['action']), false);

        $this->assertSame(1, substr_count($response->getContent(), 'Faire le suivi'));
        $this->assertSame(1, substr_count($response->getContent(), "Report de l'action"));
    }

    public function test_actions_table_highlights_required_proof_status(): void
    {
        $fixture = $this->createFixture(['justificatif_obligatoire' => true]);

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.index'))
            ->assertOk()
            ->assertSee('Pièce obligatoire manquante')
            ->assertSee('Preuve obligatoire')
            ->assertSee('Renseigner l’avancement');
    }

    public function test_removing_an_action_filter_resets_pagination_and_keeps_the_layout(): void
    {
        $fixture = $this->createFixture(['libelle' => 'Action filtre pagination']);

        $response = $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.index', ['q' => 'pagination', 'page' => 2, 'layout' => 'list']));

        $response->assertOk();

        $document = new \DOMDocument;
        @$document->loadHTML(mb_convert_encoding($response->getContent(), 'HTML-ENTITIES', 'UTF-8'));
        $links = (new \DOMXPath($document))->query('//a[contains(@class, "active-filter-chip")]');
        $this->assertCount(1, $links);
        $link = $links->item(0);
        parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('page', $query);
        $this->assertSame('list', $query['layout']);
        $this->assertSame('', $query['q'] ?? '');
        $this->assertStringContainsString('pagination', $link->getAttribute('aria-label'));

        $this->get($link->getAttribute('href'))
            ->assertOk()
            ->assertSee('Action filtre pagination');
    }

    public function test_actions_table_filters_by_axis_direction_and_service(): void
    {
        $visibleFixture = $this->createFixture(['libelle' => 'Action filtre visible']);
        $hiddenFixture = $this->createFixture(['libelle' => 'Action filtre masquee']);

        $this->actingAs($visibleFixture['controller'])
            ->get(route('workspace.actions.index', [
                'pas_axe_id' => $visibleFixture['axis']->id,
                'direction_id' => $visibleFixture['direction']->id,
                'service_id' => $visibleFixture['service']->id,
            ]))
            ->assertOk()
            ->assertSee('Axe stratégique')
            ->assertSee('Direction')
            ->assertSee('Service')
            ->assertSee('Action filtre visible')
            ->assertDontSee('Action filtre masquee');

        $this->assertNotSame($visibleFixture['axis']->id, $hiddenFixture['axis']->id);
    }

    public function test_agent_workspace_shows_operational_command_and_complete_hierarchy(): void
    {
        $fixture = $this->createFixture();

        $response = $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertOk()
            ->assertSee('Poste de traitement', false)
            ->assertSee('Points de controle avant validation', false)
            ->assertSee('Resultat declare', false)
            ->assertSee('Circuit officiel', false)
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

    public function test_action_workspace_shows_missing_required_execution_proof(): void
    {
        $fixture = $this->createFixture(['justificatif_obligatoire' => true]);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertOk()
            ->assertSee('Justificatif d&#039;execution', false)
            ->assertSee('Piece obligatoire manquante', false)
            ->assertSee('#action-justificatifs', false);
    }

    public function test_historical_execution_form_is_limited_to_sciq_and_planning_profiles(): void
    {
        $fixture = $this->createFixture();
        $historicalRoute = route('workspace.actions.historical-execution.store', $fixture['action']);

        foreach ([
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ,
        ] as $role) {
            $reviewer = User::factory()->create(['role' => $role]);

            $this->actingAs($reviewer)
                ->get(route('workspace.actions.suivi', $fixture['action']))
                ->assertOk()
                ->assertSee($historicalRoute, false);
        }

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertOk()
            ->assertDontSee($historicalRoute, false);
    }

    public function test_manual_historical_execution_recalculates_quantitative_performance(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['controller'])
            ->post(route('workspace.actions.historical-execution.store', $fixture['action']), [
                'statut_execution' => 'achevee',
                'date_debut_reelle' => '2026-01-05',
                'date_fin_reelle' => '2026-03-28',
                'quantite_realisee' => 80,
                'commentaire' => 'PV historique a joindre.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $fixture['action']));

        $action = $fixture['action']->fresh(['actionKpi']);
        $this->assertSame('100.00', (string) $action->progression_reelle);
        $this->assertSame('80.0000', (string) $action->quantite_realisee);
        $this->assertSame(100.0, (float) $action->actionKpi?->progression_reelle);
        $this->assertNotNull($action->historical_execution_recorded_at);
    }

    public function test_historical_execution_accepts_unknown_real_dates_and_keeps_neutral_status(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['controller'])
            ->post(route('workspace.actions.historical-execution.store', $fixture['action']), [
                'statut_execution' => 'achevee',
                'date_debut_reelle' => '',
                'date_fin_reelle' => '',
                'progression' => 100,
                'commentaire' => 'Réalisation confirmée par le rapport T1.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $fixture['action']));

        $action = $fixture['action']->fresh();
        $this->assertNull($action->date_debut_reelle);
        $this->assertNull($action->date_fin_reelle);
        $this->assertSame(ActionTrackingService::STATUS_EN_COURS, $action->statut_dynamique);
        $this->assertSame('100.00', (string) $action->progression_reelle);
        $this->assertFalse(app(ActionStatusService::class)->isCompleted($action));
    }

    public function test_historical_completion_can_be_confirmed_and_submitted_by_its_agent(): void
    {
        Storage::fake('local');
        $fixture = $this->createFixture(['justificatif_obligatoire' => true]);

        $this->actingAs($fixture['controller'])
            ->post(route('workspace.actions.historical-execution.store', $fixture['action']), [
                'statut_execution' => 'achevee',
                'date_debut_reelle' => '',
                'date_fin_reelle' => '',
                'quantite_realisee' => 100,
                'commentaire' => 'Exécution attestée au premier trimestre.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $fixture['action']));

        $imported = $fixture['action']->fresh();
        $this->assertSame(ActionTrackingService::STATUS_EN_COURS, $imported->statut_dynamique);
        $this->assertNull($imported->date_fin_reelle);
        $this->assertNotNull($imported->historical_execution_recorded_at);
        $this->assertNull($imported->historical_execution_confirmed_at);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $fixture['action']))
            ->assertOk()
            ->assertSee('Réalisation antérieure à confirmer.', false)
            ->assertSee(route('workspace.actions.execution.update', $fixture['action']), false);

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.execution.update', $fixture['action']), [
                'quantite_realisee' => 100,
                'commentaire' => 'Justificatif du premier trimestre joint.',
                'justificatif' => UploadedFile::fake()->create('pv-premier-trimestre.pdf', 10, 'application/pdf'),
                'tracking_action' => 'submit',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $fixture['action']));

        $submitted = $fixture['action']->fresh();
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $submitted->statut_validation);
        $this->assertSame($fixture['agent']->id, (int) $submitted->soumise_par);
        $this->assertSame($fixture['controller']->id, (int) $submitted->historical_execution_recorded_by);
        $this->assertSame($fixture['agent']->id, (int) $submitted->historical_execution_confirmed_by);
        $this->assertNotNull($submitted->historical_execution_confirmed_at);
        $this->assertNull($submitted->date_fin_reelle);
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $submitted->id,
            'type_evenement' => 'action_soumise_validation',
            'utilisateur_id' => $fixture['agent']->id,
        ]);

        $workflow = app(ActionWorkflowService::class);
        $submitted = $workflow->reviewAction($submitted, true, null, $fixture['chef']);
        $planner = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $submitted = $workflow->reviewActionByPlanification($submitted, true, null, $planner);
        $controller = User::factory()->create(['role' => User::ROLE_SCIQ]);
        $closed = $workflow->reviewActionByController($submitted, true, null, $controller);

        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CONTROLE, $closed->statut_validation);
        $this->assertSame(ActionTrackingService::STATUS_CLOTUREE, $closed->statut_dynamique);
        $this->assertNull($closed->date_fin_reelle, 'La date de visa ne doit pas remplacer la date réelle inconnue.');
        $this->assertNotNull($closed->cloture_le, 'La clôture administrative conserve son propre horodatage.');
    }

    public function test_historical_action_cannot_be_submitted_without_its_required_justificatif(): void
    {
        $fixture = $this->createFixture(['justificatif_obligatoire' => true]);
        $this->actingAs($fixture['controller'])
            ->post(route('workspace.actions.historical-execution.store', $fixture['action']), [
                'statut_execution' => 'achevee',
                'progression' => 100,
                'commentaire' => 'Exécution attestée au premier trimestre.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $fixture['action']));

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.execution.update', $fixture['action']), [
                'quantite_realisee' => 100,
                'commentaire' => 'Soumission sans pièce jointe.',
                'tracking_action' => 'submit',
            ])
            ->assertSessionHasErrors('general');

        $action = $fixture['action']->fresh();
        $this->assertSame(ActionTrackingService::VALIDATION_NON_SOUMISE, $action->statut_validation);
        $this->assertNull($action->historical_execution_confirmed_at);
    }

    public function test_historical_import_is_rejected_for_composite_actions(): void
    {
        $fixture = $this->createFixture(['type_action' => Action::TYPE_COMPOSEE]);

        $this->actingAs($fixture['controller'])
            ->post(route('workspace.actions.historical-execution.store', $fixture['action']), [
                'statut_execution' => 'achevee',
                'progression' => 100,
                'commentaire' => 'Exécution composée à reprendre.',
            ])
            ->assertSessionHasErrors('general');

        $this->assertNull($fixture['action']->fresh()->historical_execution_recorded_at);
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
        $planification = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $action = $workflow->reviewActionByPlanification($action, true, null, $planification);
        $sciq = User::factory()->create(['role' => User::ROLE_SCIQ]);

        $this->actingAs($sciq)
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Contrôle final SCIQ', false)
            ->assertSeeText('Clôturer par le SCIQ')
            ->assertSee('Ouvrir la validation finale', false);
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
        $planification = User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION]);
        $action = $workflow->reviewActionByPlanification($action, true, null, $planification);
        $action = $workflow->reviewActionByController($action, true, null, $fixture['controller']);

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
        $planification = User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION]);
        $action = $workflow->reviewActionByPlanification($action, true, null, $planification);
        $action = $workflow->reviewActionByController($action, true, null, $fixture['controller']);

        $this->assertSame(ActionTrackingService::VALIDATION_VALIDEE_CONTROLE, $action->statut_validation);
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

    /**
     * Le circuit ne doit pas pouvoir etre court-circuite : aucune etape ne peut
     * etre sautee, et personne ne peut poser deux visas successifs.
     */
    public function test_control_cannot_review_an_action_that_the_chief_has_not_signed_off(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);

        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 40], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);

        // L'action est en attente du chef : le controle ne peut pas se saisir.
        $this->expectException(\InvalidArgumentException::class);
        $workflow->reviewActionByController($action, true, null, $fixture['controller']);
    }

    public function test_planification_cannot_close_an_action_that_control_has_not_transmitted(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);
        $planification = User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION]);

        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 40], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);

        // La Planification transmet au SCIQ ; elle ne clôture pas l'action.
        $action = $workflow->reviewActionByPlanification($action, true, null, $planification);
        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CONTROLE, $action->statut_validation);
    }

    public function test_the_responsible_cannot_sign_off_their_own_action_as_chief(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);

        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 40], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);

        // Separation des roles : celui qui soumet ne peut pas viser.
        $this->expectException(\InvalidArgumentException::class);
        $workflow->reviewAction($action, true, null, $fixture['agent']);
    }

    public function test_control_cannot_review_an_action_it_already_signed_off_as_chief(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);

        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 40], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);
        $planification = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $action = $workflow->reviewActionByPlanification($action, true, null, $planification);

        // Le chef qui a vise ne peut pas ensuite poser le visa de controle.
        $this->expectException(\InvalidArgumentException::class);
        $workflow->reviewActionByController($action, true, null, $fixture['chef']);
    }

    public function test_planification_cannot_close_an_action_it_already_controlled(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);

        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 40], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);
        $planification = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $action = $workflow->reviewActionByPlanification($action, true, null, $planification);
        $action = $workflow->reviewActionByController($action, true, null, $fixture['controller']);

        // Le controleur qui a transmis ne peut pas realiser la cloture finale.
        $this->expectException(\InvalidArgumentException::class);
        $workflow->reviewActionByPlanification($action, true, null, $fixture['controller']);
    }

    public function test_an_action_returned_by_planification_becomes_editable_again(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);
        $planification = User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION]);

        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 40], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);
        $action = $workflow->reviewActionByPlanification($action, false, 'Preuve insuffisante', $planification);

        $this->assertSame(
            ActionTrackingService::VALIDATION_RETOUR_PLANIFICATION,
            $action->statut_validation
        );

        $action = $workflow->reviewAction($action, true, 'Retour Planification confirmé.', $fixture['chef']);

        // Le responsable doit pouvoir corriger puis resoumettre : sans cela
        // l'action restait gelee definitivement.
        $action = $workflow->recordActionProgress($action, ['quantite_realisee' => 60], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);

        $this->assertSame(
            ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            $action->statut_validation
        );
    }

    public function test_a_closed_action_cannot_be_reviewed_again(): void
    {
        $fixture = $this->createFixture();
        $workflow = app(ActionWorkflowService::class);
        $planification = User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION]);
        $otherPlanification = User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION]);

        $action = $workflow->recordActionProgress($fixture['action'], ['quantite_realisee' => 100], $fixture['agent']);
        $action = $workflow->submitAction($action, ['has_new_proof' => true], $fixture['agent']);
        $action = $workflow->reviewAction($action, true, null, $fixture['chef']);
        $action = $workflow->reviewActionByPlanification($action, true, null, $planification);
        $action = $workflow->reviewActionByController($action, true, null, $fixture['controller']);

        // Une action cloturee ne se rejoue pas.
        $this->expectException(\InvalidArgumentException::class);
        $workflow->reviewActionByPlanification($action, true, null, $otherPlanification);
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
            'change_fields' => [DeadlineExtensionChangeSet::FIELD_DEADLINE],
            'requested_deadline' => now()->addMonths(3)->toDateString(),
            'motif' => 'Dependance externe confirmee',
            'justification' => 'Le fournisseur a transmis un calendrier revise et documente.',
            'piece_justificative' => UploadedFile::fake()->create('preuve.pdf', 100, 'application/pdf'),
        ];

        $this->actingAs($assignedAgent)
            ->post(route('workspace.actions.deadline-extension.store', $fixture['action']), $basePayload)
            ->assertRedirect()
            ->assertSessionHasErrors('sous_action_id');

        $this->actingAs($assignedAgent)
            ->post(route('workspace.actions.deadline-extension.store', $fixture['action']), [
                ...$basePayload,
                'sous_action_id' => $otherSubAction->id,
                'piece_justificative' => UploadedFile::fake()->create('preuve-autre.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect()
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
     * @return array{action: Action, agent: User, chef: User, controller: User, direction: Direction, service: Service, axis: PasAxe}
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

        return compact('action', 'agent', 'chef', 'controller', 'direction', 'service', 'axis');
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
