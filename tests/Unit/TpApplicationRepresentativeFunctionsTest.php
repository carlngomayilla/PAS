<?php

namespace Tests\Unit;

use App\Models\Action;
use App\Services\Workflow\ActionPerformanceCalculator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TpApplicationRepresentativeFunctionsTest extends TestCase
{
    private ActionPerformanceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new ActionPerformanceCalculator;
    }

    #[DataProvider('quantitativePerformanceProvider')]
    public function test_it_calculates_a_quantitative_action_performance(
        float $realized,
        float $target,
        float $expected,
    ): void {
        $action = new Action;
        $action->type_action = Action::TYPE_QUANTITATIVE;
        $action->quantite_realisee = $realized;
        $action->quantite_a_realiser = $target;

        $this->assertSame(
            $expected,
            $this->calculator->provisionalPerformance($action),
        );
    }

    /**
     * @return array<string, array{float, float, float}>
     */
    public static function quantitativePerformanceProvider(): array
    {
        return [
            'action non demarree' => [0.0, 100.0, 0.0],
            'realisation partielle' => [40.0, 100.0, 40.0],
            'cible atteinte' => [100.0, 100.0, 100.0],
            'depassement plafonne' => [125.0, 100.0, 100.0],
            'cible nulle invalide' => [25.0, 0.0, 0.0],
        ];
    }

    #[DataProvider('performanceStatusProvider')]
    public function test_it_classifies_performance_at_business_boundaries(
        float $percent,
        string $expectedStatus,
    ): void {
        $this->assertSame(
            $expectedStatus,
            $this->calculator->performanceStatus($percent),
        );
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function performanceStatusProvider(): array
    {
        return [
            'non demarre a zero' => [0.0, ActionPerformanceCalculator::PERF_NON_DEMARRE],
            'critique juste avant 50' => [49.99, ActionPerformanceCalculator::PERF_CRITIQUE],
            'alerte a partir de 50' => [50.0, ActionPerformanceCalculator::PERF_ALERTE],
            'acceptable a partir de 80' => [80.0, ActionPerformanceCalculator::PERF_ACCEPTABLE],
            'cible atteinte a 100' => [100.0, ActionPerformanceCalculator::PERF_CIBLE_ATTEINTE],
            'cible depassee au-dessus de 100' => [100.01, ActionPerformanceCalculator::PERF_CIBLE_DEPASSEE],
        ];
    }

    #[DataProvider('temporalStatusProvider')]
    public function test_it_determines_the_temporal_status_of_an_action(
        ?string $deadline,
        float $realized,
        string $expectedStatus,
    ): void {
        $action = new Action;
        $action->date_echeance = $deadline;
        $action->quantite_realisee = $realized;
        $action->progression_reelle = 0;
        $action->setRelation('justificatifs', collect());

        $this->assertSame(
            $expectedStatus,
            $this->calculator->temporalStatus(
                $action,
                Carbon::parse('2026-07-23'),
            ),
        );
    }

    /**
     * @return array<string, array{string|null, float, string}>
     */
    public static function temporalStatusProvider(): array
    {
        return [
            'sans echeance' => [null, 0.0, ActionPerformanceCalculator::TEMPS_SANS_ECHEANCE],
            'echeance lointaine' => ['2026-08-15', 0.0, ActionPerformanceCalculator::TEMPS_DANS_DELAI],
            'echeance dans sept jours' => ['2026-07-30', 0.0, ActionPerformanceCalculator::TEMPS_BIENTOT_RETARD],
            'retard avec debut execution' => ['2026-07-22', 1.0, ActionPerformanceCalculator::TEMPS_EN_RETARD],
            'retard sans debut execution' => ['2026-07-22', 0.0, ActionPerformanceCalculator::TEMPS_CRITIQUE],
        ];
    }
}
