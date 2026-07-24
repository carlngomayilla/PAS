<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\JournalAudit;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\PlanningUnlockRequest;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\PersonalTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersonalTaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_chef_receives_validation_task_with_48h_deadline(): void
    {
        $fixture = $this->planningFixture();

        $action = $this->makeAction($fixture['pta'], $fixture['agent'], 'Action a valider');
        $action->forceFill([
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'soumise_le' => now()->subHours(49),
        ])->save();

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertSee('Validation chef')
            ->assertSee('En retard')
            ->assertSee('Action a valider');

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertDontSee('Validation chef');
    }

    public function test_inline_validation_from_personal_tasks_is_journalized(): void
    {
        $fixture = $this->planningFixture();

        $action = $this->makeAction($fixture['pta'], $fixture['agent'], 'Action journalisee depuis taches');
        $action->forceFill([
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'soumise_le' => now()->subHour(),
            'progression_reelle' => 80,
        ])->save();

        $this->actingAs($fixture['chef'])
            ->post(route('workspace.actions.review', $action), [
                'source' => 'personal_tasks',
                'decision' => 'valider',
            ])
            ->assertRedirect(route('workspace.tasks.index'));

        $audit = JournalAudit::query()
            ->where('module', 'action')
            ->where('action', 'review_action_validate')
            ->where('entite_id', $action->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($fixture['chef']->id, $audit->user_id);
        $this->assertSame('personal_tasks', $audit->nouvelle_valeur['audit_context']['source'] ?? null);
        $this->assertTrue((bool) ($audit->nouvelle_valeur['audit_context']['intervention_processed'] ?? false));
    }

    public function test_inline_rejection_from_personal_tasks_requires_a_reason_and_returns_to_the_queue(): void
    {
        $fixture = $this->planningFixture();
        $action = $this->makeAction($fixture['pta'], $fixture['agent'], 'Action a renvoyer depuis taches');
        $action->forceFill([
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'soumise_le' => now()->subHour(),
            'progression_reelle' => 60,
        ])->save();

        $this->actingAs($fixture['chef'])
            ->from(route('workspace.tasks.index'))
            ->post(route('workspace.actions.review', $action), [
                'source' => 'personal_tasks',
                'decision' => 'rejeter',
            ])
            ->assertRedirect(route('workspace.tasks.index'))
            ->assertSessionHasErrors('motif');

        $this->assertSame(
            ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            $action->fresh()->statut_validation
        );

        $this->actingAs($fixture['chef'])
            ->post(route('workspace.actions.review', $action), [
                'source' => 'personal_tasks',
                'decision' => 'rejeter',
                'motif' => 'La preuve doit etre completee avant validation.',
            ])
            ->assertRedirect(route('workspace.tasks.index'))
            ->assertSessionHas('success');

        $this->assertSame(
            ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
            $action->fresh()->statut_validation
        );
    }

    public function test_planification_receives_final_control_task_after_chef_visa(): void
    {
        $fixture = $this->planningFixture();
        $controller = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'password_changed_at' => now(),
        ]);
        $action = $this->makeAction($fixture['pta'], $fixture['agent'], 'Action a controler');
        $action->forceFill([
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CONTROLE,
            'evalue_le' => now()->subHours(49),
            'chef_progress_percent' => 80,
        ])->save();

        $task = collect(app(PersonalTaskService::class)->forUser($controller, 10)['items'])
            ->firstWhere('key', 'controller-validation:'.$action->id);

        $this->assertNotNull($task);
        $this->assertSame('validation_controleur', $task['type']);
        $this->assertSame('en_retard', $task['status']);
        $this->assertSame('critique', $task['criticality']);

        $this->actingAs($controller)
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertSee('Controle final')
            ->assertSee('Action a controler');
    }

    public function test_personal_task_workspace_filters_and_paginates_the_full_authorized_queue(): void
    {
        $fixture = $this->planningFixture();

        foreach (range(1, 81) as $index) {
            $action = $this->makeAction(
                $fixture['pta'],
                $fixture['agent'],
                sprintf('Lot filtrable validation %02d', $index)
            );
            $action->forceFill([
                'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
                'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                'soumise_le' => now()->subHours($index),
            ])->save();
        }

        $this->makeAction($fixture['pta'], $fixture['chef'], 'Execution hors famille validation');

        $service = app(PersonalTaskService::class);
        $filters = [
            'q' => 'lot filtrable',
            'vue' => 'toutes',
            'famille' => 'validations',
            'tri' => 'echeance',
        ];
        $firstPage = $service->workspaceForUser($fixture['chef'], $filters, 15, 1);
        $lastPage = $service->workspaceForUser($fixture['chef'], $filters, 15, 6);

        $this->assertSame(81, $firstPage['items']->total());
        $this->assertCount(15, $firstPage['items']->items());
        $this->assertCount(6, $lastPage['items']->items());
        $this->assertSame(81, $firstPage['filtered_summary']['total']);
        $this->assertTrue(collect($firstPage['items']->items())->every(
            fn (array $task): bool => $task['family'] === 'validations'
        ));

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.tasks.index', [
                ...$filters,
                'per_page' => 15,
            ]))
            ->assertOk()
            ->assertSee('File priorisée')
            ->assertSee('Validations (81)')
            ->assertSee('1-15 sur 81')
            ->assertDontSee('Execution hors famille validation');
    }

    public function test_personal_task_temporal_views_search_and_invalid_filters_are_safe(): void
    {
        $fixture = $this->planningFixture();

        $overdue = $this->makeAction($fixture['pta'], $fixture['agent'], 'Validation echeance depassee');
        $overdue->forceFill([
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'soumise_le' => now()->subHours(60),
        ])->save();

        $dueSoon = $this->makeAction($fixture['pta'], $fixture['agent'], 'Validation échéance rapide');
        $dueSoon->forceFill([
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'soumise_le' => now()->subHours(40),
        ])->save();

        $future = $this->makeAction($fixture['pta'], $fixture['agent'], 'Validation echeance future');
        $future->forceFill([
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'soumise_le' => now()->subHours(2),
        ])->save();

        $undated = $this->makeAction($fixture['pta'], $fixture['chef'], 'Execution sans calendrier');
        $undated->forceFill([
            'date_fin' => null,
            'date_echeance' => null,
        ])->save();

        $service = app(PersonalTaskService::class);
        $baseFilters = ['q' => '', 'famille' => '', 'tri' => 'priorite'];
        $overdueTasks = $service->workspaceForUser($fixture['chef'], [
            ...$baseFilters,
            'vue' => 'retard',
        ]);
        $dueSoonTasks = $service->workspaceForUser($fixture['chef'], [
            ...$baseFilters,
            'vue' => 'a_24h',
        ]);
        $undatedTasks = $service->workspaceForUser($fixture['chef'], [
            ...$baseFilters,
            'vue' => 'sans_echeance',
        ]);
        $searchTasks = $service->workspaceForUser($fixture['chef'], [
            ...$baseFilters,
            'q' => 'echeance rapide',
            'vue' => 'toutes',
        ]);

        $this->assertSame(['Validation echeance depassee'], collect($overdueTasks['items']->items())->pluck('subject')->all());
        $this->assertSame(['Validation échéance rapide'], collect($dueSoonTasks['items']->items())->pluck('subject')->all());
        $this->assertSame(['Execution sans calendrier'], collect($undatedTasks['items']->items())->pluck('subject')->all());
        $this->assertSame(['Validation échéance rapide'], collect($searchTasks['items']->items())->pluck('subject')->all());

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.tasks.index', [
                'vue' => ['inconnue'],
                'famille' => ['interdite'],
                'tri' => ['aleatoire'],
                'per_page' => [999],
                'page' => ['deux'],
            ]))
            ->assertOk()
            ->assertSee('Validation echeance depassee')
            ->assertSee('Validation échéance rapide')
            ->assertSee('Execution sans calendrier');
    }

    public function test_chef_receives_sub_action_validation_task_with_48h_deadline(): void
    {
        $fixture = $this->planningFixture();

        $action = $this->makeAction($fixture['pta'], $fixture['agent'], 'Action avec sous-action');
        $subAction = SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $fixture['agent']->id,
            'libelle' => 'Sous-action a controler',
            'date_debut' => now()->subWeek()->toDateString(),
            'date_fin' => now()->addWeek()->toDateString(),
            'date_realisation' => now()->subHours(50),
            'completed_at' => now()->subHours(50),
            'statut' => 'en_attente_validation_chef',
            'est_effectuee' => true,
            'taux_execution' => 100,
        ]);

        $tasks = app(PersonalTaskService::class)->forUser($fixture['chef'], 10)['items'];
        $task = collect($tasks)->firstWhere('key', 'chef-sub-action-validation:'.$subAction->id);

        $this->assertNotNull($task);
        $this->assertSame('validation_sous_action_chef', $task['type']);
        $this->assertSame('en_retard', $task['status']);
        $this->assertSame('critique', $task['criticality']);
        $this->assertSame('Retard de validation impute au chef valideur.', $task['score_impact']);

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertSee('Validation sous-action')
            ->assertSee('En retard')
            ->assertSee('Sous-action a controler');

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertDontSee('Validation sous-action');
    }

    public function test_daf_director_receives_financing_task_with_three_day_delay(): void
    {
        $fixture = $this->planningFixture();
        $dafDirection = Direction::query()->create([
            'code' => 'DAF',
            'libelle' => 'Direction administrative et financiere',
            'actif' => true,
        ]);
        $dafDirector = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $dafDirection->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);
        $otherDirector = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $fixture['direction']->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $action = $this->makeAction($fixture['pta'], $fixture['agent'], 'Action financee');
        $action->forceFill([
            'financement_requis' => true,
            'financement_statut' => Action::FINANCEMENT_SOUMIS_DAF,
            'financement_soumis_le' => now()->subDays(4),
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ])->save();

        $this->actingAs($dafDirector)
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertSee('Traitement DAF')
            ->assertSee('En retard')
            ->assertSee('Action financee');

        $this->actingAs($otherDirector)
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertDontSee('Traitement DAF');
    }

    public function test_financing_complement_returns_task_to_responsable_not_daf(): void
    {
        $fixture = $this->planningFixture();
        $dafDirection = Direction::query()->create([
            'code' => 'DAF',
            'libelle' => 'Direction administrative et financiere',
            'actif' => true,
        ]);
        $dafDirector = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $dafDirection->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $action = $this->makeAction($fixture['pta'], $fixture['agent'], 'Complement financement');
        $action->forceFill([
            'financement_requis' => true,
            'financement_statut' => Action::FINANCEMENT_COMPLEMENT_DEMANDE,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ])->save();
        DB::table('actions')
            ->where('id', $action->id)
            ->update(['updated_at' => now()->subHours(50)]);

        $agentTasks = app(PersonalTaskService::class)->forUser($fixture['agent'], 10)['items'];
        $agentTask = collect($agentTasks)->firstWhere('key', 'action-execution:'.$action->id);

        $this->assertNotNull($agentTask);
        $this->assertSame('correction_financement', $agentTask['type']);
        $this->assertSame('en_retard', $agentTask['status']);
        $this->assertSame('Retard de correction du dossier imputable au RMO.', $agentTask['score_impact']);

        $dafTasks = app(PersonalTaskService::class)->forUser($dafDirector, 10)['items'];
        $this->assertNull(collect($dafTasks)->firstWhere('key', 'daf-financing:'.$action->id));
    }

    public function test_dg_receives_financing_arbitrage_task_with_48h_deadline(): void
    {
        $fixture = $this->planningFixture();
        $dg = User::factory()->create([
            'role' => User::ROLE_DG,
            'direction_id' => null,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);
        $chef = $fixture['chef'];

        $action = $this->makeAction($fixture['pta'], $fixture['agent'], 'Financement critique DG');
        $action->forceFill([
            'financement_requis' => true,
            'financement_statut' => Action::FINANCEMENT_TRANSMIS_DG,
            'financement_daf_le' => now()->subHours(49),
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ])->save();

        $tasks = app(PersonalTaskService::class)->forUser($dg, 10)['items'];
        $task = collect($tasks)->firstWhere('key', 'dg-financing:'.$action->id);

        $this->assertNotNull($task);
        $this->assertSame('financement_dg', $task['type']);
        $this->assertSame('en_retard', $task['status']);
        $this->assertSame('critique', $task['criticality']);
        $this->assertSame('Delai DG de 48h impute au decideur.', $task['score_impact']);

        $this->actingAs($dg)
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertSee('Arbitrage DG financement')
            ->assertSee('En retard')
            ->assertSee('Financement critique DG');

        $this->actingAs($chef)
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertDontSee('Arbitrage DG financement');
    }

    public function test_planning_unlock_task_redirects_to_the_exact_request(): void
    {
        $fixture = $this->planningFixture();
        $controller = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $unlockRequest = PlanningUnlockRequest::query()->create([
            'module' => 'action',
            'target_type' => Action::class,
            'target_id' => $fixture['pta']->id,
            'target_label' => 'Action a deverrouiller',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'requested_by' => $fixture['chef']->id,
            'reason' => 'Correction necessaire',
            'status' => PlanningUnlockRequest::STATUS_SOUMISE,
        ]);

        $tasks = app(PersonalTaskService::class)->forUser($controller, 10)['items'];
        $task = collect($tasks)->firstWhere('key', 'planning-unlock:sciq_planif:'.$unlockRequest->id);

        $this->assertNotNull($task);
        $this->assertSame(route('workspace.planning-unlocks.index').'#unlock-request-'.$unlockRequest->id, $task['url']);

        $this->actingAs($controller)
            ->get($task['url'])
            ->assertOk()
            ->assertSee('unlock-request-'.$unlockRequest->id, false)
            ->assertSee('Action a deverrouiller');
    }

    public function test_dashboard_no_longer_embeds_personal_tasks_widget_but_module_remains_accessible(): void
    {
        // Le widget « Centre personnel » a été retiré du tableau de bord (Synthèse + Graphiques).
        // Les tâches personnelles restent accessibles via le module dédié « Mes tâches ».
        $fixture = $this->planningFixture();

        $action = $this->makeAction($fixture['pta'], $fixture['agent'], 'Dashboard validation task');
        $action->forceFill([
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'soumise_le' => now()->subHours(4),
        ])->save();

        // 1) Le dashboard ne doit plus contenir le widget personnel
        $this->actingAs($fixture['chef'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('dashboard-personal-tasks', false);

        // 2) Le module dédié continue de lister les tâches
        $this->actingAs($fixture['chef'])
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertSee('Dashboard validation task');
    }

    public function test_personal_score_uses_weighted_components_and_quality_label(): void
    {
        $fixture = $this->planningFixture();

        $action = $this->makeAction($fixture['pta'], $fixture['agent'], 'Action evaluee');
        $action->forceFill([
            'statut_dynamique' => ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CONTROLE,
            'validation_sans_correction' => true,
            'taux_performance' => 72,
            'taux_delai' => 90,
            'evalue_par' => $fixture['chef']->id,
            'evalue_le' => now(),
        ])->save();

        $summary = app(PersonalTaskService::class)
            ->forUser($fixture['agent'], 10)['summary'];

        // Spec v3 : score = Performance (60 %) + Delai (40 %) tires des KPI de l'action.
        // 72 * 0.6 + 90 * 0.4 = 43.2 + 36 = 79.2.
        $this->assertEquals(79.2, (float) $summary['score']);
        $this->assertSame('Tres bon', $summary['quality_label']);
        $this->assertSame(60, $summary['components']['performance']['weight']);
        $this->assertSame(40, $summary['components']['deadlines']['weight']);
        $this->assertEquals(72.0, (float) $summary['components']['performance']['score']);
        $this->assertEquals(90.0, (float) $summary['components']['deadlines']['score']);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertSee('Composantes du score personnel')
            ->assertSee('Qualité Tres bon');
    }

    public function test_personal_score_quality_labels_follow_canonical_scale(): void
    {
        $fixture = $this->planningFixture();
        $cases = [
            20 => 'Insuffisant',
            50 => 'Moyen',
            72 => 'Bon',
            85 => 'Tres bon',
            95 => 'Excellent',
        ];

        foreach ($cases as $note => $expectedLabel) {
            $agent = User::factory()->create([
                'role' => User::ROLE_AGENT,
                'direction_id' => $fixture['direction']->id,
                'service_id' => $fixture['service']->id,
                'password_changed_at' => now(),
            ]);

            $action = $this->makeAction($fixture['pta'], $agent, 'Action qualite '.$note);
            // Performance et Delai egaux a la note : le score global vaut la note,
            // ce qui isole le mapping label/score.
            $action->forceFill([
                'statut_dynamique' => ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
                'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CONTROLE,
                'taux_performance' => $note,
                'taux_delai' => $note,
                'evalue_par' => $fixture['chef']->id,
                'evalue_le' => now(),
            ])->save();

            $summary = app(PersonalTaskService::class)->forUser($agent, 10)['summary'];

            $this->assertSame($expectedLabel, $summary['quality_label']);
            $this->assertEquals((float) $note, (float) $summary['components']['performance']['score']);
        }
    }

    /**
     * @return array{direction: Direction, service: Service, agent: User, chef: User, pta: Pta}
     */
    private function planningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-TASK',
            'libelle' => 'Direction taches',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SRV-TASK',
            'libelle' => 'Service taches',
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
            'titre' => 'PAS taches',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'actif',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-TASK',
            'libelle' => 'Axe taches',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-TASK',
            'libelle' => 'Objectif taches',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO taches',
            'statut' => 'valide',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA taches',
            'statut' => 'en_cours',
        ]);

        return compact('direction', 'service', 'agent', 'chef', 'pta');
    }

    private function makeAction(Pta $pta, User $agent, string $label): Action
    {
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pta->pao_id,
            'libelle' => $label,
            'description' => 'Action test mes taches',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'date_debut' => now()->subWeek()->toDateString(),
            'date_fin' => now()->addWeek()->toDateString(),
            'date_echeance' => now()->addWeek()->toDateString(),
            'responsable_id' => $agent->id,
            'financement_requis' => false,
        ]);

        $action->forceFill([
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'progression_reelle' => 0,
            'progression_theorique' => 0,
        ])->save();

        return $action;
    }
}
