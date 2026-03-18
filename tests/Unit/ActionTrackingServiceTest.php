<?php

namespace Tests\Unit;

use App\Models\Action;
use App\Models\ActionWeek;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActionTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_metrics_computes_completed_action_kpis_and_status(): void
    {
        $action = $this->createQuantitativeAction();

        ActionWeek::query()->create([
            'action_id' => $action->id,
            'numero_semaine' => 1,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-07',
            'est_renseignee' => true,
            'quantite_realisee' => 100,
            'saisi_par' => $action->responsable_id,
            'saisi_le' => now(),
        ]);

        $action->update([
            'date_fin_reelle' => '2026-01-07',
            'validation_sans_correction' => true,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ]);

        $service = app(ActionTrackingService::class);
        $service->refreshActionMetrics($action, Carbon::parse('2026-01-07'));

        $action->refresh()->load('actionKpi');

        $this->assertSame(ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, $action->statut_dynamique);
        $this->assertSame('100.00', (string) $action->progression_reelle);
        $this->assertNotNull($action->actionKpi);
        $this->assertSame('100.00', (string) $action->actionKpi->kpi_performance);
        $this->assertSame('100.00', (string) $action->actionKpi->kpi_delai);
    }

    public function test_refresh_metrics_marks_action_late_when_deadline_passes_without_completion(): void
    {
        $action = $this->createQuantitativeAction();

        ActionWeek::query()->create([
            'action_id' => $action->id,
            'numero_semaine' => 1,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-07',
            'est_renseignee' => true,
            'quantite_realisee' => 10,
            'saisi_par' => $action->responsable_id,
            'saisi_le' => now(),
        ]);

        app(ActionTrackingService::class)->refreshActionMetrics($action, Carbon::parse('2026-02-01'));

        $action->refresh()->load('actionKpi');

        $this->assertSame(ActionTrackingService::STATUS_EN_RETARD, $action->statut_dynamique);
        $this->assertSame('10.00', (string) $action->progression_reelle);
        $this->assertNotNull($action->actionKpi);
        $this->assertLessThan(100, (float) $action->actionKpi->kpi_global);
    }

    private function createQuantitativeAction(): Action
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-U',
            'libelle' => 'Direction Unit Test',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-U',
            'libelle' => 'Service Unit Test',
            'actif' => true,
        ]);

        $responsable = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'UT-AG-01',
            'agent_fonction' => 'Agent test',
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS UT',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-UT',
            'libelle' => 'Axe UT',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-UT-1',
            'libelle' => 'Objectif UT',
            'ordre' => 1,
        ]);

        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'annee' => 2026,
            'titre' => 'PAO UT',
            'statut' => 'brouillon',
        ]);

        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA UT',
            'statut' => 'brouillon',
        ]);

        return Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action UT',
            'description' => 'Action unitaire',
            'type_cible' => 'quantitative',
            'unite_cible' => 'taches',
            'quantite_cible' => 100,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
            'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
            'responsable_id' => $responsable->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
        ]);
    }
}
