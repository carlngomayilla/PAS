<?php

namespace Tests\Unit;

use App\Models\Action;
use App\Models\SousAction;
use App\Services\Workflow\ActionPerformanceCalculator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tests purs du calculateur de performance V2 (sans BDD).
 * Voir docs/WORKFLOW-SUIVI-V2.md §6.
 */
class ActionPerformanceCalculatorTest extends TestCase
{
    private ActionPerformanceCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new ActionPerformanceCalculator();
    }

    #[DataProvider('performanceStatusProvider')]
    public function test_performance_status_paliers(float $percent, string $expected): void
    {
        $this->assertSame($expected, $this->calc->performanceStatus($percent));
    }

    public static function performanceStatusProvider(): array
    {
        return [
            'zero'        => [0.0, ActionPerformanceCalculator::PERF_NON_DEMARRE],
            'critique-30' => [30.0, ActionPerformanceCalculator::PERF_CRITIQUE],
            'critique-49' => [49.0, ActionPerformanceCalculator::PERF_CRITIQUE],
            'alerte-50'   => [50.0, ActionPerformanceCalculator::PERF_ALERTE],
            'alerte-79'   => [79.0, ActionPerformanceCalculator::PERF_ALERTE],
            'accept-80'   => [80.0, ActionPerformanceCalculator::PERF_ACCEPTABLE],
            'accept-99'   => [99.0, ActionPerformanceCalculator::PERF_ACCEPTABLE],
            'atteinte'    => [100.0, ActionPerformanceCalculator::PERF_CIBLE_ATTEINTE],
            'depassee'    => [120.0, ActionPerformanceCalculator::PERF_CIBLE_DEPASSEE],
        ];
    }

    public function test_quantitative_performance_realise_sur_prevu(): void
    {
        $action = new Action();
        $action->type_action = Action::TYPE_QUANTITATIVE;
        $action->quantite_cible = 100;
        $action->quantite_realisee = 40;

        $this->assertSame(40.0, $this->calc->provisionalPerformance($action));
    }

    public function test_quantitative_sans_cible_devient_binaire(): void
    {
        $action = new Action();
        $action->type_action = Action::TYPE_QUANTITATIVE;
        $action->quantite_cible = 0;
        $action->quantite_realisee = 5;

        $this->assertSame(100.0, $this->calc->provisionalPerformance($action));
    }

    public function test_non_quantitative_est_binaire_sans_piece(): void
    {
        $action = new Action();
        $action->type_action = Action::TYPE_NON_QUANTITATIVE;
        $action->setRelation('justificatifs', collect());

        $this->assertSame(0.0, $this->calc->provisionalPerformance($action));
    }

    public function test_sub_action_quantitative_performance(): void
    {
        $sa = new SousAction();
        $sa->sub_action_type = SousAction::TYPE_QUANTITATIVE;
        $sa->cible_prevue = 200;
        $sa->quantite_realisee = 100;

        $this->assertSame(50.0, $this->calc->subActionPerformance($sa));
    }

    public function test_temporal_sans_echeance(): void
    {
        $action = new Action();
        $this->assertSame(
            ActionPerformanceCalculator::TEMPS_SANS_ECHEANCE,
            $this->calc->temporalStatus($action)
        );
    }

    public function test_temporal_dans_delai(): void
    {
        $action = new Action();
        $action->date_echeance = Carbon::now()->addMonths(2);

        $this->assertSame(
            ActionPerformanceCalculator::TEMPS_DANS_DELAI,
            $this->calc->temporalStatus($action)
        );
    }

    public function test_temporal_bientot_retard(): void
    {
        $action = new Action();
        $action->date_echeance = Carbon::now()->addDays(3);

        $this->assertSame(
            ActionPerformanceCalculator::TEMPS_BIENTOT_RETARD,
            $this->calc->temporalStatus($action)
        );
    }

    public function test_temporal_critique_si_jamais_demarree_et_depassee(): void
    {
        $action = new Action();
        $action->date_echeance = Carbon::now()->subDays(5);
        $action->quantite_realisee = 0;
        $action->progression_reelle = 0;
        $action->setRelation('justificatifs', collect());

        $this->assertSame(
            ActionPerformanceCalculator::TEMPS_CRITIQUE,
            $this->calc->temporalStatus($action)
        );
    }

    public function test_conformity_bloque_si_quantite_manquante_quantitative(): void
    {
        $action = new Action();
        $action->type_action = Action::TYPE_QUANTITATIVE;
        $action->quantite_realisee = 0;
        $action->justificatif_obligatoire = false;
        $action->requires_comment = false;
        $action->allows_difficulty = false;

        $result = $this->calc->actionConformity($action);
        $this->assertFalse($result['can_submit']);
        $this->assertContains('quantite', $result['missing']);
    }

    public function test_conformity_ok_quand_conditions_remplies(): void
    {
        $action = new Action();
        $action->type_action = Action::TYPE_QUANTITATIVE;
        $action->quantite_realisee = 10;
        $action->justificatif_obligatoire = false;
        $action->requires_comment = false;
        $action->allows_difficulty = false;

        $result = $this->calc->actionConformity($action, hasNewProof: true);
        $this->assertTrue($result['can_submit']);
        $this->assertSame([], $result['missing']);
    }
}
