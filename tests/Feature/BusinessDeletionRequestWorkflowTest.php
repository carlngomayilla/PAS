<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\DeletionRequest;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use App\Services\PasStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class BusinessDeletionRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_pao_delete_with_existing_pta_cascades_immediately(): void
    {
        // Regle metier ANBG : le Super Admin (et le DG) suppriment DIRECTEMENT en
        // cascade les PAO + PTA + Actions enfants, sans passer par le workflow de
        // demande. Seuls les roles a perimetre limite (service, direction...) creent
        // une DeletionRequest validee ensuite par le Super Admin.
        $superAdmin = $this->createSuperAdminUser();
        [$direction, $service] = $this->makeScope('BDR1');
        [$pas, $pao, $pta] = $this->makePlanningTree($direction, $service, 'PAO impacte');

        $this->actingAs($superAdmin)
            ->delete(route('workspace.pao.destroy', $pao), [
                'motif' => 'Suppression directe par Super Admin (cascade PTA).',
            ])
            ->assertRedirect(route('workspace.pao.index'));

        // PAO et son PTA enfant doivent etre soft-deletes immediatement (cascade).
        $this->assertTrue((bool) $pao->fresh()->trashed(), 'Le PAO doit etre soft-delete par le SA.');
        $this->assertTrue((bool) $pta->fresh()->trashed(), 'Le PTA enfant doit etre soft-delete en cascade.');

        // Aucune DeletionRequest ne doit avoir ete creee : suppression directe.
        $this->assertSame(0, DeletionRequest::query()
            ->where('entity_type', Pao::class)
            ->where('entity_id', $pao->id)
            ->count());

        // Le PAS parent n'est PAS supprime (cascade descend, pas remonte).
        $this->assertNotNull($pas->fresh());
        $this->assertFalse((bool) $pas->fresh()->trashed());
    }

    public function test_dg_pao_delete_with_existing_pta_cascades_immediately(): void
    {
        // Le DG dispose des memes droits de suppression directe que le Super Admin
        // (regle metier ANBG : pilotage complet de l'agence).
        $dg = User::factory()->create([
            'role' => User::ROLE_DG,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
        [$direction, $service] = $this->makeScope('BDRDG');
        [$pas, $pao, $pta] = $this->makePlanningTree($direction, $service, 'PAO supprime par DG');

        $this->actingAs($dg)
            ->delete(route('workspace.pao.destroy', $pao), [
                'motif' => 'Suppression directe par DG (cascade PTA).',
            ])
            ->assertRedirect(route('workspace.pao.index'));

        $this->assertTrue((bool) $pao->fresh()->trashed());
        $this->assertTrue((bool) $pta->fresh()->trashed());
        $this->assertSame(0, DeletionRequest::query()
            ->where('entity_type', Pao::class)
            ->where('entity_id', $pao->id)
            ->count());
        $this->assertNotNull($pas->fresh());
    }

    public function test_service_action_delete_with_sub_actions_creates_request_instead_of_deleting(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        [$direction, $service] = $this->makeScope('BDR2');
        [, , $pta] = $this->makePlanningTree($direction, $service, 'Action impactee');

        $serviceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pta->pao_id,
            'libelle' => 'Action avec sous-action',
            'date_debut' => now()->subWeek()->toDateString(),
            'date_fin' => now()->addWeek()->toDateString(),
            'responsable_id' => $agent->id,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'financement_requis' => false,
        ]);
        SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $agent->id,
            'libelle' => 'Sous-action liee',
            'date_debut' => now()->subDay()->toDateString(),
            'date_fin' => now()->addDay()->toDateString(),
            'statut' => 'en_cours',
        ]);

        $this->actingAs($serviceUser)
            ->delete(route('workspace.actions.destroy', $action), [
                'motif' => 'Suppression demandee car action creee en doublon.',
            ])
            ->assertRedirect(route('workspace.actions.index'));

        $this->assertFalse((bool) $action->fresh()->trashed());
        $request = DeletionRequest::query()
            ->where('entity_type', Action::class)
            ->where('entity_id', $action->id)
            ->firstOrFail();

        $this->assertSame(DeletionRequest::STATUS_PENDING, $request->status);
        $this->assertSame(1, $request->impact_summary['linked_records']['sous_actions'] ?? null);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.deletion-requests.decision', $request), [
                'decision' => DeletionRequest::DECISION_DELETE,
                'reviewer_note' => 'Tentative refusee par impact encore present.',
            ])
            ->assertSessionHasErrors('decision');

        $this->assertFalse((bool) $action->fresh()->trashed());
    }

    public function test_super_admin_can_delete_unused_pas_with_required_reason(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $pas = Pas::query()->create([
            'titre' => 'PAS sans rattachement',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => Pas::STATUS_ACTIF,
        ]);

        $this->actingAs($superAdmin)
            ->delete(route('workspace.pas.destroy', $pas), [
                'motif' => 'Suppression technique apres creation par erreur.',
            ])
            ->assertRedirect(route('workspace.pas.index'));

        $this->assertTrue((bool) Pas::withTrashed()->findOrFail($pas->id)->trashed());
        $this->assertDatabaseMissing('deletion_requests', [
            'entity_type' => Pas::class,
            'entity_id' => $pas->id,
        ]);
    }

    public function test_super_admin_can_delete_pas_with_only_axes_and_objectifs(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $pas = Pas::query()->create([
            'titre' => 'PAS structure seul',
            'periode_debut' => 2026,
            'periode_fin' => 2026,
            'statut' => Pas::STATUS_ACTIF,
        ]);

        app(PasStructureService::class)->sync($pas, [[
            'libelle' => 'Axe structurel',
            'objectifs' => [[
                'libelle' => 'Objectif structurel',
            ]],
        ]], $superAdmin->id);

        $this->actingAs($superAdmin)
            ->delete(route('workspace.pas.destroy', $pas), [
                'motif' => 'Suppression technique du PAS cree par erreur.',
            ])
            ->assertRedirect(route('workspace.pas.index'));

        $this->assertTrue((bool) Pas::withTrashed()->findOrFail($pas->id)->trashed());
        $this->assertTrue((bool) PasAxe::withTrashed()->where('pas_id', $pas->id)->firstOrFail()->trashed());
        $this->assertDatabaseMissing('deletion_requests', [
            'entity_type' => Pas::class,
            'entity_id' => $pas->id,
        ]);
    }

    /**
     * @return array{0:Direction,1:Service}
     */
    private function makeScope(string $suffix): array
    {
        $direction = Direction::query()->create([
            'code' => 'B-'.$suffix,
            'libelle' => 'Direction '.$suffix,
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'S-'.$suffix,
            'libelle' => 'Service '.$suffix,
            'actif' => true,
        ]);

        return [$direction, $service];
    }

    /**
     * @return array{0:Pas,1:Pao,2:Pta}
     */
    private function makePlanningTree(Direction $direction, Service $service, string $title): array
    {
        $pas = Pas::query()->create([
            'titre' => 'PAS '.$title,
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => Pas::STATUS_ACTIF,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO '.$title,
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA '.$title,
            'statut' => Pta::STATUS_EN_COURS,
        ]);

        return [$pas, $pao, $pta];
    }
}
