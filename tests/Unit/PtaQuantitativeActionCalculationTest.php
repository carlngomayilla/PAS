<?php

namespace Tests\Unit;

use App\Enums\StatutRealisation;
use App\Enums\TypeIndicateur;
use App\Models\Action;
use App\Services\PtaOfficialCalculationService;
use PHPUnit\Framework\TestCase;

class PtaQuantitativeActionCalculationTest extends TestCase
{
    public function test_quantitative_rate_is_capped_and_raw_rate_is_kept(): void
    {
        $action = new Action([
            'type_indicateur' => TypeIndicateur::Quantitatif->value,
            'quantite_a_realiser' => 100,
        ]);
        $action->quantite_realisee = 125;
        $action->setRelation('sousActions', collect());

        $result = (new PtaOfficialCalculationService)->actionResult($action);

        $this->assertSame(125.0, $result['raw_rate']);
        $this->assertSame(100.0, $result['rate']);
        $this->assertSame(100.0, $result['display_rate']);
        $this->assertSame(StatutRealisation::Realisee->value, $result['statut_realisation']);
    }

    public function test_quantitative_without_target_stays_to_configure(): void
    {
        $action = new Action([
            'type_indicateur' => TypeIndicateur::Quantitatif->value,
            'quantite_a_realiser' => 0,
        ]);
        $action->quantite_realisee = 50;
        $action->setRelation('sousActions', collect());

        $result = (new PtaOfficialCalculationService)->actionResult($action);

        $this->assertNull($result['rate']);
        $this->assertTrue($result['excluded']);
        $this->assertSame(StatutRealisation::AParametrer->value, $result['statut_realisation']);
    }

    public function test_quantitative_with_zero_realized_is_not_started(): void
    {
        $action = new Action([
            'type_indicateur' => TypeIndicateur::Quantitatif->value,
            'quantite_a_realiser' => 100,
        ]);
        $action->quantite_realisee = 0;
        $action->setRelation('sousActions', collect());

        $result = (new PtaOfficialCalculationService)->actionResult($action);

        $this->assertSame(0.0, $result['rate']);
        $this->assertSame(StatutRealisation::NonDemarree->value, $result['statut_realisation']);
    }
}
