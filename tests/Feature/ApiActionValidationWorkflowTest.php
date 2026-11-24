<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiActionValidationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_service_chief_can_validate_a_submitted_action_through_the_api(): void
    {
        $fixture = $this->createFixture();
        Sanctum::actingAs($fixture['chef']);

        $this->postJson(route('v1.actions.review', $fixture['action']), [
            'decision' => 'valider',
            'progress_percent' => 75,
            'motif' => 'Ajustement vérifié avec les pièces.',
            ...$this->signaturePayload(),
        ])
            ->assertOk()
            // Le visa du chef transmet maintenant l'action à la Planification;
            // le contrôle SCIQ intervient ensuite comme visa final.
            ->assertJsonPath('data.statut_validation', ActionTrackingService::VALIDATION_SOUMISE_PLANIFICATION);

        $this->assertSame('75.00', (string) $fixture['action']->fresh()->chef_progress_percent);
    }

    public function test_chief_approval_notifies_planification_before_sciq_through_the_api(): void
    {
        Notification::fake();
        $fixture = $this->createFixture();
        $planners = collect([
            User::factory()->create(['role' => User::ROLE_PLANIFICATION]),
            User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION]),
        ]);
        $controller = User::factory()->create(['role' => User::ROLE_SCIQ]);
        Sanctum::actingAs($fixture['chef']);

        $this->postJson(route('v1.actions.review', $fixture['action']), [
            'decision' => 'valider',
            ...$this->signaturePayload(),
        ])->assertOk()
            ->assertJsonPath('data.statut_validation', ActionTrackingService::VALIDATION_SOUMISE_PLANIFICATION);

        foreach ($planners as $planner) {
            Notification::assertSentTo($planner, WorkspaceModuleNotification::class);
            $notification = Notification::sent($planner, WorkspaceModuleNotification::class)
                ->first(fn (WorkspaceModuleNotification $notification): bool => $notification->toArray($planner)['title'] === 'Action à valider par la Planification');
            $this->assertNotNull($notification);
            $data = $notification->toArray($planner);
            $this->assertSame('Action à valider par la Planification', $data['title']);
            $this->assertSame($fixture['action']->id, (int) $data['entity_id']);
        }

        Notification::assertNotSentTo($controller, WorkspaceModuleNotification::class);
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

    public function test_rejection_does_not_require_an_approval_signature(): void
    {
        $fixture = $this->createFixture();
        $fixture['chef']->forceFill([
            'signature_path' => null,
            'signature_sha256' => null,
            'signature_updated_at' => null,
        ])->save();
        Sanctum::actingAs($fixture['chef']);

        $this->postJson(route('v1.actions.review', $fixture['action']), [
            'decision' => 'rejeter',
            'motif' => 'La pièce doit être corrigée.',
        ])->assertOk();

        $this->assertSame(ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE, $fixture['action']->fresh()->statut_validation);
    }

    public function test_approval_requires_explicit_signature_consent_and_current_password(): void
    {
        $fixture = $this->createFixture();
        Sanctum::actingAs($fixture['chef']);

        $this->postJson(route('v1.actions.review', $fixture['action']), [
            'decision' => 'valider',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['signature_consent', 'current_password']);

        $this->postJson(route('v1.actions.review', $fixture['action']), [
            'decision' => 'valider',
            'signature_consent' => '1',
            'current_password' => 'incorrect',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $fixture['action']->fresh()->statut_validation);
    }

    public function test_approval_requires_a_signature_registered_in_the_profile(): void
    {
        $fixture = $this->createFixture();
        $fixture['chef']->forceFill([
            'signature_path' => null,
            'signature_sha256' => null,
            'signature_updated_at' => null,
        ])->save();
        Sanctum::actingAs($fixture['chef']);

        $this->postJson(route('v1.actions.review', $fixture['action']), [
            'decision' => 'valider',
            ...$this->signaturePayload(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('signature_consent');

        $this->assertSame(ActionTrackingService::VALIDATION_SOUMISE_CHEF, $fixture['action']->fresh()->statut_validation);
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
            ...$this->signaturePayload(),
        ])->assertOk();

        $this->postJson(route('v1.actions.review', $fixture['action']->fresh()), [
            'decision' => 'valider',
            ...$this->signaturePayload(),
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
        $this->registerSignature($chef);
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

    /** @return array{signature_consent: string, current_password: string} */
    private function signaturePayload(): array
    {
        return [
            'signature_consent' => '1',
            'current_password' => 'password',
        ];
    }

    private function registerSignature(User $user): void
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $path = 'signatures/'.$user->id.'/workflow.png';
        Storage::disk('local')->put($path, $contents);
        $user->forceFill([
            'signature_path' => $path,
            'signature_sha256' => hash('sha256', $contents),
            'signature_updated_at' => now(),
        ])->save();
    }
}
