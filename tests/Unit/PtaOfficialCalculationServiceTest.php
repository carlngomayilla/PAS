<?php

namespace Tests\Unit;

use App\Models\Action;
use App\Models\SousAction;
use App\Services\Actions\ActionTrackingService;
use App\Services\PtaOfficialCalculationService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class PtaOfficialCalculationServiceTest extends TestCase
{
    private PtaOfficialCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PtaOfficialCalculationService;
    }

    public function test_action_performance_uses_realized_over_target_without_simple_average(): void
    {
        $action = new Action([
            'quantite_cible' => 100,
        ]);
        $action->quantite_realisee = 60;
        $action->setRelation('sousActions', collect());

        $result = $this->service->actionResult($action);

        $this->assertTrue($result['is_configured']);
        $this->assertSame(100.0, $result['target']);
        $this->assertSame(60.0, $result['realized']);
        $this->assertSame(60.0, $result['rate']);
        $this->assertSame('en_cours', $result['status']);
    }

    public function test_action_composed_from_sub_actions_is_weighted_by_raw_targets(): void
    {
        $action = new Action;
        $action->setRelation('sousActions', collect([
            new SousAction(['cible_prevue' => 100, 'quantite_realisee' => 80]),
            new SousAction(['cible_prevue' => 20, 'quantite_realisee' => 10]),
        ]));

        $result = $this->service->actionResult($action);

        $this->assertSame(120.0, $result['target']);
        $this->assertSame(90.0, $result['realized']);
        $this->assertSame(75.0, $result['rate']);
    }

    public function test_non_quantitative_action_with_tracked_deliverable_without_quantity_is_configured(): void
    {
        $action = new Action([
            'type_action' => Action::TYPE_NON_QUANTITATIVE,
            'mode_evaluation' => Action::MODE_SANS_QUANTITE,
            'resultat_attendu' => 'Rapport valide',
        ]);
        $action->setRelation('sousActions', collect());

        $result = $this->service->actionResult($action);

        $this->assertTrue($result['is_configured']);
        $this->assertFalse($result['excluded']);
        $this->assertSame(1.0, $result['target']);
        $this->assertSame(0.0, $result['realized']);
        $this->assertSame(0.0, $result['rate']);
        $this->assertSame('en_attente', $result['status']);
    }

    public function test_non_quantitative_sub_action_with_validated_deliverable_without_quantity_is_done(): void
    {
        $sousAction = new SousAction([
            'sub_action_type' => SousAction::TYPE_NON_QUANTITATIVE,
            'resultat_attendu' => 'PV signe',
            'validation_status' => SousAction::VALIDATION_VALIDEE,
        ]);

        $result = $this->service->subActionResult($sousAction);

        $this->assertTrue($result['is_configured']);
        $this->assertSame(1.0, $result['target']);
        $this->assertSame(1.0, $result['realized']);
        $this->assertSame(100.0, $result['rate']);
        $this->assertSame('realise', $result['status']);
    }

    public function test_composite_action_combines_action_quantity_and_sub_action_targets(): void
    {
        $action = new Action([
            'type_action' => Action::TYPE_COMPOSEE,
            'mode_evaluation' => Action::MODE_SOUS_ACTIONS,
            'quantite_cible' => 100,
        ]);
        $action->quantite_realisee = 50;
        $action->setRelation('sousActions', collect([
            new SousAction([
                'sub_action_type' => SousAction::TYPE_QUANTITATIVE,
                'cible_prevue' => 20,
                'quantite_realisee' => 10,
            ]),
        ]));

        $result = $this->service->actionResult($action);

        $this->assertSame(120.0, $result['target']);
        $this->assertSame(60.0, $result['realized']);
        $this->assertSame(50.0, $result['rate']);
        $this->assertSame('en_cours', $result['status']);
    }

    public function test_validated_non_quantitative_action_deliverable_is_done_without_quantity(): void
    {
        $action = new Action([
            'type_action' => Action::TYPE_NON_QUANTITATIVE,
            'mode_evaluation' => Action::MODE_SANS_QUANTITE,
            'livrable_attendu' => 'Decision signee',
        ]);
        $action->statut_validation = ActionTrackingService::VALIDATION_VALIDEE_CHEF;
        $action->setRelation('sousActions', collect());

        $result = $this->service->actionResult($action);

        $this->assertTrue($result['is_configured']);
        $this->assertSame(100.0, $result['rate']);
        $this->assertSame('realise', $result['status']);
    }

    public function test_upper_level_rollup_excludes_items_without_target(): void
    {
        $result = $this->service->targetWeighted(new Collection([
            ['target' => 100, 'realized' => 80, 'is_configured' => true],
            ['target' => 0, 'realized' => 100, 'is_configured' => false],
            ['target' => 20, 'realized' => 10, 'is_configured' => true],
        ]));

        $this->assertSame(120.0, $result['target']);
        $this->assertSame(90.0, $result['realized']);
        $this->assertSame(75.0, $result['rate']);
    }

    public function test_missing_or_zero_target_is_to_configure_and_excluded(): void
    {
        $action = new Action;
        $action->quantite_cible = 0;
        $action->quantite_realisee = 50;
        $action->setRelation('sousActions', collect());

        $result = $this->service->actionResult($action);

        $this->assertFalse($result['is_configured']);
        $this->assertTrue($result['excluded']);
        $this->assertNull($result['rate']);
        $this->assertSame('a_parametrer', $result['status']);
        $this->assertSame('A parametrer', $result['status_label']);
    }

    public function test_institutional_consolidation_uses_weights(): void
    {
        $result = $this->service->institutionWeighted(new Collection([
            ['rate' => 80, 'weight' => 3, 'is_configured' => true],
            ['rate' => 50, 'weight' => 1, 'is_configured' => true],
            ['rate' => 100, 'weight' => 0, 'is_configured' => true],
        ]));

        $this->assertSame(4.0, $result['weight']);
        $this->assertSame(290.0, $result['weighted_points']);
        $this->assertSame(72.5, $result['rate']);
    }

    public function test_real_rate_can_exceed_one_hundred_while_display_rate_is_capped(): void
    {
        $action = new Action(['quantite_cible' => 100]);
        $action->quantite_realisee = 125;
        $action->setRelation('sousActions', collect());

        $result = $this->service->actionResult($action);

        $this->assertSame(125.0, $result['rate']);
        $this->assertSame(100.0, $result['display_rate']);
        $this->assertSame('realise', $result['status']);
    }
}
