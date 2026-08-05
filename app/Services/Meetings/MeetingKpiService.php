<?php

namespace App\Services\Meetings;

use App\Enums\MeetingApprovalDecision;
use App\Enums\MeetingApprovalLevel;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingApproval;
use App\Models\MeetingPlan;
use App\Models\MeetingReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Indicateurs du module de reunions.
 *
 * Une reunion n'est comptee comme officiellement tenue qu'apres validation du
 * PV par le SCIQ puis par la Planification (regle metier 12).
 */
class MeetingKpiService
{
    /**
     * @param  array{year?:int|null,quarter?:int|null,month?:int|null,direction_id?:int|null,service_id?:int|null}  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        $meetings = $this->meetingQuery($filters)->get();
        $expected = (int) $this->planQuery($filters)->sum('expected_count');

        $scheduled = $meetings->reject(fn (Meeting $m): bool => $m->status === MeetingStatus::Annulee)->count();
        $extra = $meetings->filter(fn (Meeting $m): bool => (bool) $m->is_extra)->count();
        $validated = $meetings->filter(fn (Meeting $m): bool => $m->isOfficiallyHeld())->count();
        $postponed = $meetings->filter(fn (Meeting $m): bool => (bool) $m->was_postponed)->count();
        $cancelled = $meetings->filter(fn (Meeting $m): bool => $m->status === MeetingStatus::Annulee)->count();
        $due = $meetings->filter(fn (Meeting $m): bool => $m->isDue() && $m->status !== MeetingStatus::Annulee)->count();
        $withReport = $meetings->filter(fn (Meeting $m): bool => $m->reports_count > 0)->count();
        $withoutReport = $meetings->filter(fn (Meeting $m): bool => $m->status === MeetingStatus::PvAttendu)->count();
        $toCorrect = $meetings->filter(fn (Meeting $m): bool => $m->status === MeetingStatus::ACorriger)->count();
        $awaitingSciq = $meetings->filter(fn (Meeting $m): bool => $m->status === MeetingStatus::EnValidationSciq)->count();
        $awaitingPlanification = $meetings->filter(fn (Meeting $m): bool => $m->status === MeetingStatus::EnValidationPlanification)->count();

        $delays = $this->validationDelays($meetings->pluck('id')->all());

        return [
            // Programmation
            'expected' => $expected,
            'scheduled' => $scheduled,
            'remaining_to_schedule' => max(0, $expected - $scheduled),
            'extra' => $extra,
            'scheduling_rate' => $this->rate($scheduled, $expected),

            // Realisation
            'due' => $due,
            'with_report' => $withReport,
            'validated' => $validated,
            'postponed' => $postponed,
            'cancelled' => $cancelled,
            'without_report' => $withoutReport,
            'to_correct' => $toCorrect,

            // Validation
            'awaiting_sciq' => $awaitingSciq,
            'awaiting_planification' => $awaitingPlanification,
            'returned_for_correction' => $this->correctionCount($meetings->pluck('id')->all()),
            'average_delay_sciq' => $delays['sciq'],
            'average_delay_planification' => $delays['planification'],
            'average_delay_total' => $delays['total'],

            // Taux officiels
            'realization_rate' => $this->rate($validated, $expected),
            'attendance_rate' => $this->rate($validated, $scheduled),
            'postponement_rate' => $this->rate($postponed, $scheduled),
            'justification_rate' => $this->rate($withReport, $due),
        ];
    }

    /**
     * Suivi des objectifs par structure et par mois, avec le reste a programmer.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function planProgress(array $filters = []): array
    {
        $plans = $this->planQuery($filters)
            ->with(['direction:id,code,libelle', 'service:id,code,libelle'])
            ->orderBy('direction_id')
            ->orderBy('service_id')
            ->orderBy('month')
            ->get();

        $counts = Meeting::query()
            ->whereIn('meeting_plan_id', $plans->pluck('id')->filter()->all())
            ->where('status', '!=', MeetingStatus::Annulee->value)
            ->selectRaw('meeting_plan_id, count(*) as total')
            ->groupBy('meeting_plan_id')
            ->pluck('total', 'meeting_plan_id');

        return $plans->map(function (MeetingPlan $plan) use ($counts): array {
            $scheduled = (int) ($counts[$plan->id] ?? 0);
            $expected = (int) $plan->expected_count;

            return [
                'id' => (int) $plan->id,
                'structure' => $plan->structureLabel(),
                'meeting_type' => $plan->meeting_type->label(),
                'year' => (int) $plan->year,
                'quarter' => (int) $plan->quarter,
                'month' => (int) $plan->month,
                'expected' => $expected,
                'scheduled' => $scheduled,
                'remaining' => max(0, $expected - $scheduled),
                'rate' => $this->rate($scheduled, $expected),
            ];
        })->values()->all();
    }

    /**
     * Delais moyens de validation, en jours.
     *
     * @param  list<int>  $meetingIds
     * @return array{sciq:?float,planification:?float,total:?float}
     */
    private function validationDelays(array $meetingIds): array
    {
        if ($meetingIds === []) {
            return ['sciq' => null, 'planification' => null, 'total' => null];
        }

        $reports = MeetingReport::query()
            ->whereIn('meeting_id', $meetingIds)
            ->with('approvals')
            ->get();

        $sciq = [];
        $planification = [];
        $total = [];

        foreach ($reports as $report) {
            $uploadedAt = $report->uploaded_at;
            if (! $uploadedAt instanceof Carbon) {
                continue;
            }

            $sciqApproval = $report->approvals
                ->first(fn (MeetingApproval $a): bool => $a->approval_level === MeetingApprovalLevel::Sciq
                    && $a->decision === MeetingApprovalDecision::Validated);

            $planApproval = $report->approvals
                ->first(fn (MeetingApproval $a): bool => $a->approval_level === MeetingApprovalLevel::Planification
                    && $a->decision === MeetingApprovalDecision::Validated);

            if ($sciqApproval?->reviewed_at instanceof Carbon) {
                $sciq[] = $uploadedAt->diffInDays($sciqApproval->reviewed_at, false);
            }

            if ($sciqApproval?->reviewed_at instanceof Carbon && $planApproval?->reviewed_at instanceof Carbon) {
                $planification[] = $sciqApproval->reviewed_at->diffInDays($planApproval->reviewed_at, false);
            }

            if ($planApproval?->reviewed_at instanceof Carbon) {
                $total[] = $uploadedAt->diffInDays($planApproval->reviewed_at, false);
            }
        }

        return [
            'sciq' => $this->average($sciq),
            'planification' => $this->average($planification),
            'total' => $this->average($total),
        ];
    }

    /** @param list<int> $meetingIds */
    private function correctionCount(array $meetingIds): int
    {
        if ($meetingIds === []) {
            return 0;
        }

        return MeetingApproval::query()
            ->where('decision', MeetingApprovalDecision::CorrectionRequested->value)
            ->whereHas('report', fn (Builder $query) => $query->whereIn('meeting_id', $meetingIds))
            ->count();
    }

    /** @param array<string, mixed> $filters */
    public function meetingQuery(array $filters = []): Builder
    {
        $query = Meeting::query()->withCount('reports');

        return $this->applyFilters($query, $filters);
    }

    /** @param array<string, mixed> $filters */
    private function planQuery(array $filters = []): Builder
    {
        return $this->applyFilters(MeetingPlan::query(), $filters);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['year', 'quarter', 'month', 'direction_id', 'service_id'] as $key) {
            $value = $filters[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== 'all') {
                $query->where($key, $value);
            }
        }

        return $query;
    }

    /** @param list<float|int> $values */
    private function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 1);
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : 0.0;
    }
}
