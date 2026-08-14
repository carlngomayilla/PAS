<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Justificatif;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActionFinancingGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rmo_submission_opens_the_daf_queue_with_notification_log_and_audit(): void
    {
        $fixture = $this->createFixture();
        $action = $fixture['action'];

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.tasks.index'))
            ->assertOk()
            ->assertSee('Dossier financement a soumettre');

        $this->actingAs($fixture['daf'])
            ->post(route('workspace.actions.financement.daf', $action), $this->dafApprovalPayload())
            ->assertSessionHasErrors('general');

        $this->assertSame(Action::FINANCEMENT_PRE_SIGNALE_DAF, $action->fresh()->financementStatus());

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.actions.suivi', $action))
            ->assertOk()
            ->assertSee('Soumettre à la DAF');

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.financement.submit', $action), [
                'source_financement' => 'Budget programme 2026',
                'commentaire_financement' => 'Dossier complet transmis avec devis.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $action->refresh();
        $this->assertSame(Action::FINANCEMENT_SOUMIS_DAF, $action->financementStatus());
        $this->assertSame('Budget programme 2026', $action->source_financement);
        $this->assertNotNull($action->financement_soumis_le);
        $this->assertNotNull($action->financement_notifie_le);
        $this->assertNotNull($fixture['daf']->fresh()->notifications()->first());
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'financement_soumis_daf',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'user_id' => $fixture['agent']->id,
            'entite_id' => $action->id,
            'action' => 'submit_financing_daf',
        ]);

        $this->actingAs($fixture['daf'])
            ->get(route('workspace.daf.financements.index'))
            ->assertOk()
            ->assertSee(route('workspace.daf.financing-requests.index'), false);

        $this->actingAs($fixture['daf'])
            ->get(route('workspace.daf.financing-requests.index'))
            ->assertOk()
            ->assertSee('A instruire DAF')
            ->assertSee('Instruire DAF');
    }

    public function test_financing_commands_are_forbidden_outside_the_assigned_roles(): void
    {
        $fixture = $this->createFixture();
        $action = $fixture['action'];

        $this->actingAs($fixture['chef'])
            ->post(route('workspace.actions.financement.submit', $action), [
                'source_financement' => 'Budget interne',
                'commentaire_financement' => 'Tentative hors responsabilite.',
            ])
            ->assertForbidden();

        $action->forceFill(['financement_statut' => Action::FINANCEMENT_SOUMIS_DAF])->save();

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.actions.financement.daf', $action), $this->dafApprovalPayload())
            ->assertForbidden();

        $action->forceFill(['financement_statut' => Action::FINANCEMENT_TRANSMIS_DG])->save();

        $this->actingAs($fixture['daf'])
            ->post(route('workspace.actions.financement.dg', $action), [
                'decision_financement' => ActionTrackingService::FINANCEMENT_DECISION_ACCORDER,
                'commentaire_financement' => 'Tentative de decision DG par la DAF.',
            ])
            ->assertForbidden();
    }

    public function test_daf_complement_requires_a_new_proof_before_rmo_resubmission(): void
    {
        Storage::fake('local');
        $fixture = $this->createFixture();
        $action = $fixture['action'];
        $this->submitToDaf($fixture);

        $this->actingAs($fixture['daf'])
            ->post(route('workspace.actions.financement.daf', $action), [
                'decision_financement' => ActionTrackingService::FINANCEMENT_DECISION_COMPLEMENT,
                'commentaire_financement' => 'Joindre un devis fournisseur actualise.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertSame(Action::FINANCEMENT_COMPLEMENT_DEMANDE, $action->fresh()->financementStatus());

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.financement.submit', $action), [
                'source_financement' => 'Budget programme 2026',
                'commentaire_financement' => 'Devis actualise ajoute au dossier.',
            ])
            ->assertSessionHasErrors('justificatif_financement');

        $this->assertSame(Action::FINANCEMENT_COMPLEMENT_DEMANDE, $action->fresh()->financementStatus());

        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.financement.submit', $action), [
                'source_financement' => 'Budget programme 2026',
                'commentaire_financement' => 'Devis actualise ajoute au dossier.',
                'justificatif_financement' => UploadedFile::fake()->create(
                    'devis-actualise.pdf',
                    32,
                    'application/pdf'
                ),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertSame(Action::FINANCEMENT_SOUMIS_DAF, $action->fresh()->financementStatus());
        $this->assertDatabaseHas('justificatifs', [
            'justifiable_type' => Action::class,
            'justifiable_id' => $action->id,
            'categorie' => 'financement',
            'nom_original' => 'devis-actualise.pdf',
        ]);
        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'type_evenement' => 'financement_resoumis_daf',
        ]);
    }

    public function test_stale_or_duplicate_financing_decisions_do_not_change_the_result(): void
    {
        Storage::fake('local');
        $fixture = $this->createFixture();
        $action = $fixture['action'];
        $this->submitToDaf($fixture);

        $this->actingAs($fixture['daf'])
            ->post(route('workspace.actions.financement.daf', $action), $this->dafApprovalPayload())
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertSame(Action::FINANCEMENT_TRANSMIS_DG, $action->fresh()->financementStatus());

        $filesBeforeReplay = Storage::disk('local')->allFiles();
        $stalePayload = $this->dafApprovalPayload();
        $stalePayload['justificatif_financement_daf'] = UploadedFile::fake()->create(
            'avis-daf-rejoue.pdf',
            24,
            'application/pdf'
        );

        $this->actingAs($fixture['daf'])
            ->post(route('workspace.actions.financement.daf', $action), $stalePayload)
            ->assertSessionHasErrors('general');

        $this->assertSame($filesBeforeReplay, Storage::disk('local')->allFiles());

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.actions.financement.dg', $action), [
                'decision_financement' => ActionTrackingService::FINANCEMENT_DECISION_REFUSER,
                'commentaire_financement' => 'Credit budgetaire non disponible.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $this->assertSame(Action::FINANCEMENT_REJETE_DG, $action->fresh()->financementStatus());

        $this->actingAs($fixture['dg'])
            ->post(route('workspace.actions.financement.dg', $action), [
                'decision_financement' => ActionTrackingService::FINANCEMENT_DECISION_ACCORDER,
                'commentaire_financement' => 'Tentative de rejeu de la decision.',
            ])
            ->assertSessionHasErrors('general');

        $action->refresh();
        $this->assertSame(Action::FINANCEMENT_REJETE_DG, $action->financementStatus());
        $this->assertSame(ActionTrackingService::FINANCEMENT_DECISION_REFUSER, $action->financement_dg_decision);
    }

    public function test_suspended_action_and_legacy_direct_status_route_cannot_bypass_governance(): void
    {
        $fixture = $this->createFixture();
        $action = $fixture['action'];
        $this->submitToDaf($fixture);
        $action->forceFill(['statut' => ActionTrackingService::STATUS_SUSPENDU])->save();

        $this->actingAs($fixture['daf'])
            ->post(route('workspace.actions.financement.daf', $action), $this->dafApprovalPayload())
            ->assertSessionHasErrors('general');

        $this->assertSame(Action::FINANCEMENT_SOUMIS_DAF, $action->fresh()->financementStatus());

        $this->actingAs($fixture['daf'])
            ->post(route('workspace.actions.financement.daf.status', $action), [
                'statut_financement' => Action::FINANCEMENT_VALIDE_DG,
            ])
            ->assertStatus(410);

        $this->assertSame(Action::FINANCEMENT_SOUMIS_DAF, $action->fresh()->financementStatus());
    }

    /**
     * @param  array{action:Action,agent:User,chef:User,daf:User,dg:User}  $fixture
     */
    private function submitToDaf(array $fixture): void
    {
        $this->actingAs($fixture['agent'])
            ->post(route('workspace.actions.financement.submit', $fixture['action']), [
                'source_financement' => 'Budget programme 2026',
                'commentaire_financement' => 'Dossier complet transmis avec devis.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $fixture['action']));
    }

    /**
     * @return array{decision_financement:string,montant_valide:int,reference_financement:string,commentaire_financement:string}
     */
    private function dafApprovalPayload(): array
    {
        return [
            'decision_financement' => ActionTrackingService::FINANCEMENT_DECISION_VALIDER,
            'montant_valide' => 1450000,
            'reference_financement' => 'DAF-2026-145',
            'commentaire_financement' => 'Avis favorable apres controle des pieces.',
        ];
    }

    /**
     * @return array{action:Action,agent:User,chef:User,daf:User,dg:User}
     */
    private function createFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-FIN-GOV',
            'libelle' => 'Direction metier financement',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-FIN-GOV',
            'libelle' => 'Service metier financement',
            'actif' => true,
        ]);
        $dafDirection = Direction::query()->create([
            'code' => 'DAF',
            'libelle' => 'Direction administrative et financiere',
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
        $daf = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $dafDirection->id,
            'password_changed_at' => now(),
        ]);
        $dg = User::factory()->create([
            'role' => User::ROLE_DG,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS gouvernance financement',
            'periode_debut' => 2026,
            'periode_fin' => 2030,
        ]);
        $axis = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'code' => 'AXE-FIN-GOV',
            'libelle' => 'Gouvernance financiere',
            'ordre' => 1,
        ]);
        $objective = PasObjectif::query()->create([
            'pas_axe_id' => $axis->id,
            'code' => 'OS-FIN-GOV',
            'libelle' => 'Securiser les financements',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PAO gouvernance financement',
            'annee' => 2026,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA gouvernance financement',
            'statut' => 'brouillon',
        ]);
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'responsable_id' => $agent->id,
            'libelle' => 'Action financee gouvernee',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 20,
            'statut_parametrage' => 'parametre',
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'statut' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'date_debut' => now()->subMonth()->toDateString(),
            'date_fin' => now()->addMonths(2)->toDateString(),
            'financement_requis' => true,
            'financement_statut' => Action::FINANCEMENT_PRE_SIGNALE_DAF,
            'nature_financement' => 'Investissement numerique',
            'description_financement' => 'Acquisition de licences et equipements',
            'montant_estime' => 1500000,
        ]);

        Justificatif::query()->create([
            'justifiable_type' => Action::class,
            'justifiable_id' => $action->id,
            'categorie' => 'financement',
            'nom_original' => 'devis-initial.pdf',
            'chemin_stockage' => 'justificatifs/tests/devis-initial.pdf',
            'mime_type' => 'application/pdf',
            'taille_octets' => 128,
            'description' => 'Piece initiale du financement',
            'ajoute_par' => $agent->id,
        ]);

        return compact('action', 'agent', 'chef', 'daf', 'dg');
    }
}
