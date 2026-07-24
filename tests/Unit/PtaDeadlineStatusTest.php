<?php

namespace Tests\Unit;

use App\Enums\StatutEcheance;
use App\Enums\StatutRetard;
use App\Models\Action;
use App\Services\PtaOfficialCalculationService;
use Tests\TestCase;

class PtaDeadlineStatusTest extends TestCase
{
    public function test_action_is_due_when_end_date_is_report_date(): void
    {
        $action = new Action(['date_fin' => '2026-03-31']);

        $status = (new PtaOfficialCalculationService)->deadlineStatus($action, '2026-03-31');

        $this->assertSame(StatutEcheance::Echue, $status);
    }

    public function test_action_is_not_due_before_end_date(): void
    {
        $action = new Action(['date_fin' => '2026-04-01']);

        $status = (new PtaOfficialCalculationService)->deadlineStatus($action, '2026-03-31');

        $this->assertSame(StatutEcheance::NonEchue, $status);
    }

    public function test_due_action_under_configured_threshold_is_late(): void
    {
        $action = new Action([
            'date_fin' => '2026-03-31',
            'seuil_minimum' => 100,
        ]);

        $status = (new PtaOfficialCalculationService)->delayStatus($action, 99.99, '2026-03-31');

        $this->assertSame(StatutRetard::EnRetard, $status);
    }

    public function test_due_action_at_configured_threshold_stays_on_time(): void
    {
        $action = new Action([
            'date_fin' => '2026-03-31',
            'seuil_minimum' => 80,
        ]);

        $status = (new PtaOfficialCalculationService)->delayStatus($action, 80.0, '2026-03-31');

        $this->assertSame(StatutRetard::DansLesDelais, $status);
    }

    public function test_not_due_action_under_one_hundred_stays_on_time(): void
    {
        $action = new Action(['date_fin' => '2026-04-01']);

        $status = (new PtaOfficialCalculationService)->delayStatus($action, 40.0, '2026-03-31');

        $this->assertSame(StatutRetard::DansLesDelais, $status);
    }

    public function test_due_completed_action_stays_on_time_for_late_status(): void
    {
        $action = new Action(['date_fin' => '2026-03-31']);

        $status = (new PtaOfficialCalculationService)->delayStatus($action, 100.0, '2026-03-31');

        $this->assertSame(StatutRetard::DansLesDelais, $status);
    }
}
