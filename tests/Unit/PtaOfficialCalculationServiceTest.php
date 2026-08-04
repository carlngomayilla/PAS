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

    public function test_action_status_uses_configured_completion_threshold(): void
    {
        $action = new Action([
            'quantite_cible' => 100,
            'seuil_minimum' => 80,
        ]);
        $action->quantite_realisee = 80;
        $action->setRelation('sousActions', collect());

        $result = $this->service->actionResult($action);

        $this->assertSame(80.0, $result['rate']);
        $this->assertSame('realise', $result['status']);
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
        $action->statut_validation = ActionTrackingService::VALIDATION_VALIDEE_CONTROLE;
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
        $this->assertSame('À paramétrer', $result['status_label']);
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

    public function test_rate_is_capped_at_one_hundred_and_raw_rate_is_available(): void
    {
        $action = new Action(['quantite_cible' => 100]);
        $action->quantite_realisee = 125;
        $action->setRelation('sousActions', collect());

        $result = $this->service->actionResult($action);

        $this->assertSame(125.0, $result['raw_rate']);
        $this->assertSame(100.0, $result['rate']);
        $this->assertSame(100.0, $result['display_rate']);
        $this->assertSame('realise', $result['status']);
    }

    /**
     * Exemple institutionnel de reference (note metier du 2026-08-04).
     *
     * 1 axe, 2 objectifs strategiques, 4 objectifs operationnels, 8 actions.
     * Chaque enfant a le meme poids et sa cible de performance vaut 100 %.
     */
    public function test_hierarchy_reproduces_the_institutional_reference_example(): void
    {
        $action = static fn (float $rate, bool $due, bool $done): array => [
            'taux_realisation' => $rate,
            'calcul_configured' => true,
            'calcul_cible' => 100.0,
            'calcul_realise' => $rate,
            'est_echue' => $due,
            'est_realisee' => $done,
        ];

        $a1 = $action(80.0, true, false);
        $a2 = $action(100.0, true, true);
        $a3 = $action(60.0, true, false);
        $a4 = $action(100.0, true, true);
        $a5 = $action(100.0, true, true);
        $a6 = $action(100.0, true, true);
        $a7 = $action(50.0, false, false);
        $a8 = $action(100.0, false, true);

        $op1 = $this->service->targetWeightedRows(collect([$a1, $a2]), 'oo');
        $op2 = $this->service->targetWeightedRows(collect([$a3, $a4]), 'oo');
        $op3 = $this->service->targetWeightedRows(collect([$a5, $a6]), 'oo');
        $op4 = $this->service->targetWeightedRows(collect([$a7, $a8]), 'oo');

        $this->assertSame(90.0, $op1['rate']);
        $this->assertSame(80.0, $op2['rate']);
        $this->assertSame(100.0, $op3['rate']);
        $this->assertSame(75.0, $op4['rate']);

        $node = static fn (array $result): array => [
            'taux_realisation' => $result['rate'],
            'calcul_configured' => $result['is_configured'],
            'calcul_cible' => $result['target'],
            'calcul_realise' => $result['realized'],
        ];

        $os1 = $this->service->targetWeightedRows(collect([$node($op1), $node($op2)]), 'os');
        $os2 = $this->service->targetWeightedRows(collect([$node($op3), $node($op4)]), 'os');

        $this->assertSame(85.0, $os1['rate']);
        $this->assertSame(87.5, $os2['rate']);

        $axis = $this->service->targetWeightedRows(collect([$node($os1), $node($os2)]), 'axe');

        $this->assertSame(86.25, $axis['rate']);

        $all = collect([$a1, $a2, $a3, $a4, $a5, $a6, $a7, $a8]);

        $execution = $this->service->executionRate($all);
        $this->assertSame(66.67, $execution['rate']);
        $this->assertSame(4, $execution['done']);
        $this->assertSame(6, $execution['due']);

        $completion = $this->service->globalCompletionRate($all);
        $this->assertSame(62.5, $completion['rate']);
        $this->assertSame(5, $completion['done']);
        $this->assertSame(8, $completion['due']);
    }

    public function test_hierarchy_gives_every_child_the_same_weight_whatever_its_target(): void
    {
        // Une action de cible 1 000 ne doit pas peser plus qu'une action de cible 10.
        $rows = collect([
            ['taux_realisation' => 100.0, 'calcul_configured' => true, 'calcul_cible' => 10.0, 'calcul_realise' => 10.0],
            ['taux_realisation' => 0.0, 'calcul_configured' => true, 'calcul_cible' => 1000.0, 'calcul_realise' => 0.0],
        ]);

        $result = $this->service->targetWeightedRows($rows, 'oo');

        $this->assertSame(50.0, $result['rate']);
        // Les cumuls restent disponibles a titre informatif.
        $this->assertSame(1010.0, $result['target']);
        $this->assertSame(10.0, $result['realized']);
    }

    public function test_hierarchy_excludes_unconfigured_children(): void
    {
        $rows = collect([
            ['taux_realisation' => 60.0, 'calcul_configured' => true, 'calcul_cible' => 10.0, 'calcul_realise' => 6.0],
            ['taux_realisation' => null, 'calcul_configured' => false, 'calcul_cible' => 0.0, 'calcul_realise' => 0.0],
        ]);

        $result = $this->service->targetWeightedRows($rows, 'oo');

        $this->assertSame(60.0, $result['rate']);
    }

    public function test_pas_indicators_return_null_when_nothing_is_due(): void
    {
        $rows = collect([
            ['est_echue' => false, 'est_realisee' => false],
        ]);

        $execution = $this->service->executionRate($rows);

        $this->assertNull($execution['rate']);
        $this->assertFalse($execution['is_configured']);
    }
}
