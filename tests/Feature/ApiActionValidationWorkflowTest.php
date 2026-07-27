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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiActionValidationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_chief_can_validate_a_submitted_action_through_the_api(): void
    {
        $fixture = $this->createFixture();
        Sanctum::actingAs($fixture['chef']);

        $this->postJson(route('v1.actions.review', $fixture['action']), [
            'decision' => 'valider',
            'progress_percent' => 75,
            'motif' => 'Ajustement vérifié avec les pièces.',
        ])
            ->assertOk()
            ->assertJsonPath('data.statut_validation', ActionTrackingService::VALIDATION_SOUMISE_CONTROLE);

        $this->assertSame('75.00', (string) $fixture['action']->fresh()->chef_progress_percent);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $fixture = $this->createFixture();
        Sanctum::actingAs($fixture['chef']);

        $this->postJson(route('v1.actions.review', $fixture['action']), [
            'decision' => 'rejeter',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motif');
    }

    public function test_chief_cannot_review_an_action_from_another_service(): void
    {
        $fixture = $this->createFixture();
        $otherService = Service::query()->create([
            'direction_id' => $fixture['direction']->id,
            'code' => 'S-API-2',
            'libelle' => 'Autre service API',
        ]);
        $otherChief = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $fixture['direction']->id,
            'service_id' => $otherService->id,
        ]);
        Sanctum::actingAs($otherChief);

        $this->postJson(route('v1.actions.review', $fixture['action']), [
            'decision' => 'valider',
        ])->assertForbidden();
    }

    public function test_action_cannot_be_reviewed_twice(): void
    {
        $fixture = $this->createFixture();
        Sanctum::actingAs($fixture['chef']);

        $this->postJson(route('v1.actions.review', $fixture['action']), [
            'decision' => 'valider',
        ])->assertOk();

        $this->postJson(route('v1.actions.review', $fixture['action']->fresh()), [
            'decision' => 'valider',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
    }

    /**
     * @return array{action: Action, chef: User, direction: Direction}
     */
    private function createFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'D-API',
            'libelle' => 'Direction API',
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'S-API',
            'libelle' => 'Service API',
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
        $pas = Pas::query()->create([
            'titre' => 'PAS API',
            'periode_debut' => 2026,
            'periode_fin' => 2030,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PAO API',
            'annee' => 2026,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA API',
        ]);
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'responsable_id' => $agent->id,
            'libelle' => 'Action soumise par API',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'quantite_cible' => 100,
            'quantite_realisee' => 50,
            'progression_reelle' => 50,
            'statut_parametrage' => 'parametre',
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'justificatif_obligatoire' => false,
        ]);

        return compact('action', 'chef', 'direction');
    }
}
