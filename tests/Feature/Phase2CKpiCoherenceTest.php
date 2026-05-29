<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\ActionPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre la sous-phase 2.C :
 *   - A17 : Action::authoritativeProgress() expose une valeur unique bornee 0-100.
 *   - A18 : isValidatedSubAction() exige le statut workflow (validee/cloturee)
 *           ou la validation hierarchique de l action parente.
 *   - A19 : ActionTrackingService est la seule source des statuts terminaux,
 *           le scope "actions en retard" reste aligne sur completedActionStatuses.
 *   - A28 : refreshActionMetrics tourne dans une transaction.
 */
class Phase2CKpiCoherenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a17_authoritative_progress_is_bounded(): void
    {
        $action = new Action([
            'libelle' => 'X',
            'type_cible' => 'qualitative',
        ]);
        // forceFill car beaucoup de champs ne sont plus mass-assignable.
        $action->forceFill(['progression_reelle' => 150, 'progression_theorique' => 50]);

        $progress = $action->authoritativeProgress();
        $this->assertGreaterThanOrEqual(0.0, $progress);
        $this->assertLessThanOrEqual(100.0, $progress);
    }

    public function test_a18_is_validated_sub_action_requires_workflow(): void
    {
        $service = app(ActionPerformanceService::class);

        // Sous-action juste cochee "effectuee" — N EST PAS validee au sens
        // institutionnel.
        $unverified = new SousAction([
            'est_effectuee' => true,
            'statut' => 'effectuee',
        ]);
        $this->assertFalse($service->isValidatedSubAction($unverified));

        // Sous-action statut="validee" — validee.
        $validated = new SousAction([
            'est_effectuee' => true,
            'statut' => 'validee',
        ]);
        $this->assertTrue($service->isValidatedSubAction($validated));
    }

    public function test_a19_delayed_actions_aligned_on_completed_statuses(): void
    {
        // Verifie que la liste des statuts terminaux centralisee est utilisee
        // (et non pas un statut hardcode "en_retard").
        $completed = ActionTrackingService::completedActionStatuses();

        $this->assertContains(ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, $completed);
        $this->assertContains(ActionTrackingService::STATUS_ACHEVE_HORS_DELAI, $completed);
        $this->assertContains(ActionTrackingService::STATUS_CLOTUREE, $completed);
        $this->assertContains(ActionTrackingService::STATUS_SUSPENDU, $completed);
        $this->assertContains(ActionTrackingService::STATUS_ANNULE, $completed);

        // L action en retard NE doit PAS etre dans la liste des "terminees".
        $this->assertNotContains(ActionTrackingService::STATUS_EN_RETARD, $completed);
    }

    public function test_a28_refresh_action_metrics_runs_inside_transaction(): void
    {
        [$admin, $pta] = $this->createPlanningFixture();

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'A28 action',
            'type_cible' => 'qualitative',
            'resultat_attendu' => 'OK',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
            'responsable_id' => $admin->id,
        ]);

        $service = app(ActionTrackingService::class);

        // On enregistre les transactions ouvertes au moment de l execution.
        $depths = [];
        \DB::listen(function ($query) use (&$depths): void {
            $depths[] = \DB::transactionLevel();
        });

        $service->refreshActionMetrics($action);

        // Au moins une requete doit avoir tourne avec transactionLevel >= 1.
        $this->assertNotEmpty($depths);
        $this->assertGreaterThanOrEqual(1, max($depths));
    }

    public function test_global_kpi_uses_performance_and_delay_without_validation_component(): void
    {
        [$admin, $pta] = $this->createPlanningFixture();

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'KPI validation action',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'quantite_realisee' => 10,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
            'responsable_id' => $admin->id,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
        ]);

        $service = app(ActionPerformanceService::class);
        $referenceDate = \Illuminate\Support\Carbon::parse('2026-01-05');

        $this->assertSame(100.0, $service->calculateRealProgress($action));
        $this->assertSame(0.0, $service->calculateValidationScore($action));
        $this->assertSame(100.0, $service->calculateGlobalKpi($action, $referenceDate));

        $action->forceFill([
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ])->save();

        $this->assertSame(0.0, $service->calculateValidationScore($action->fresh()));
        $this->assertSame(100.0, $service->calculateGlobalKpi($action->fresh(), $referenceDate));
    }

    public function test_financed_action_global_kpi_does_not_depend_on_validation_or_financing_component(): void
    {
        [$admin, $pta] = $this->createPlanningFixture();

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'KPI financement validation',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'quantite_realisee' => 10,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-10',
            'date_echeance' => '2026-01-10',
            'responsable_id' => $admin->id,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            'financement_requis' => true,
            'financement_statut' => Action::FINANCEMENT_SOUMIS_DAF,
        ]);

        $service = app(ActionPerformanceService::class);
        $referenceDate = \Illuminate\Support\Carbon::parse('2026-01-05');

        $this->assertSame(0.0, $service->calculateValidationScore($action));
        $this->assertSame(100.0, $service->calculateGlobalKpi($action, $referenceDate));

        $action->forceFill(['financement_statut' => Action::FINANCEMENT_TRANSMIS_DG])->save();

        $this->assertSame(0.0, $service->calculateValidationScore($action->fresh()));
        $this->assertSame(100.0, $service->calculateGlobalKpi($action->fresh(), $referenceDate));

        $action->forceFill(['financement_statut' => Action::FINANCEMENT_VALIDE_DG])->save();

        $this->assertSame(0.0, $service->calculateValidationScore($action->fresh()));
        $this->assertSame(100.0, $service->calculateGlobalKpi($action->fresh(), $referenceDate));
    }

    public function test_sub_action_without_quantity_counts_progress_once_submitted_to_chef(): void
    {
        [$admin, $pta] = $this->createPlanningFixture();

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Sous-action validation KPI',
            'type_cible' => 'qualitative',
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-10',
            'responsable_id' => $admin->id,
        ]);

        $subAction = $action->sousActions()->create([
            'agent_id' => $admin->id,
            'libelle' => 'Deposer le dossier',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-10',
            'statut' => 'en_attente_validation_chef',
            'est_effectuee' => true,
            'taux_execution' => 100,
        ]);

        $service = app(ActionPerformanceService::class);

        $this->assertSame(100.0, $service->calculateRealProgress($action->fresh('sousActions')));

        $subAction->forceFill(['statut' => 'validee_chef'])->save();

        $this->assertSame(100.0, $service->calculateRealProgress($action->fresh('sousActions')));
    }

    /**
     * @return array{0: User, 1: Pta}
     */
    private function createPlanningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-2C', 'libelle' => 'D 2C', 'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id, 'code' => 'SRV-2C', 'libelle' => 'S 2C', 'actif' => true,
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS 2C', 'periode_debut' => 2026, 'periode_fin' => 2028, 'statut' => 'en_cours',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id, 'code' => 'AXE-2C', 'libelle' => 'A 2C', 'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id, 'code' => 'OS-2C', 'libelle' => 'O 2C', 'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id, 'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id, 'service_id' => $service->id,
            'annee' => 2026, 'titre' => 'PAO 2C', 'statut' => 'valide',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id, 'direction_id' => $direction->id,
            'service_id' => $service->id, 'titre' => 'PTA 2C', 'statut' => 'valide',
        ]);

        return [$admin, $pta];
    }
}
