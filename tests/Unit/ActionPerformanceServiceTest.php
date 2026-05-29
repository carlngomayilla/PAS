<?php

namespace Tests\Unit;

use App\Models\Action;
use App\Services\ActionPerformanceService;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActionPerformanceServiceTest extends TestCase
{
    public function test_quantitative_performance_is_capped_but_real_quantity_is_kept(): void
    {
        $action = new Action([
            'mode_evaluation' => Action::MODE_QUANTITATIF,
            'quantite_cible' => 100,
            'quantite_realisee' => 150,
        ]);
        $action->setRelation('sousActions', collect());

        $this->assertSame(100.0, app(ActionPerformanceService::class)->calculateRealProgress($action));
        $this->assertSame(150.0, (float) $action->quantite_realisee);
    }

    public function test_delay_kpi_uses_agent_submission_date_not_chef_review_date(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $service = app(ActionPerformanceService::class);
        $action = new Action([
            'date_fin' => '2026-06-30',
            'date_echeance' => '2026-06-30',
            'soumise_le' => '2026-06-30 09:00:00',
            'evalue_le' => '2026-07-05 09:00:00',
        ]);

        $this->assertSame(100.0, $service->calculateDelayScore($action));

        Carbon::setTestNow();
    }

    public function test_non_quantified_action_counts_as_complete_when_submitted_with_proof_logic(): void
    {
        $action = new Action([
            'mode_evaluation' => Action::MODE_SANS_QUANTITE,
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
        ]);
        $action->setRelation('sousActions', collect());

        $this->assertSame(100.0, app(ActionPerformanceService::class)->calculateRealProgress($action));
    }
}
