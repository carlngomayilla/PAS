<?php

namespace Tests\Unit;

use App\Http\Controllers\DashboardController;
use App\Models\Action;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Support\Carbon;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class DashboardDelayStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_delay_status_prefers_action_deadline_over_planned_end_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16'));

        $action = new Action([
            'date_echeance' => Carbon::parse('2026-06-10'),
            'date_fin' => Carbon::parse('2026-06-30'),
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
        ]);

        $this->assertSame('En retard', $this->invokeDashboardMethod('delayStatusLabel', $action));
        $this->assertSame(6, $this->invokeDashboardMethod('delayDays', $action));
    }

    public function test_dashboard_delay_status_marks_near_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16'));

        $action = new Action([
            'date_echeance' => Carbon::parse('2026-06-20'),
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
        ]);

        $this->assertSame('Proche echeance', $this->invokeDashboardMethod('delayStatusLabel', $action));
        $this->assertSame(0, $this->invokeDashboardMethod('delayDays', $action));
    }

    public function test_dashboard_delay_status_marks_completed_late_from_validation_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16'));

        $action = new Action([
            'date_echeance' => Carbon::parse('2026-06-10'),
            'evalue_le' => Carbon::parse('2026-06-12 09:00:00'),
            'statut_dynamique' => ActionTrackingService::STATUS_CLOTUREE,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ]);

        $this->assertSame('Achevee hors delai', $this->invokeDashboardMethod('delayStatusLabel', $action));
        $this->assertSame(2, $this->invokeDashboardMethod('delayDays', $action));
    }

    private function invokeDashboardMethod(string $method, Action $action): mixed
    {
        $controller = (new ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, $action);
    }
}
