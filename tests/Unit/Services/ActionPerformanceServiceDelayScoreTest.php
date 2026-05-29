<?php

namespace Tests\Unit\Services;

use App\Models\Action;
use App\Services\ActionPerformanceService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tests unitaires de la formule graduee du KPI Delai (spec v2, 28/05/2026).
 *
 * Formule : KPI_delai = max(0, 100 - (retard_jours / duree_prevue * 100))
 *
 * Couvre :
 *   - soumis a temps        -> 100
 *   - soumis en retard      -> degradation proportionnelle a la duree prevue
 *   - retard egal a la duree-> 0
 *   - jamais soumis, echue  -> 0
 *   - pas encore echue      -> 100 (non penalise)
 *   - flag binary conservé pour rollback rapide
 */
class ActionPerformanceServiceDelayScoreTest extends TestCase
{
    private function service(): ActionPerformanceService
    {
        return app(ActionPerformanceService::class);
    }

    /**
     * Construit une Action non persistee avec les champs date_debut / date_fin /
     * date_echeance / soumise_le requis par la formule.
     */
    private function makeAction(array $attributes = []): Action
    {
        $action = new Action;
        $action->forceFill(array_merge([
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-11',           // duree prevue = 10 jours
            'date_echeance' => '2026-01-11',
            'soumise_le' => null,
        ], $attributes));

        return $action;
    }

    public function test_submitted_on_time_returns_100(): void
    {
        config(['kpis.delay.mode' => 'graduated']);

        $action = $this->makeAction([
            'soumise_le' => Carbon::parse('2026-01-10 14:00:00'),
        ]);

        $this->assertSame(
            100.0,
            $this->service()->calculateDelayScore($action, Carbon::parse('2026-01-11'))
        );
    }

    public function test_submitted_one_day_late_on_ten_day_action_returns_90(): void
    {
        config(['kpis.delay.mode' => 'graduated']);

        $action = $this->makeAction([
            'soumise_le' => Carbon::parse('2026-01-12'),
        ]);

        // retard = 1 jour, duree prevue = 10 jours -> 100 - 10 = 90.
        $this->assertSame(
            90.0,
            $this->service()->calculateDelayScore($action, Carbon::parse('2026-01-13'))
        );
    }

    public function test_submitted_five_days_late_on_ten_day_action_returns_50(): void
    {
        config(['kpis.delay.mode' => 'graduated']);

        $action = $this->makeAction([
            'soumise_le' => Carbon::parse('2026-01-16'),
        ]);

        // retard = 5 jours, duree prevue = 10 jours -> 100 - 50 = 50.
        $this->assertSame(
            50.0,
            $this->service()->calculateDelayScore($action, Carbon::parse('2026-01-17'))
        );
    }

    public function test_retard_greater_than_duration_is_clamped_to_zero(): void
    {
        config(['kpis.delay.mode' => 'graduated']);

        $action = $this->makeAction([
            'soumise_le' => Carbon::parse('2026-02-15'),  // 35 jours de retard.
        ]);

        $this->assertSame(
            0.0,
            $this->service()->calculateDelayScore($action, Carbon::parse('2026-02-16'))
        );
    }

    public function test_never_submitted_and_overdue_returns_zero(): void
    {
        config(['kpis.delay.mode' => 'graduated']);

        $action = $this->makeAction([
            'soumise_le' => null,
        ]);

        $this->assertSame(
            0.0,
            $this->service()->calculateDelayScore($action, Carbon::parse('2026-02-01'))
        );
    }

    public function test_not_yet_overdue_and_not_submitted_returns_100(): void
    {
        config(['kpis.delay.mode' => 'graduated']);

        $action = $this->makeAction([
            'soumise_le' => null,
        ]);

        $this->assertSame(
            100.0,
            $this->service()->calculateDelayScore($action, Carbon::parse('2026-01-05'))
        );
    }

    public function test_binary_mode_returns_zero_for_any_late_submission(): void
    {
        config(['kpis.delay.mode' => 'binary']);

        $action = $this->makeAction([
            'soumise_le' => Carbon::parse('2026-01-12'),  // un seul jour de retard.
        ]);

        $this->assertSame(
            0.0,
            $this->service()->calculateDelayScore($action, Carbon::parse('2026-01-13'))
        );
    }

    public function test_fallback_penalty_applies_when_date_debut_is_missing(): void
    {
        config(['kpis.delay.mode' => 'graduated']);
        config(['kpis.delay.fallback_daily_penalty' => 5.0]);

        $action = $this->makeAction([
            'date_debut' => null,
            'date_fin' => '2026-01-11',
            'date_echeance' => '2026-01-11',
            'soumise_le' => Carbon::parse('2026-01-14'),  // 3 jours de retard.
        ]);

        // 3 jours * 5% = 15 de penalite -> 85.
        $this->assertSame(
            85.0,
            $this->service()->calculateDelayScore($action, Carbon::parse('2026-01-15'))
        );
    }
}
