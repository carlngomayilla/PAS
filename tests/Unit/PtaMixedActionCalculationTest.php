<?php

namespace Tests\Unit;

use App\Enums\TypeIndicateur;
use App\Models\Action;
use App\Models\Justificatif;
use App\Services\PtaOfficialCalculationService;
use PHPUnit\Framework\TestCase;

class PtaMixedActionCalculationTest extends TestCase
{
    public function test_mixed_action_averages_quantity_and_deliverable_dimensions(): void
    {
        $action = new Action([
            'type_indicateur' => TypeIndicateur::Mixte->value,
            'quantite_a_realiser' => 100,
            'livrable_attendu' => 'Rapport valide',
        ]);
        $action->quantite_realisee = 50;
        $action->setRelation('sousActions', collect());
        $action->setRelation('justificatifs', collect());

        $result = (new PtaOfficialCalculationService)->actionResult($action);

        $this->assertSame(25.0, $result['rate']);
        $this->assertSame(25.0, $result['display_rate']);
        $this->assertSame('mixed_targets', $result['source']);
    }

    public function test_mixed_action_counts_validated_deliverable_as_second_dimension(): void
    {
        $action = new Action([
            'type_indicateur' => TypeIndicateur::Mixte->value,
            'quantite_a_realiser' => 100,
            'livrable_attendu' => 'Rapport valide',
        ]);
        $action->quantite_realisee = 50;
        $action->setRelation('sousActions', collect());
        $action->setRelation('justificatifs', collect([new Justificatif]));

        $result = (new PtaOfficialCalculationService)->actionResult($action);

        $this->assertSame(75.0, $result['rate']);
    }
}
