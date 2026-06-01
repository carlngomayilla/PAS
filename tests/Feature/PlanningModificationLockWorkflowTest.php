<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\PlanningUnlockRequest;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class PlanningModificationLockWorkflowTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    public function test_super_admin_bypasses_lock_on_pas_update_and_relocks_after_save(): void
    {
        // Regle metier ANBG (2026-05-28) : le Super Admin et le DG peuvent ecrire
        // meme sur un PAS verrouille — pilotage complet. Le workflow de demande
        // de deverrouillage n'est requis QUE pour les autres roles (planification,
        // SCIQ, chef de service...). Voir aussi le test PTA ci-dessous qui couvre
        // bien le workflow complet via un chef de service.
        $fixture = $this->fixture();
        $superAdmin = $this->createSuperAdminUser();
        $pas = $fixture['pas'];
        $axis = $fixture['axis'];
        $strategic = $fixture['strategic'];

        $payload = [
            'titre' => 'PAS verrou corrige par SA',
            'periode_debut' => 2026,
            'periode_fin' => 2026,
            'axes' => [[
                'id' => $axis->id,
                'code' => $axis->code,
                'libelle' => 'Axe verrou corrige',
                'objectifs' => [[
                    'id' => $strategic->id,
                    'code' => $strategic->code,
                    'libelle' => 'Objectif verrou corrige',
                    'date_echeance' => '2026-12-31',
                ]],
            ]],
        ];

        // Le Super Admin met a jour directement, sans passer par le workflow de
        // demande de deverrouillage : succes immediat, redirection vers l'index.
        $this->actingAs($superAdmin)
            ->put(route('workspace.pas.update', $pas), $payload)
            ->assertRedirect(route('workspace.pas.index'))
            ->assertSessionHasNoErrors();

        $pas->refresh();
        $this->assertSame('PAS verrou corrige par SA', $pas->titre);
        // Le verrou est ressanctuarise apres la sauvegarde (lockAfterSave).
        $this->assertNotNull($pas->modification_locked_at);
    }

    public function test_chef_service_in_scope_bypasses_lock_on_pta_update(): void
    {
        // Regle metier ANBG (2026-05-29) : un chef de service peut modifier les
        // PTAs et actions de SON service meme s'ils sont verrouilles, sans passer
        // par le workflow de demande de deverrouillage. C'est lui le responsable
        // operationnel : il est habilite a parametrer/mettre a jour son PTA.
        $fixture = $this->fixture();
        $pta = $fixture['pta'];

        // Le chef de service IN SCOPE met a jour directement, succes immediat.
        $this->actingAs($fixture['serviceUser'])
            ->put(route('workspace.pta.update', $pta), [
                'objectif_operationnel_id' => $fixture['operational']->id,
            ])
            ->assertRedirect(route('workspace.pta.index'))
            ->assertSessionHasNoErrors();

        $pta->refresh();
        $this->assertNotNull($pta->modification_locked_at, 'Le verrou est ressanctuarise apres save.');
    }

    public function test_chef_service_cannot_modify_locked_action_without_unlock_circuit(): void
    {
        // RÈGLE V2 (2026-05-31) : une ACTION verrouillée n'est PLUS modifiable
        // directement par le chef de service. Il doit passer par le circuit
        // chef → directeur → planification → DG. Toute modif directe est refusée.
        $fixture = $this->fixture();
        $action = $fixture['action'];

        $this->actingAs($fixture['serviceUser'])
            ->patch(route('workspace.actions.quick-status', $action), [
                'statut' => ActionTrackingService::STATUS_SUSPENDU,
            ])
            ->assertStatus(409);

        $action->refresh();
        $this->assertNotSame(ActionTrackingService::STATUS_SUSPENDU, $action->statut_dynamique);
        $this->assertNotNull($action->modification_locked_at);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-LOCK',
            'libelle' => 'Direction verrou',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SRV-LOCK',
            'libelle' => 'Service verrou',
            'actif' => true,
        ]);
        $serviceUser = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'is_active' => true,
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'is_active' => true,
        ]);
        $dg = User::factory()->create([
            'role' => User::ROLE_DG,
            'is_active' => true,
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS verrou',
            'periode_debut' => 2026,
            'periode_fin' => 2026,
        ]);
        $pas->forceFill([
            'statut' => Pas::STATUS_ACTIF,
            'modification_locked_at' => now(),
        ])->save();

        $axis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-LOCK',
            'libelle' => 'Axe verrou',
            'ordre' => 1,
        ]);
        $strategic = PasObjectif::query()->create([
            'pas_axe_id' => $axis->id,
            'code' => 'OS-LOCK',
            'libelle' => 'Objectif verrou',
            'ordre' => 1,
            'date_echeance' => '2026-12-31',
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $strategic->id,
            'direction_id' => $direction->id,
            'service_id' => null,
            'annee' => 2026,
            'titre' => 'PAO verrou',
            'echeance' => '2026-12-31',
            'objectif_operationnel' => 'Objectif operationnel verrou',
        ]);
        $pao->forceFill(['statut' => Pao::STATUS_VALIDE])->save();

        $operational = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axis->id,
            'pas_objectif_id' => $strategic->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif operationnel verrou',
            'echeance' => '2026-12-31',
            'statut' => Pao::STATUS_VALIDE,
        ]);

        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operational->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA verrou',
        ]);
        $pta->forceFill([
            'statut' => Pta::STATUS_EN_COURS,
            'modification_locked_at' => now(),
        ])->save();

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operational->id,
            'libelle' => 'Action verrou',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-06-30',
            'responsable_id' => $agent->id,
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'origine_action' => Action::ORIGIN_PTA,
            'type_cible' => 'quantitative',
            'mode_evaluation' => Action::MODE_QUANTITATIF,
            'quantite_cible' => 10,
            'unite_cible' => 'dossiers',
            'financement_requis' => false,
        ]);
        $action->forceFill([
            'statut' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'modification_locked_at' => now(),
        ])->save();

        return compact(
            'direction',
            'service',
            'serviceUser',
            'agent',
            'dg',
            'pas',
            'axis',
            'strategic',
            'pao',
            'operational',
            'pta',
            'action'
        );
    }
}
