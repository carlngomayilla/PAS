<?php

namespace Tests\Unit;

use App\Enums\StatutRealisation;
use App\Enums\TypeIndicateur;
use App\Models\Action;
use App\Models\Justificatif;
use App\Services\PtaOfficialCalculationService;
use PHPUnit\Framework\TestCase;

class PtaNonQuantitativeActionSubmissionTest extends TestCase
{
    public function test_non_quantitative_with_proof_is_one_hundred_without_quantity(): void
    {
        $action = new Action([
            'type_indicateur' => TypeIndicateur::NonQuantitatif->value,
            'cible' => 'Decision signee',
            'livrable_attendu' => 'Decision signee',
            'quantite_a_realiser' => null,
        ]);
        $action->quantite_realisee = 0;
        $action->setRelation('sousActions', collect());
        $action->setRelation('justificatifs', collect([new Justificatif]));

        $result = (new PtaOfficialCalculationService)->actionResult($action);

        $this->assertTrue($result['is_configured']);
        $this->assertSame(100.0, $result['rate']);
        $this->assertSame(StatutRealisation::Realisee->value, $result['statut_realisation']);
    }

    public function test_non_quantitative_without_proof_never_requires_quantity(): void
    {
        $action = new Action([
            'type_indicateur' => TypeIndicateur::NonQuantitatif->value,
            'cible' => 'Rapport final disponible',
            'livrable_attendu' => 'Rapport final',
            'quantite_a_realiser' => null,
        ]);
        $action->setRelation('sousActions', collect());
        $action->setRelation('justificatifs', collect());

        $result = (new PtaOfficialCalculationService)->actionResult($action);

        $this->assertTrue($result['is_configured']);
        $this->assertSame(1.0, $result['target']);
        $this->assertSame(0.0, $result['rate']);
        $this->assertSame(StatutRealisation::NonDemarree->value, $result['statut_realisation']);
    }
}
