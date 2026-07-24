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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre le module "Suivi des actions" (workspace.actions.index) :
 * - tri par defaut par trimestre / echeance la plus proche a la plus lointaine ;
 * - rendu des cartes Kanban / Calendrier / Gantt qui s'appuient desormais sur les
 *   vraies colonnes date_debut / date_fin / date_echeance (et non plus *_prevue).
 */
class ActionIndexLayoutsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{chef: User, pta: Pta, actions: array<string, Action>}
     */
    private function seedActions(): array
    {
        $direction = Direction::query()->create(['code' => 'DIN', 'libelle' => 'Direction Index']);
        $service = Service::query()->create(['direction_id' => $direction->id, 'code' => 'SIN', 'libelle' => 'Service Index']);
        $chef = User::factory()->create(['role' => User::ROLE_SERVICE, 'direction_id' => $direction->id, 'service_id' => $service->id]);

        $pas = Pas::query()->create(['titre' => 'PAS Index', 'periode_debut' => 2026, 'periode_fin' => 2030]);
        $pao = Pao::query()->create(['pas_id' => $pas->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'titre' => 'PAO Index', 'annee' => 2026]);
        $pta = Pta::query()->create(['pao_id' => $pao->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'titre' => 'PTA Index']);

        // Volontairement crees dans le desordre pour eprouver le tri par defaut.
        $t3 = $this->createAction($pta, $chef, 'Action TROISIEME trimestre', '2026-07-01', '2026-09-10');
        $t1 = $this->createAction($pta, $chef, 'Action PREMIER trimestre', '2026-01-10', '2026-02-15');
        $t2 = $this->createAction($pta, $chef, 'Action DEUXIEME trimestre', '2026-03-01', '2026-05-20');

        return ['chef' => $chef, 'pta' => $pta, 'actions' => ['t1' => $t1, 't2' => $t2, 't3' => $t3]];
    }

    private function createAction(
        Pta $pta,
        User $responsable,
        string $libelle,
        string $debut,
        string $echeance
    ): Action {
        return Action::query()->create([
            'pta_id' => $pta->id,
            'responsable_id' => $responsable->id,
            'libelle' => $libelle,
            'statut_parametrage' => 'parametre',
            'statut' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'date_debut' => $debut,
            'date_fin' => $echeance,
            'date_echeance' => $echeance,
            'justificatif_obligatoire' => false,
        ]);
    }

    /**
     * @param  array{chef: User, pta: Pta, actions: array<string, Action>}  $fixture
     */
    private function createActionBeyondFirstPage(array $fixture): Action
    {
        foreach (range(1, 15) as $day) {
            $date = sprintf('2026-01-%02d', $day);
            $this->createAction($fixture['pta'], $fixture['chef'], 'Action pagination '.sprintf('%02d', $day), $date, $date);
        }

        return $this->createAction(
            $fixture['pta'],
            $fixture['chef'],
            'Action exhaustive hors premiere page',
            '2026-12-01',
            '2026-12-31'
        );
    }

    public function test_default_list_is_ordered_by_closest_deadline_and_shows_quarter_badge(): void
    {
        $fixture = $this->seedActions();

        $html = $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.index'))
            ->assertOk()
            ->assertSee('T1 2026')
            ->assertSee('T2 2026')
            ->assertSee('T3 2026')
            ->getContent();

        // Echeance la plus proche d'abord : T1 avant T2 avant T3.
        $posT1 = strpos($html, 'Action PREMIER trimestre');
        $posT2 = strpos($html, 'Action DEUXIEME trimestre');
        $posT3 = strpos($html, 'Action TROISIEME trimestre');

        $this->assertNotFalse($posT1);
        $this->assertNotFalse($posT2);
        $this->assertNotFalse($posT3);
        $this->assertTrue($posT1 < $posT2 && $posT2 < $posT3, 'Les actions doivent etre triees de l echeance la plus proche a la plus lointaine.');
    }

    public function test_kanban_layout_renders_cards_with_real_deadline(): void
    {
        $fixture = $this->seedActions();

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.index', ['layout' => 'kanban']))
            ->assertOk()
            ->assertSee('Action PREMIER trimestre')
            // La carte affiche desormais l'echeance reelle (date_echeance) et le trimestre.
            ->assertSee('15/02/2026')
            ->assertSee('T1 2026');
    }

    public function test_calendar_layout_lists_actions_due_in_month(): void
    {
        $fixture = $this->seedActions();

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.index', ['layout' => 'calendar', 'cal_year' => 2026, 'cal_month' => 2]))
            ->assertOk()
            ->assertSee('Action PREMIER trimestre');
    }

    public function test_gantt_layout_plots_actions_from_real_dates(): void
    {
        $fixture = $this->seedActions();

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.index', ['layout' => 'gantt']))
            ->assertOk()
            ->assertDontSee('Aucune action planifiée')
            ->assertSee('Action PREMIER trimestre');
    }

    public function test_visual_layouts_cover_every_filtered_action_while_the_table_stays_paginated(): void
    {
        $fixture = $this->seedActions();
        $overflowAction = $this->createActionBeyondFirstPage($fixture);

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.index'))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows): bool => $rows->total() === 19 && $rows->count() === 15)
            ->assertViewHas('visualizationRows', fn ($rows): bool => $rows->isEmpty())
            ->assertDontSee($overflowAction->libelle);

        $layouts = [
            'kanban' => [],
            'calendar' => ['cal_year' => 2026, 'cal_month' => 12],
            'gantt' => [],
        ];

        foreach ($layouts as $layout => $parameters) {
            $this->actingAs($fixture['chef'])
                ->get(route('workspace.actions.index', ['layout' => $layout, ...$parameters]))
                ->assertOk()
                ->assertViewHas('layoutMode', $layout)
                ->assertViewHas('visualizationRows', fn ($rows): bool => $rows->count() === 19)
                ->assertSee($overflowAction->libelle);
        }
    }

    public function test_visualization_rows_keep_search_filters_and_organizational_scope(): void
    {
        $fixture = $this->seedActions();
        $visibleAction = $this->createAction(
            $fixture['pta'],
            $fixture['chef'],
            'Recherche exhaustive autorisee',
            '2026-10-01',
            '2026-10-31'
        );

        $foreignDirection = Direction::query()->create(['code' => 'DEX', 'libelle' => 'Direction exclue']);
        $foreignService = Service::query()->create([
            'direction_id' => $foreignDirection->id,
            'code' => 'SEX',
            'libelle' => 'Service exclu',
        ]);
        $foreignResponsable = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $foreignDirection->id,
            'service_id' => $foreignService->id,
        ]);
        $foreignPao = Pao::query()->create([
            'pas_id' => $fixture['pta']->pao->pas_id,
            'direction_id' => $foreignDirection->id,
            'service_id' => $foreignService->id,
            'titre' => 'PAO exclu',
            'annee' => 2026,
        ]);
        $foreignPta = Pta::query()->create([
            'pao_id' => $foreignPao->id,
            'direction_id' => $foreignDirection->id,
            'service_id' => $foreignService->id,
            'titre' => 'PTA exclu',
        ]);
        $foreignAction = $this->createAction(
            $foreignPta,
            $foreignResponsable,
            'Recherche exhaustive interdite',
            '2026-10-01',
            '2026-10-31'
        );

        $response = $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.index', [
                'layout' => 'kanban',
                'q' => 'Recherche exhaustive',
            ]))
            ->assertOk();

        $visualizationRows = $response->viewData('visualizationRows');
        $this->assertCount(1, $visualizationRows);
        $this->assertSame($visibleAction->id, $visualizationRows->sole()->id);

        $response
            ->assertSee($visibleAction->libelle)
            ->assertDontSee($foreignAction->libelle);
    }

    public function test_unknown_layout_falls_back_to_the_paginated_list(): void
    {
        $fixture = $this->seedActions();

        $this->actingAs($fixture['chef'])
            ->get(route('workspace.actions.index', ['layout' => 'inconnue']))
            ->assertOk()
            ->assertViewHas('layoutMode', 'list')
            ->assertViewHas('visualizationRows', fn ($rows): bool => $rows->isEmpty());
    }
}
