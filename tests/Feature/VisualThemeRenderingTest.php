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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisualThemeRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_theme_renders_dashboard_and_populated_action_list(): void
    {
        $year = now()->year;
        $direction = Direction::factory()->create(['code' => 'VIS', 'libelle' => 'Direction de vérification visuelle']);
        $service = Service::factory()->create(['direction_id' => $direction->id, 'code' => 'VIS-S', 'libelle' => 'Service de vérification']);
        $user = User::factory()->create(['name' => 'Profil de démonstration', 'email' => 'visual.fixture@example.test', 'role' => User::ROLE_SERVICE, 'direction_id' => $direction->id, 'service_id' => $service->id, 'password_changed_at' => now()]);
        $pas = Pas::query()->create(['titre' => 'PAS de démonstration', 'periode_debut' => $year, 'periode_fin' => $year + 4]);
        $axis = PasAxe::query()->create(['pas_id' => $pas->id, 'direction_id' => $direction->id, 'code' => 'VIS', 'libelle' => 'Amélioration des services', 'ordre' => 1]);
        $objective = PasObjectif::query()->create(['pas_axe_id' => $axis->id, 'code' => 'VIS-OBJ', 'libelle' => 'Qualité des services']);
        $pao = Pao::query()->create(['pas_id' => $pas->id, 'pas_objectif_id' => $objective->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'titre' => 'PAO de démonstration', 'annee' => $year]);
        $pta = Pta::query()->create(['pao_id' => $pao->id, 'direction_id' => $direction->id, 'service_id' => $service->id, 'titre' => 'PTA de démonstration']);
        Action::query()->create(['pta_id' => $pta->id, 'responsable_id' => $user->id, 'libelle' => 'Améliorer le suivi des dossiers et la qualité de service', 'statut_parametrage' => 'parametre', 'statut' => 'non_demarre', 'statut_dynamique' => 'non_demarre', 'statut_validation' => 'non_soumise', 'contexte_action' => Action::CONTEXT_PILOTAGE, 'date_debut' => $year.'-01-01', 'date_fin' => $year.'-12-31', 'date_echeance' => $year.'-12-31', 'justificatif_obligatoire' => false]);

        foreach (['dashboard' => 'dashboard', 'actions' => 'workspace.actions.index'] as $name => $route) {
            $response = $this->actingAs($user)->get(route($route))->assertOk();
            $response->assertSee('Profil de démonstration');
            $response->assertSee('<main', false);

            if ($name === 'actions') {
                $response->assertSee('Améliorer le suivi des dossiers et la qualité de service');
            }

            if (getenv('PAS_VISUAL_FIXTURE_EXPORT') === '1') {
                $directory = base_path('output/ui-theme-fixtures');
                if (! is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                file_put_contents($directory.'/'.$name.'.html', $response->getContent());
            }
        }
    }
}
