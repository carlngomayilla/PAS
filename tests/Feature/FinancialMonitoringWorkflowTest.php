<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\BudgetOverrunRequest;
use App\Models\Direction;
use App\Models\FinancialTransaction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinancialMonitoringWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_daf_users_can_record_operations_and_disbursements_require_proof(): void
    {
        Storage::fake('local');
        $fixture = $this->createFixture();
        $action = $fixture['action'];

        $this->actingAs($fixture['daf_service'])
            ->get(route('workspace.daf.financements.index', ['action_id' => $action->id]))
            ->assertOk()
            ->assertSee('Enregistrer une operation DAF')
            ->assertSee('Nouvelle demande');

        $this->actingAs($fixture['operational_service'])
            ->post(route('workspace.finances.transactions.store', $action), $this->operationPayload())
            ->assertForbidden();

        $this->actingAs($fixture['daf_service'])
            ->post(route('workspace.finances.transactions.store', $action), $this->operationPayload())
            ->assertRedirect(route('workspace.daf.financements.index', ['action_id' => $action->id]));

        $this->assertDatabaseHas('financial_transactions', [
            'action_id' => $action->id,
            'operation_type' => FinancialTransaction::TYPE_COMMITMENT,
            'amount' => 40000,
            'recorded_by' => $fixture['daf_service']->id,
        ]);

        $this->actingAs($fixture['daf_service'])
            ->post(route('workspace.finances.transactions.store', $action), $this->operationPayload([
                'operation_type' => FinancialTransaction::TYPE_DISBURSEMENT,
                'amount' => 12000,
            ]))
            ->assertSessionHasErrors('proof');

        $this->actingAs($fixture['daf_service'])
            ->post(route('workspace.finances.transactions.store', $action), $this->operationPayload([
                'operation_type' => FinancialTransaction::TYPE_DISBURSEMENT,
                'amount' => 12000,
                'proof' => UploadedFile::fake()->create('ordre-virement.pdf', 12, 'application/pdf'),
            ]))
            ->assertRedirect(route('workspace.daf.financements.index', ['action_id' => $action->id]));

        $transaction = FinancialTransaction::query()->where('operation_type', FinancialTransaction::TYPE_DISBURSEMENT)->firstOrFail();
        $this->assertSame(1, $transaction->justificatifs()->count());
    }

    public function test_action_overrun_requires_daf_director_and_dg_before_the_budget_can_be_exceeded(): void
    {
        Storage::fake('local');
        $fixture = $this->createFixture();
        $action = $fixture['action'];

        $this->actingAs($fixture['daf_service'])
            ->post(route('workspace.finances.transactions.store', $action), $this->operationPayload([
                'operation_type' => FinancialTransaction::TYPE_DISBURSEMENT,
                'amount' => 120000,
                'proof' => UploadedFile::fake()->create('paiement.pdf', 12, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('amount');

        $this->actingAs($fixture['daf_service'])
            ->post(route('workspace.finances.overruns.store'), [
                'scope_type' => BudgetOverrunRequest::SCOPE_ACTION,
                'scope_id' => $action->id,
                'requested_extra' => 30000,
                'reason' => 'Hausse documentee du cout de la prestation.',
            ])
            ->assertRedirect(route('workspace.daf.financements.index'));

        $overrun = BudgetOverrunRequest::query()->firstOrFail();
        $this->assertSame(BudgetOverrunRequest::STATUS_PENDING_DIRECTOR, $overrun->status);

        $this->actingAs($fixture['daf_director'])
            ->post(route('workspace.finances.overruns.review', $overrun), [
                'decision' => 'transmit',
                'note' => 'Dossier controle et transmis a la DG.',
            ])
            ->assertRedirect(route('workspace.daf.financements.index'));

        $this->assertSame(BudgetOverrunRequest::STATUS_PENDING_DG, $overrun->fresh()->status);

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.finances.overruns.review', $overrun), [
                'decision' => 'approve',
                'note' => 'Depassement exceptionnel approuve.',
            ])
            ->assertRedirect(route('workspace.daf.financements.index'));

        $this->assertSame(BudgetOverrunRequest::STATUS_APPROVED, $overrun->fresh()->status);

        $this->actingAs($fixture['daf_service'])
            ->post(route('workspace.finances.transactions.store', $action), $this->operationPayload([
                'operation_type' => FinancialTransaction::TYPE_DISBURSEMENT,
                'amount' => 120000,
                'proof' => UploadedFile::fake()->create('paiement-approuve.pdf', 12, 'application/pdf'),
            ]))
            ->assertRedirect(route('workspace.daf.financements.index', ['action_id' => $action->id]));

        $this->assertDatabaseHas('financial_transactions', ['action_id' => $action->id, 'amount' => 120000]);
    }

    public function test_operational_profiles_only_see_their_scope_while_control_profiles_see_the_full_portfolio(): void
    {
        $fixture = $this->createFixture();

        $this->assertContains('financement', collect($fixture['operational_service']->workspaceModules())->pluck('code')->all());
        $this->assertContains('financement', collect($fixture['planification']->workspaceModules())->pluck('code')->all());

        $this->actingAs($fixture['operational_service'])
            ->get(route('workspace.daf.financements.index'))
            ->assertOk()
            ->assertSee('Action operationnelle')
            ->assertDontSee('Action autre direction')
            ->assertDontSee('Enregistrer une operation DAF');

        $this->actingAs($fixture['planification'])
            ->get(route('workspace.daf.financements.index'))
            ->assertOk()
            ->assertSee('Action operationnelle')
            ->assertSee('Action autre direction');
    }

    /** @return array<string, mixed> */
    private function operationPayload(array $overrides = []): array
    {
        return [
            ...[
                'operation_type' => FinancialTransaction::TYPE_COMMITMENT,
                'amount' => 40000,
                'operated_on' => '2026-07-28',
                'payment_method' => 'virement',
                'reference' => 'DAF-2026-001',
                'beneficiary' => 'Prestataire test',
                'comment' => 'Operation de test finance.',
            ],
            ...$overrides,
        ];
    }

    /** @return array{action:Action,daf_service:User,daf_director:User,dg:User,operational_service:User,planification:User} */
    private function createFixture(): array
    {
        $operationalDirection = Direction::query()->create(['code' => 'DOP', 'libelle' => 'Direction operationnelle', 'actif' => true]);
        $operationalService = Service::query()->create(['direction_id' => $operationalDirection->id, 'code' => 'SOP', 'libelle' => 'Service operationnel', 'actif' => true]);
        $otherDirection = Direction::query()->create(['code' => 'DPR', 'libelle' => 'Direction projets', 'actif' => true]);
        $otherService = Service::query()->create(['direction_id' => $otherDirection->id, 'code' => 'SPR', 'libelle' => 'Service projets', 'actif' => true]);
        $daf = Direction::query()->create(['code' => 'DAF', 'libelle' => 'Direction administrative et financiere', 'actif' => true]);
        $dafService = Service::query()->create(['direction_id' => $daf->id, 'code' => 'DAF-BUD', 'libelle' => 'Service budget DAF', 'actif' => true]);

        $dafUser = User::factory()->create(['role' => User::ROLE_SERVICE, 'direction_id' => $daf->id, 'service_id' => $dafService->id]);
        $dafDirector = User::factory()->create(['role' => User::ROLE_DIRECTION, 'direction_id' => $daf->id]);
        $dg = User::factory()->create(['role' => User::ROLE_DG]);
        $operationalUser = User::factory()->create(['role' => User::ROLE_SERVICE, 'direction_id' => $operationalDirection->id, 'service_id' => $operationalService->id]);
        $planification = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);

        $pas = Pas::query()->create(['titre' => 'PAS financier', 'periode_debut' => 2026, 'periode_fin' => 2028, 'statut' => 'brouillon']);
        $axe = PasAxe::query()->create(['pas_id' => $pas->id, 'code' => 'AX-FIN', 'libelle' => 'Axe financier', 'ordre' => 1]);
        $objective = PasObjectif::query()->create(['pas_axe_id' => $axe->id, 'code' => 'OS-FIN', 'libelle' => 'Objectif financier', 'ordre' => 1]);

        $pao = Pao::query()->create(['pas_id' => $pas->id, 'pas_objectif_id' => $objective->id, 'direction_id' => $operationalDirection->id, 'service_id' => $operationalService->id, 'annee' => 2026, 'titre' => 'PAO operationnel', 'statut' => 'brouillon']);
        $pta = Pta::query()->create(['pao_id' => $pao->id, 'direction_id' => $operationalDirection->id, 'service_id' => $operationalService->id, 'titre' => 'PTA operationnel', 'statut' => 'brouillon']);
        $action = $this->createAction($pta, $operationalUser, 'Action operationnelle');

        $otherPao = Pao::query()->create(['pas_id' => $pas->id, 'pas_objectif_id' => $objective->id, 'direction_id' => $otherDirection->id, 'service_id' => $otherService->id, 'annee' => 2026, 'titre' => 'PAO projets', 'statut' => 'brouillon']);
        $otherPta = Pta::query()->create(['pao_id' => $otherPao->id, 'direction_id' => $otherDirection->id, 'service_id' => $otherService->id, 'titre' => 'PTA projets', 'statut' => 'brouillon']);
        $this->createAction($otherPta, $operationalUser, 'Action autre direction');

        return [
            'action' => $action,
            'daf_service' => $dafUser,
            'daf_director' => $dafDirector,
            'dg' => $dg,
            'operational_service' => $operationalUser,
            'planification' => $planification,
        ];
    }

    private function createAction(Pta $pta, User $responsible, string $label): Action
    {
        return Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => $label,
            'description' => 'Action de suivi financier.',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 1,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'date_echeance' => '2026-12-31',
            'responsable_id' => $responsible->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => 'non_demarre',
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'montant_estime' => 100000,
        ]);
    }
}
