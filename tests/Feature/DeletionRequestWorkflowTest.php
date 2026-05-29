<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\DeletionRequest;
use App\Models\Direction;
use App\Models\JournalAudit;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class DeletionRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_authorized_manager_can_request_user_deletion_and_super_admin_can_delete_when_impact_is_empty(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        [$direction, $service] = $this->makeScope('DEL0');

        $requester = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $target = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($requester)
            ->post(route('workspace.referentiel.utilisateurs.deletion-requests.store', $target), [
                'motif' => 'Compte cree par erreur dans le mauvais service.',
            ])
            ->assertRedirect(route('workspace.referentiel.utilisateurs.index'));

        $request = DeletionRequest::query()->where('entity_id', $target->id)->firstOrFail();
        $this->assertSame(DeletionRequest::STATUS_PENDING, $request->status);
        $this->assertSame(0, $request->impact_summary['total'] ?? null);
        $this->assertTrue(DB::table('notifications')
            ->where('notifiable_id', $superAdmin->id)
            ->where('data', 'like', '%Demande de suppression a traiter%')
            ->exists());
        $tasks = app(\App\Services\PersonalTaskService::class)->forUser($superAdmin, 10);
        $this->assertTrue(collect($tasks['items'])->contains(
            fn (array $task): bool => ($task['key'] ?? '') === 'deletion-request-review:'.$request->id
        ));

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.deletion-requests.decision', $request), [
                'decision' => DeletionRequest::DECISION_DELETE,
                'reviewer_note' => 'Impact nul, suppression acceptee apres controle.',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $this->assertTrue((bool) User::withTrashed()->findOrFail($target->id)->trashed());
        $this->assertSame(DeletionRequest::STATUS_DELETED, $request->fresh()->status);
        $this->assertTrue(DB::table('notifications')
            ->where('notifiable_id', $requester->id)
            ->where('data', 'like', '%Demande de suppression traitee%')
            ->exists());
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'deletion_request_decision',
            'entite_id' => $request->id,
        ]);
    }

    public function test_delete_decision_is_blocked_with_impact_and_disable_transfers_open_tasks(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        [$direction, $service] = $this->makeScope('DEL1');

        $requester = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);
        $target = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $replacement = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'is_active' => true,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $pta = $this->makePtaForScope($direction, $service, 'PTA demande suppression');
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pta->pao_id,
            'libelle' => 'Action ouverte avant demande de suppression',
            'date_debut' => now()->subWeek()->toDateString(),
            'date_fin' => now()->addWeek()->toDateString(),
            'responsable_id' => $target->id,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'financement_requis' => false,
        ]);

        $this->actingAs($requester)
            ->post(route('workspace.referentiel.utilisateurs.deletion-requests.store', $target), [
                'motif' => 'Depart signale avec actions encore ouvertes.',
            ])
            ->assertRedirect(route('workspace.referentiel.utilisateurs.index'));

        $request = DeletionRequest::query()->where('entity_id', $target->id)->firstOrFail();
        $this->assertGreaterThan(0, (int) ($request->impact_summary['total'] ?? 0));

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.deletion-requests.decision', $request), [
                'decision' => DeletionRequest::DECISION_DELETE,
                'reviewer_note' => 'Tentative de suppression avec impact.',
            ])
            ->assertSessionHasErrors('decision');

        $this->assertFalse((bool) User::withTrashed()->findOrFail($target->id)->trashed());

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.organization.deletion-requests.decision', $request), [
                'decision' => DeletionRequest::DECISION_DISABLE,
                'transfer_to_user_id' => $replacement->id,
                'reviewer_note' => 'Desactivation avec transfert des taches ouvertes.',
            ])
            ->assertRedirect(route('workspace.super-admin.organization.index'));

        $target->refresh();
        $this->assertFalse((bool) $target->is_active);
        $this->assertSame(DeletionRequest::STATUS_DISABLED, $request->fresh()->status);
        $this->assertDatabaseHas('actions', [
            'id' => $action->id,
            'responsable_id' => $replacement->id,
        ]);

        $audit = JournalAudit::query()
            ->where('module', 'super_admin')
            ->where('action', 'deletion_request_decision')
            ->where('entite_id', $request->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(DeletionRequest::DECISION_DISABLE, $audit->nouvelle_valeur['decision'] ?? null);
        $this->assertSame($replacement->id, $audit->nouvelle_valeur['execution']['lifecycle']['replacement_user_id'] ?? null);
    }

    /**
     * @return array{0:Direction,1:Service}
     */
    private function makeScope(string $suffix): array
    {
        $direction = Direction::query()->create([
            'code' => 'DR-'.$suffix,
            'libelle' => 'Direction '.$suffix,
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SR-'.$suffix,
            'libelle' => 'Service '.$suffix,
            'actif' => true,
        ]);

        return [$direction, $service];
    }

    private function makePtaForScope(Direction $direction, Service $service, string $title): Pta
    {
        $pas = Pas::query()->create([
            'titre' => 'PAS '.$title,
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'valide',
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO '.$title,
            'statut' => 'valide',
        ]);

        return Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => $title,
            'statut' => 'en_cours',
        ]);
    }
}
