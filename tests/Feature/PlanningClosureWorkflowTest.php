<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\JournalAudit;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class PlanningClosureWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    public function test_pao_closure_requires_anomaly_report_or_explicit_justification(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $fixture = $this->planningFixture();

        $this->actingAs($superAdmin)
            ->from(route('workspace.pao.index'))
            ->post(route('workspace.pao.close', $fixture['pao']), [
                'motif' => 'Cloture fin exercice',
            ])
            ->assertRedirect(route('workspace.pao.index'))
            ->assertSessionHasErrors('general');

        $this->assertSame('valide', $fixture['pao']->fresh()->statut);

        $this->actingAs($superAdmin)
            ->post(route('workspace.pao.close', $fixture['pao']), [
                'motif' => 'Cloture avec anomalies assumees',
                'force_close' => '1',
            ])
            ->assertRedirect(route('workspace.pao.index'));

        $this->assertSame(Pao::STATUS_CLOTURE, $fixture['pao']->fresh()->statut);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'pao',
            'action' => 'close',
        ]);

        $audit = JournalAudit::query()->where('module', 'pao')->where('action', 'close')->latest('id')->firstOrFail();
        $after = is_array($audit->nouvelle_valeur) ? $audit->nouvelle_valeur : [];
        $this->assertTrue((bool) ($after['forced_with_anomalies'] ?? false));
        $this->assertGreaterThan(0, (int) data_get($after, 'closure_report.total'));
    }

    public function test_pas_can_be_closed_then_archived_manually_after_report(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $fixture = $this->planningFixture();

        $this->actingAs($superAdmin)
            ->post(route('workspace.pas.close', $fixture['pas']), [
                'motif' => 'Cloture PAS avec anomalies justifiees',
                'force_close' => '1',
            ])
            ->assertRedirect(route('workspace.pas.index'));

        $this->assertSame(Pas::STATUS_CLOTURE, $fixture['pas']->fresh()->statut);

        $this->actingAs($superAdmin)
            ->post(route('workspace.pas.archive', $fixture['pas']), [
                'motif' => 'Archivage manuel apres cloture',
            ])
            ->assertRedirect(route('workspace.pas.index'));

        $this->assertSame(Pas::STATUS_ARCHIVE, $fixture['pas']->fresh()->statut);
        $this->assertDatabaseHas('journal_audit', [
            'module' => 'pas',
            'action' => 'archive',
        ]);
    }

    public function test_pta_closure_report_detects_open_and_late_actions(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $fixture = $this->planningFixture();

        $this->actingAs($superAdmin)
            ->from(route('workspace.pta.index'))
            ->post(route('workspace.pta.close', $fixture['pta']), [
                'motif' => 'Cloture PTA',
            ])
            ->assertRedirect(route('workspace.pta.index'))
            ->assertSessionHasErrors('general');

        $this->actingAs($superAdmin)
            ->post(route('workspace.pta.close', $fixture['pta']), [
                'motif' => 'Cloture PTA avec anomalies assumees',
                'force_close' => '1',
            ])
            ->assertRedirect(route('workspace.pta.index'));

        $this->assertSame(Pta::STATUS_CLOTURE, $fixture['pta']->fresh()->statut);
    }

    /**
     * @return array{pas: Pas, pao: Pao, pta: Pta, action: Action}
     */
    private function planningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-CLOSE',
            'libelle' => 'Direction cloture',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SRV-CLOSE',
            'libelle' => 'Service cloture',
            'actif' => true,
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS cloture',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => Pas::STATUS_ACTIF,
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-CLOSE',
            'libelle' => 'Axe cloture',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-CLOSE',
            'libelle' => 'Objectif cloture',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO cloture',
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $objectifOperationnel = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axe->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif operationnel cloture',
            'echeance' => now()->addMonth()->toDateString(),
            'statut' => 'en_cours',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectifOperationnel->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA cloture',
            'statut' => Pta::STATUS_EN_COURS,
        ]);

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectifOperationnel->id,
            'libelle' => 'Action en retard cloture',
            'description' => 'Action ouverte pour rapport anomalies',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'date_debut' => now()->subMonth()->toDateString(),
            'date_fin' => now()->subDay()->toDateString(),
            'date_echeance' => now()->subDay()->toDateString(),
            'financement_requis' => true,
        ]);
        $action->forceFill([
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'financement_statut' => Action::FINANCEMENT_SOUMIS_DAF,
            'progression_reelle' => 0,
            'seuil_minimum' => 80,
        ])->save();

        return compact('pas', 'pao', 'pta', 'action');
    }
}
