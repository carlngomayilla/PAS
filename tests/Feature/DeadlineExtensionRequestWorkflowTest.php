<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\DeadlineExtensionRequest;
use App\Models\Direction;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeadlineExtensionRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_deadline_extension_request_from_tracking_page_is_reviewed_and_applied(): void
    {
        Storage::fake('local');
        Notification::fake();
        config()->set('services.brevo.enabled', false);

        $fixture = $this->createFixture();
        $action = $fixture['action'];
        $chef = $fixture['chef'];
        $sciq = $fixture['sciq'];
        $dg = $fixture['dg'];

        $this->actingAs($chef)
            ->post(route('workspace.actions.deadline-extension.store', $action), [
                'requested_deadline' => '2026-08-15',
                'motif' => 'Retard fournisseur',
                'justification' => 'La prestation depend d une livraison externe documentee.',
                'report_attachment' => UploadedFile::fake()->create('justification-report.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $request = DeadlineExtensionRequest::query()->firstOrFail();

        $this->assertSame('soumise', (string) $request->status);
        $this->assertSame('2026-06-30', $request->old_deadline->toDateString());
        $this->assertSame('2026-08-15', $request->requested_deadline->toDateString());
        $this->assertTrue((bool) $request->is_critical);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'reports_echeance',
            'entite_id' => $request->id,
            'action' => 'create',
        ]);

        $this->actingAs($sciq)
            ->post(route('workspace.deadline-extension.sciq', $request), [
                'sciq_avis' => DeadlineExtensionRequest::AVIS_FAVORABLE,
                'sciq_comment' => 'Impact PTA analyse et transmissible.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $request->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_TRANSMISE_DG, (string) $request->status);

        $this->actingAs($dg)
            ->post(route('workspace.deadline-extension.dg', $request), [
                'dg_decision' => DeadlineExtensionRequest::DECISION_APPROUVER,
                'approved_deadline' => '2026-08-10',
                'dg_comment' => 'Date ajustee et approuvee.',
            ])
            ->assertRedirect(route('workspace.actions.suivi', $action));

        $request->refresh();
        $action->refresh();

        $this->assertSame(DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE, (string) $request->status);
        $this->assertSame('2026-08-10', $request->approved_deadline->toDateString());
        $this->assertSame('2026-08-10', $action->date_fin->toDateString());
        $this->assertSame('2026-08-10', $action->date_echeance->toDateString());
    }

    /**
     * @return array{action: Action, chef: User, sciq: User, dg: User}
     */
    private function createFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-REP',
            'libelle' => 'Direction report',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SRV-REP',
            'libelle' => 'Service report',
            'actif' => true,
        ]);
        $chef = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
        $sciq = User::factory()->create([
            'role' => User::ROLE_SCIQ,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
        $dg = User::factory()->create([
            'role' => User::ROLE_DG,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS-2026-2028',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'actif',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-REP',
            'libelle' => 'Axe report',
            'ordre' => 1,
        ]);
        $pasObjectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-REP',
            'libelle' => 'Objectif strategique report',
            'ordre' => 1,
            'date_echeance' => '2028-12-31',
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $pasObjectif->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO report',
            'statut' => 'en_cours',
        ]);
        $objectif = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axe->id,
            'pas_objectif_id' => $pasObjectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'code' => 'OO-REP',
            'libelle' => 'Objectif report',
            'echeance' => '2026-07-31',
            'statut' => 'en_cours',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'objectif_operationnel_id' => $objectif->id,
            'titre' => 'PTA report',
            'statut' => 'en_cours',
        ]);
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectif->id,
            'libelle' => 'Action report',
            'mode_evaluation' => Action::MODE_QUANTITATIF,
            'type_cible' => 'quantitative',
            'quantite_cible' => 100,
            'unite_cible' => 'dossiers',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-06-30',
            'date_echeance' => '2026-06-30',
            'responsable_id' => $agent->id,
            'statut_parametrage' => 'parametre',
            'statut_validation' => 'non_soumise',
        ]);

        return compact('action', 'chef', 'sciq', 'dg');
    }
}
