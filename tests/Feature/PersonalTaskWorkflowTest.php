<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
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
        $this->assertSame('correction_action', $agentTask['type']);
        $this->assertSame('en_retard', $agentTask['status']);
        $this->assertSame('Retard de correction imputable au responsable de l action.', $agentTask['score_impact']);

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
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            'validation_sans_correction' => true,
            'taux_performance' => 72,
            'evalue_par' => $fixture['chef']->id,
            'evalue_le' => now(),
        ])->save();

        $summary = app(PersonalTaskService::class)
            ->forUser($fixture['agent'], 10)['summary'];

        $this->assertEquals(93.0, (float) $summary['score']);
        $this->assertSame('Bon', $summary['quality_label']);
        $this->assertSame(35, $summary['components']['processed']['weight']);
        $this->assertSame(30, $summary['components']['deadlines']['weight']);
        $this->assertSame(25, $summary['components']['quality']['weight']);
        $this->assertSame(10, $summary['components']['criticality']['weight']);
        $this->assertEquals(72.0, (float) $summary['components']['quality']['score']);

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertSee('Composantes du score personnel')
            ->assertSee('Qualite Bon');
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
            $action->forceFill([
                'statut_dynamique' => ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
                'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                'taux_performance' => $note,
                'evalue_par' => $fixture['chef']->id,
                'evalue_le' => now(),
            ])->save();

            $summary = app(PersonalTaskService::class)->forUser($agent, 10)['summary'];

            $this->assertSame($expectedLabel, $summary['quality_label']);
            $this->assertEquals((float) $note, (float) $summary['components']['quality']['score']);
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
