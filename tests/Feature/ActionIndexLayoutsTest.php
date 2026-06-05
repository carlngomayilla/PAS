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
     * @return array{chef: User, actions: array<string, Action>}
     */
    private function seedActions(): array
    {
        $direction = Direction::query()->create(['code' => 'DIN', 'libelle' => 'Direction Index']);
        $service = Service::query()->create(['direction_id' => $direction->id, 'code' => 'SIN', 'libelle' => 'Service Index']);
        $chef = User::factory()->create(['role' => User::ROLE_SERVICE, 'direction_id' => $direction->id, 'service_id' => $service->id]);

        $pas = Pas::query()->create(['titre' => 'PAS Index', 'periode_debut' => '2026-01-01', 'periode_fin' => '2030-12-31']);
        $pao = Pao::query()->create(['pas_id' => $pas->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'titre' => 'PAO Index', 'annee' => 2026]);
        $pta = Pta::query()->create(['pao_id' => $pao->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'titre' => 'PTA Index']);

        $make = fn (string $libelle, string $debut, string $echeance): Action => Action::query()->create([
            'pta_id' => $pta->id,
            'responsable_id' => $chef->id,
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

        // Volontairement crees dans le desordre pour eprouver le tri par defaut.
        $t3 = $make('Action TROISIEME trimestre', '2026-07-01', '2026-09-10');
        $t1 = $make('Action PREMIER trimestre', '2026-01-10', '2026-02-15');
        $t2 = $make('Action DEUXIEME trimestre', '2026-03-01', '2026-05-20');

        return ['chef' => $chef, 'actions' => ['t1' => $t1, 't2' => $t2, 't3' => $t3]];
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
}
