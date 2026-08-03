<?php

namespace App\Services;

use App\Models\InstitutionalMeetingDecision;
use App\Models\InstitutionalReport;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstitutionalReportingService
{
    public function canView(User $user): bool
    {
        return $user->hasPermission('reporting.read');
    }

    public function canSubmit(User $user): bool
    {
        return $this->canView($user) && ! $user->hasRole(User::ROLE_AUDITEUR, User::ROLE_INVITE_LECTURE);
    }

    public function canScheduleMeeting(User $user): bool
    {
        return $this->canSubmit($user)
            && $user->hasRole(User::ROLE_SERVICE, User::ROLE_DIRECTION);
    }

    public function canViewReport(User $user, InstitutionalReport $report): bool
    {
        if (! $this->canView($user)) {
            return false;
        }

        if ($this->hasGlobalReviewScope($user) || (int) $report->submitted_by === (int) $user->id) {
            return true;
        }

        if ($user->service_id !== null && (int) $report->service_id === (int) $user->service_id) {
            return true;
        }

        return $user->direction_id !== null && (int) $report->direction_id === (int) $user->direction_id;
    }

    public function canAmend(User $user, InstitutionalReport $report): bool
    {
        return $this->canSubmit($user)
            && (int) $report->submitted_by === (int) $user->id
            && in_array($report->status, [InstitutionalReport::STATUS_DRAFT, InstitutionalReport::STATUS_RETURNED], true);
    }

    public function canPostponeMeeting(User $user, InstitutionalReport $report): bool
    {
        if ($report->report_type !== InstitutionalReport::TYPE_MEETING
            || $report->scheduled_at === null
            || $report->held_at !== null
            || $report->cancelled_at !== null
            || $report->status !== InstitutionalReport::STATUS_DRAFT) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION)
            && $user->direction_id !== null
            && (int) $user->direction_id === (int) $report->direction_id) {
            return true;
        }

        return $report->service_id !== null
            && $user->hasRole(User::ROLE_SERVICE)
            && $user->service_id !== null
            && (int) $user->service_id === (int) $report->service_id;
    }

    public function canPublishMeetingMinutes(User $user, InstitutionalReport $report): bool
    {
        if ($report->report_type !== InstitutionalReport::TYPE_MEETING
            || $report->cancelled_at !== null
            || ! in_array($report->status, [InstitutionalReport::STATUS_DRAFT, InstitutionalReport::STATUS_RETURNED], true)) {
            return false;
        }

        return $this->canAmend($user, $report) || $this->canManageMeetingScope($user, $report);
    }

    public function canManageMeetingDecisions(User $user, InstitutionalReport $report): bool
    {
        return $report->report_type === InstitutionalReport::TYPE_MEETING
            && $report->held_at !== null
            && $report->cancelled_at === null
            && ($this->canPublishMeetingMinutes($user, $report) || $this->canManageMeetingScope($user, $report));
    }

    public function canUpdateMeetingDecision(User $user, InstitutionalReport $report, InstitutionalMeetingDecision $decision): bool
    {
        if ((int) $decision->institutional_report_id !== (int) $report->id
            || $report->report_type !== InstitutionalReport::TYPE_MEETING
            || $report->cancelled_at !== null) {
            return false;
        }

        return $this->canManageMeetingDecisions($user, $report)
            || (int) $decision->responsible_id === (int) $user->id;
    }

    public function canReview(User $user, InstitutionalReport $report): bool
    {
        return match ((string) $report->status) {
            InstitutionalReport::STATUS_SUBMITTED_SCIQ => $user->hasRole(User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL),
            InstitutionalReport::STATUS_SUBMITTED_PLANNING => $user->hasRole(User::ROLE_PLANIFICATION),
            InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF => $user->hasRole(User::ROLE_CHEF_UNITE_SCIQ),
            InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF => $user->hasRole(User::ROLE_CHEF_PLANIFICATION),
            default => false,
        };
    }

    public function canReviewAnything(User $user): bool
    {
        return $user->hasRole(
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_CHEF_PLANIFICATION,
        );
    }

    public function canExportMeetingReports(User $user): bool
    {
        return $this->canView($user) && $user->hasRole(
            User::ROLE_SUPER_ADMIN,
            User::ROLE_ADMIN,
            User::ROLE_DG,
            User::ROLE_DIRECTION,
            User::ROLE_SERVICE,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): InstitutionalReport
    {
        if (! $this->canSubmit($actor)) {
            abort(403, 'Votre profil ne peut pas deposer de rapport institutionnel.');
        }

        $scope = $this->resolveSubmissionScope($payload, $actor);
        $isMeeting = (string) $payload['report_type'] === InstitutionalReport::TYPE_MEETING;
        if ($isMeeting && ! $this->canScheduleMeeting($actor)) {
            abort(403, 'Seul le chef de service ou le directeur du périmètre peut programmer une réunion.');
        }
        if ($isMeeting) {
            if (($payload['meeting_type'] ?? null) === InstitutionalReport::MEETING_TYPE_DIRECTION
                && ! $actor->hasRole(User::ROLE_DIRECTION)) {
                abort(403, 'Seul le directeur peut programmer une réunion de direction.');
            }
            if (($payload['meeting_type'] ?? null) === InstitutionalReport::MEETING_TYPE_SERVICE
                && ! $actor->hasRole(User::ROLE_SERVICE, User::ROLE_DIRECTION)) {
                abort(403, 'Seul le chef de service ou le directeur peut programmer une réunion de service.');
            }
            $this->assertMeetingScope($payload, $scope);
            $this->assertMeetingParticipants($payload, $scope);
        }

        return InstitutionalReport::query()->create([
            'report_type' => (string) $payload['report_type'],
            'meeting_type' => $isMeeting ? (string) $payload['meeting_type'] : null,
            'title' => $this->nullableText($payload['title'] ?? null) ?? $this->defaultMeetingTitle($payload),
            'summary' => $this->nullableText($payload['summary'] ?? null),
            'direction_id' => $scope['direction_id'],
            'service_id' => $scope['service_id'],
            'responsible_id' => $isMeeting ? (int) $payload['responsible_id'] : null,
            'scheduled_at' => $payload['scheduled_at'] ?? null,
            'original_scheduled_at' => $isMeeting ? ($payload['scheduled_at'] ?? null) : null,
            'location' => $isMeeting ? $this->nullableText($payload['location'] ?? null) : null,
            'participant_ids' => $isMeeting ? $this->normalizedParticipantIds($payload['participant_ids'] ?? []) : null,
            'held_at' => $payload['held_at'] ?? null,
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $actor->id,
            'review_history' => [$this->historyEntry($actor, 'created', 'Dossier cree ou reunion programmee.')],
        ]);
    }

    public function submit(InstitutionalReport $report, User $actor): InstitutionalReport
    {
        return DB::transaction(function () use ($report, $actor): InstitutionalReport {
            $lockedReport = InstitutionalReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! $this->canAmend($actor, $lockedReport)) {
                abort(403, 'Seul le deposant peut soumettre ce rapport dans son etat actuel.');
            }

            if ($lockedReport->report_type === InstitutionalReport::TYPE_MEETING
                && $lockedReport->held_at === null) {
                throw ValidationException::withMessages([
                    'report' => 'Renseignez la date de tenue de la reunion avant de deposer son compte rendu.',
                ]);
            }

            if (! $lockedReport->justificatifs()->exists()) {
                throw ValidationException::withMessages([
                    'attachment' => 'Ajoutez le compte rendu, le rapport ou la piece justificative avant la soumission.',
                ]);
            }

            $lockedReport->forceFill([
                'status' => InstitutionalReport::STATUS_SUBMITTED_SCIQ,
                'submitted_at' => now(),
                'returned_at' => null,
                'review_history' => $this->appendHistory($lockedReport, $actor, 'submitted_sciq', 'Soumis au SCIQ pour verification.'),
            ])->save();

            return $lockedReport->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resubmit(InstitutionalReport $report, array $payload, User $actor): InstitutionalReport
    {
        return DB::transaction(function () use ($report, $payload, $actor): InstitutionalReport {
            $lockedReport = InstitutionalReport::query()->lockForUpdate()->findOrFail($report->id);
            $canAmend = $this->canAmend($actor, $lockedReport)
                || $this->canPublishMeetingMinutes($actor, $lockedReport);
            if (! $canAmend) {
                abort(403, 'Ce rapport ne peut plus etre corrige avec votre profil.');
            }

            if (! $lockedReport->justificatifs()->exists()) {
                throw ValidationException::withMessages([
                    'attachment' => 'Une piece jointe est requise avant la nouvelle soumission.',
                ]);
            }

            $heldAt = $this->nullableText($payload['held_at'] ?? null);
            if ($lockedReport->report_type === InstitutionalReport::TYPE_MEETING && $lockedReport->held_at === null && $heldAt === null) {
                throw ValidationException::withMessages([
                    'held_at' => 'Indiquez la date effective de la réunion avant de déposer son compte rendu.',
                ]);
            }

            $lockedReport->forceFill([
                'summary' => $this->nullableText($payload['summary'] ?? $lockedReport->summary),
                'held_at' => $heldAt ?? $lockedReport->held_at,
                'actual_agenda' => $this->nullableText($payload['actual_agenda'] ?? $lockedReport->actual_agenda),
                'decisions' => $this->nullableText($payload['decisions'] ?? $lockedReport->decisions),
                'recommendations' => $this->nullableText($payload['recommendations'] ?? $lockedReport->recommendations),
                'difficulties' => $this->nullableText($payload['difficulties'] ?? $lockedReport->difficulties),
                'observations' => $this->nullableText($payload['observations'] ?? $lockedReport->observations),
                'status' => InstitutionalReport::STATUS_SUBMITTED_SCIQ,
                'submitted_at' => now(),
                'minutes_published_at' => $lockedReport->report_type === InstitutionalReport::TYPE_MEETING ? now() : $lockedReport->minutes_published_at,
                'returned_at' => null,
                'review_history' => $this->appendHistory($lockedReport, $actor, 'resubmitted', 'Correction deposee et transmise au SCIQ.'),
            ])->save();

            return $lockedReport->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array{scheduled_at:string,reason:string}  $payload
     */
    public function postponeMeeting(InstitutionalReport $report, array $payload, User $actor): InstitutionalReport
    {
        return DB::transaction(function () use ($report, $payload, $actor): InstitutionalReport {
            $lockedReport = InstitutionalReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! $this->canPostponeMeeting($actor, $lockedReport)) {
                abort(403, 'Seul le chef du service concerné ou le directeur peut reporter cette réunion avant sa tenue.');
            }

            $originalDate = $lockedReport->original_scheduled_at ?? $lockedReport->scheduled_at;
            $newDate = Carbon::parse((string) $payload['scheduled_at']);
            if (! $originalDate instanceof Carbon || ! $newDate->betweenIncluded($originalDate->copy()->startOfQuarter(), $originalDate->copy()->endOfQuarter())) {
                throw ValidationException::withMessages([
                    'scheduled_at' => 'La réunion reportée doit rester dans le même trimestre que sa programmation initiale.',
                ]);
            }

            $lockedReport->forceFill([
                'original_scheduled_at' => $originalDate,
                'scheduled_at' => $newDate,
                'postponed_at' => now(),
                'postponed_by' => $actor->id,
                'postponement_reason' => trim((string) $payload['reason']),
                'postponement_count' => (int) $lockedReport->postponement_count + 1,
                'review_history' => $this->appendHistory(
                    $lockedReport,
                    $actor,
                    'meeting_postponed',
                    'Réunion reportée dans le trimestre.',
                    trim((string) $payload['reason'])
                ),
            ])->save();

            return $lockedReport->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array{reason:string}  $payload
     */
    public function cancelMeeting(InstitutionalReport $report, array $payload, User $actor): InstitutionalReport
    {
        return DB::transaction(function () use ($report, $payload, $actor): InstitutionalReport {
            $lockedReport = InstitutionalReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! $this->canPostponeMeeting($actor, $lockedReport)) {
                abort(403, 'Seul le chef du service concerné ou le directeur peut annuler cette réunion avant sa tenue.');
            }

            $lockedReport->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => trim((string) $payload['reason']),
                'review_history' => $this->appendHistory(
                    $lockedReport,
                    $actor,
                    'meeting_cancelled',
                    'Réunion annulée.',
                    trim((string) $payload['reason'])
                ),
            ])->save();

            return $lockedReport->refresh();
        }, attempts: 3);
    }

    public function review(InstitutionalReport $report, string $decision, string $note, User $actor): InstitutionalReport
    {
        return DB::transaction(function () use ($report, $decision, $note, $actor): InstitutionalReport {
            $lockedReport = InstitutionalReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! $this->canReview($actor, $lockedReport)) {
                abort(403, 'Ce rapport n est pas dans votre file de verification.');
            }

            $nextStatus = $decision === 'return'
                ? InstitutionalReport::STATUS_RETURNED
                : $this->nextStatus((string) $lockedReport->status);
            $event = $decision === 'return' ? 'returned' : 'approved';
            $message = $decision === 'return'
                ? 'Retour au deposant pour correction.'
                : 'Verification realisee: transmission a l etape suivante.';

            $lockedReport->forceFill([
                'status' => $nextStatus,
                'verified_at' => $nextStatus === InstitutionalReport::STATUS_VERIFIED ? now() : null,
                'returned_at' => $decision === 'return' ? now() : null,
                'review_history' => $this->appendHistory($lockedReport, $actor, $event, $message, $note),
            ])->save();

            return $lockedReport->refresh();
        }, attempts: 3);
    }

    /**
     * @return Builder<InstitutionalReport>
     */
    public function visibleQuery(User $user): Builder
    {
        $query = InstitutionalReport::query();

        if ($this->hasGlobalReviewScope($user)) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($user): void {
            $scope->where('submitted_by', $user->id);
            if ($user->service_id !== null) {
                $scope->orWhere('service_id', $user->service_id);
            }
            if ($user->direction_id !== null) {
                $scope->orWhere('direction_id', $user->direction_id);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<InstitutionalReport>
     */
    public function filteredVisibleQuery(User $user, array $filters): Builder
    {
        $query = $this->visibleQuery($user);
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(fn (Builder $reportsQuery) => $reportsQuery
                ->whereLike('title', $search)
                ->orWhereLike('summary', $search)
                ->orWhereLike('actual_agenda', $search)
                ->orWhereLike('decisions', $search));
        }

        foreach (['direction_id', 'service_id', 'responsible_id'] as $field) {
            $value = $this->positiveInteger($filters[$field] ?? null);
            if ($value !== null) {
                $query->where($field, $value);
            }
        }

        $year = $this->positiveInteger($filters['year'] ?? null);
        if ($year !== null) {
            $query->whereYear('scheduled_at', $year);
        }
        $month = $this->positiveInteger($filters['month'] ?? null);
        if ($month !== null && $month <= 12) {
            $query->whereMonth('scheduled_at', $month);
        }
        $quarter = $this->positiveInteger($filters['quarter'] ?? null);
        if ($quarter !== null && $quarter <= 4) {
            $quarterYear = $year ?? now()->year;
            $quarterStart = Carbon::create($quarterYear, (($quarter - 1) * 3) + 1, 1)->startOfQuarter();
            $query->whereBetween('scheduled_at', [$quarterStart, $quarterStart->copy()->endOfQuarter()]);
        }

        $meetingType = (string) ($filters['meeting_type'] ?? '');
        if (in_array($meetingType, [InstitutionalReport::MEETING_TYPE_SERVICE, InstitutionalReport::MEETING_TYPE_DIRECTION], true)) {
            $query->where('report_type', InstitutionalReport::TYPE_MEETING)->where('meeting_type', $meetingType);
        }
        $participantId = $this->positiveInteger($filters['participant_id'] ?? null);
        if ($participantId !== null) {
            $query->where('report_type', InstitutionalReport::TYPE_MEETING)
                ->whereJsonContains('participant_ids', $participantId);
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, [
            InstitutionalReport::STATUS_DRAFT,
            InstitutionalReport::STATUS_SUBMITTED_SCIQ,
            InstitutionalReport::STATUS_SUBMITTED_PLANNING,
            InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF,
            InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF,
            InstitutionalReport::STATUS_VERIFIED,
            InstitutionalReport::STATUS_RETURNED,
        ], true)) {
            $query->where('status', $status);
        }
        if ($status === 'cancelled') {
            $query->where('report_type', InstitutionalReport::TYPE_MEETING)->whereNotNull('cancelled_at');
        }
        if ($status === 'postponed') {
            $query->where('report_type', InstitutionalReport::TYPE_MEETING)->whereNotNull('postponed_at')->whereNull('cancelled_at');
        }
        if ($status === 'held') {
            $query->where('report_type', InstitutionalReport::TYPE_MEETING)->whereNotNull('held_at')->whereNull('cancelled_at');
        }
        if ($status === 'overdue') {
            $query->where('report_type', InstitutionalReport::TYPE_MEETING)->whereNotNull('scheduled_at')->where('scheduled_at', '<', now())->whereNull('held_at')->whereNull('cancelled_at');
        }
        if ($status === 'minutes_pending') {
            $query->where('report_type', InstitutionalReport::TYPE_MEETING)->whereNotNull('held_at')->whereNull('minutes_published_at');
        }

        return $query;
    }

    /**
     * @return array{total:int,pending:int,verified:int,meetings_scheduled:int,meetings_held:int,meetings_overdue:int,meetings_on_time:int,meetings_late:int,meetings_postponed:int,meetings_cancelled:int,minutes_distributed:int,minutes_returned:int,meeting_decisions_open:int,meeting_completion_rate:float}
     */
    public function summaryFor(User $user, array $filters = []): array
    {
        $reports = $this->filteredVisibleQuery($user, $filters);
        $pastMeetings = (clone $reports)
            ->where('report_type', InstitutionalReport::TYPE_MEETING)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
        $meetingsHeld = (clone $pastMeetings)->whereNotNull('held_at')->count();
        $pastMeetingsCount = (clone $pastMeetings)->count();
        $visibleMeetingIds = (clone $reports)
            ->where('report_type', InstitutionalReport::TYPE_MEETING)
            ->select('id');

        return [
            'total' => (clone $reports)->count(),
            'pending' => (clone $reports)->whereIn('status', [
                InstitutionalReport::STATUS_SUBMITTED_SCIQ,
                InstitutionalReport::STATUS_SUBMITTED_PLANNING,
                InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF,
                InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF,
            ])->count(),
            'verified' => (clone $reports)->where('status', InstitutionalReport::STATUS_VERIFIED)->count(),
            'meetings_scheduled' => (clone $reports)
                ->where('report_type', InstitutionalReport::TYPE_MEETING)
                ->where('scheduled_at', '>=', now())
                ->whereNull('cancelled_at')
                ->count(),
            'meetings_held' => $meetingsHeld,
            'meetings_overdue' => (clone $reports)
                ->where('report_type', InstitutionalReport::TYPE_MEETING)
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<', now())
                ->whereNull('held_at')
                ->whereNull('cancelled_at')
                ->count(),
            'meetings_on_time' => (clone $reports)
                ->where('report_type', InstitutionalReport::TYPE_MEETING)
                ->whereNotNull('held_at')
                ->whereColumn('held_at', '<=', 'scheduled_at')
                ->count(),
            'meetings_late' => (clone $reports)
                ->where('report_type', InstitutionalReport::TYPE_MEETING)
                ->whereNotNull('held_at')
                ->whereColumn('held_at', '>', 'scheduled_at')
                ->count(),
            'meetings_postponed' => (clone $reports)
                ->where('report_type', InstitutionalReport::TYPE_MEETING)
                ->whereNotNull('postponed_at')
                ->count(),
            'meetings_cancelled' => (clone $reports)
                ->where('report_type', InstitutionalReport::TYPE_MEETING)
                ->whereNotNull('cancelled_at')
                ->count(),
            'minutes_distributed' => (clone $reports)
                ->where('report_type', InstitutionalReport::TYPE_MEETING)
                ->whereNotNull('submitted_at')
                ->count(),
            'minutes_returned' => (clone $reports)
                ->where('report_type', InstitutionalReport::TYPE_MEETING)
                ->where('status', InstitutionalReport::STATUS_RETURNED)
                ->count(),
            'meeting_decisions_open' => InstitutionalMeetingDecision::query()
                ->whereIn('institutional_report_id', $visibleMeetingIds)
                ->where('status', '!=', InstitutionalMeetingDecision::STATUS_COMPLETED)
                ->count(),
            'meeting_completion_rate' => $pastMeetingsCount > 0 ? round(($meetingsHeld / $pastMeetingsCount) * 100, 1) : 0.0,
        ];
    }

    /**
     * @return Collection<int, InstitutionalReport>
     */
    public function meetingReminderCandidates(int $daysBefore): Collection
    {
        return InstitutionalReport::query()
            ->where('report_type', InstitutionalReport::TYPE_MEETING)
            ->whereNull('held_at')
            ->whereNull('cancelled_at')
            ->whereDate('scheduled_at', now()->addDays($daysBefore)->toDateString())
            ->get();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            InstitutionalReport::STATUS_DRAFT => 'Programme / brouillon',
            InstitutionalReport::STATUS_SUBMITTED_SCIQ => 'Verification SCIQ',
            InstitutionalReport::STATUS_SUBMITTED_PLANNING => 'Verification Planification',
            InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF => 'Validation Chef SCIQ',
            InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF => 'Validation Chef Planification',
            InstitutionalReport::STATUS_VERIFIED => 'Verifie',
            InstitutionalReport::STATUS_RETURNED => 'Correction demandee',
            default => $status,
        };
    }

    public function meetingStateLabel(InstitutionalReport $report): string
    {
        if ($report->report_type !== InstitutionalReport::TYPE_MEETING) {
            return 'Non concerné';
        }

        if ($report->cancelled_at !== null) {
            return 'Annulée';
        }
        if ($report->held_at !== null) {
            return $report->scheduled_at !== null && $report->held_at->lte($report->scheduled_at)
                ? 'Tenue dans les délais'
                : 'Tenue hors délai';
        }
        if ($report->scheduled_at !== null && $report->scheduled_at->isPast()) {
            return 'Non tenue à échéance';
        }
        if ($report->postponed_at !== null) {
            return 'Reportée dans le trimestre';
        }

        return 'Programmée';
    }

    /**
     * @param  array{description:string,responsible_id?:int|null,priority:string,due_at?:string|null}  $payload
     */
    public function createMeetingDecision(InstitutionalReport $report, array $payload, User $actor): InstitutionalMeetingDecision
    {
        return DB::transaction(function () use ($report, $payload, $actor): InstitutionalMeetingDecision {
            $lockedReport = InstitutionalReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! $this->canManageMeetingDecisions($actor, $lockedReport)) {
                abort(403, 'Vous ne pouvez pas enregistrer de décision pour cette réunion.');
            }

            $responsibleId = $this->positiveInteger($payload['responsible_id'] ?? null);
            if ($responsibleId !== null) {
                $this->assertMeetingDecisionResponsible($responsibleId, $lockedReport);
            }
            if (! empty($payload['due_at']) && $lockedReport->held_at !== null
                && Carbon::parse((string) $payload['due_at'])->startOfDay()->lt($lockedReport->held_at->copy()->startOfDay())) {
                throw ValidationException::withMessages([
                    'due_at' => 'L’échéance ne peut pas être antérieure à la réunion.',
                ]);
            }

            return $lockedReport->meetingDecisions()->create([
                'description' => trim((string) $payload['description']),
                'responsible_id' => $responsibleId,
                'priority' => (string) $payload['priority'],
                'due_at' => $payload['due_at'] ?? null,
                'status' => InstitutionalMeetingDecision::STATUS_TO_DO,
                'created_by' => $actor->id,
            ]);
        }, attempts: 3);
    }

    /**
     * @param  array{status:string,follow_up_note?:string|null}  $payload
     */
    public function updateMeetingDecision(InstitutionalReport $report, InstitutionalMeetingDecision $decision, array $payload, User $actor): InstitutionalMeetingDecision
    {
        return DB::transaction(function () use ($report, $decision, $payload, $actor): InstitutionalMeetingDecision {
            $lockedReport = InstitutionalReport::query()->lockForUpdate()->findOrFail($report->id);
            $lockedDecision = InstitutionalMeetingDecision::query()->lockForUpdate()->findOrFail($decision->id);
            if (! $this->canUpdateMeetingDecision($actor, $lockedReport, $lockedDecision)) {
                abort(403, 'Vous ne pouvez pas mettre à jour cette décision.');
            }

            $status = (string) $payload['status'];
            $lockedDecision->forceFill([
                'status' => $status,
                'follow_up_note' => $this->nullableText($payload['follow_up_note'] ?? null),
                'completed_at' => $status === InstitutionalMeetingDecision::STATUS_COMPLETED
                    ? ($lockedDecision->completed_at ?? now())
                    : null,
            ])->save();

            return $lockedDecision->refresh();
        }, attempts: 3);
    }

    private function hasGlobalReviewScope(User $user): bool
    {
        return $user->hasGlobalReadAccess() || $user->hasRole(
            User::ROLE_DG,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ,
        );
    }

    private function canManageMeetingScope(User $user, InstitutionalReport $report): bool
    {
        if ($user->hasRole(User::ROLE_DIRECTION)
            && $user->direction_id !== null
            && (int) $user->direction_id === (int) $report->direction_id) {
            return true;
        }

        return $report->service_id !== null
            && $user->hasRole(User::ROLE_SERVICE)
            && $user->service_id !== null
            && (int) $user->service_id === (int) $report->service_id;
    }

    private function assertMeetingDecisionResponsible(int $responsibleId, InstitutionalReport $report): void
    {
        $responsible = User::query()->find($responsibleId, ['id', 'direction_id', 'service_id']);
        if (! $responsible instanceof User
            || (int) $responsible->direction_id !== (int) $report->direction_id
            || ($report->service_id !== null && (int) $responsible->service_id !== (int) $report->service_id)) {
            throw ValidationException::withMessages([
                'responsible_id' => 'Le responsable de la décision doit appartenir au périmètre de la réunion.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{direction_id:?int,service_id:?int}  $scope
     */
    private function assertMeetingScope(array $payload, array $scope): void
    {
        $meetingType = (string) ($payload['meeting_type'] ?? '');
        if ($meetingType === InstitutionalReport::MEETING_TYPE_SERVICE && $scope['service_id'] === null) {
            throw ValidationException::withMessages(['service_id' => 'Une réunion de service doit être rattachée à un service.']);
        }
        if ($meetingType === InstitutionalReport::MEETING_TYPE_DIRECTION && $scope['service_id'] !== null) {
            throw ValidationException::withMessages(['service_id' => 'Une réunion de direction doit être rattachée à la direction entière, sans service.']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{direction_id:?int,service_id:?int}  $scope
     */
    private function assertMeetingParticipants(array $payload, array $scope): void
    {
        $participantIds = $this->normalizedParticipantIds($payload['participant_ids'] ?? []);
        $responsibleId = $this->positiveInteger($payload['responsible_id'] ?? null);
        if ($responsibleId === null) {
            throw ValidationException::withMessages(['responsible_id' => 'Désignez le responsable de la réunion.']);
        }
        $userIds = array_values(array_unique([...$participantIds, $responsibleId]));
        $users = User::query()->whereIn('id', $userIds)->get(['id', 'direction_id', 'service_id']);
        if ($users->count() !== count($userIds)) {
            throw ValidationException::withMessages(['participant_ids' => 'Un participant ou le responsable est introuvable.']);
        }

        foreach ($users as $user) {
            $insideDirection = (int) $user->direction_id === (int) $scope['direction_id'];
            $insideService = $scope['service_id'] === null || (int) $user->service_id === (int) $scope['service_id'];
            if (! $insideDirection || ! $insideService) {
                throw ValidationException::withMessages(['participant_ids' => 'Le responsable et les participants doivent appartenir au périmètre de la réunion.']);
            }
        }
    }

    /** @return list<int> */
    private function normalizedParticipantIds(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{direction_id:?int,service_id:?int}
     */
    private function resolveSubmissionScope(array $payload, User $actor): array
    {
        $directionId = $this->positiveInteger($payload['direction_id'] ?? null);
        $serviceId = $this->positiveInteger($payload['service_id'] ?? null);

        if ($serviceId !== null) {
            $service = Service::query()->findOrFail($serviceId);
            if ($directionId !== null && $directionId !== (int) $service->direction_id) {
                throw ValidationException::withMessages(['service_id' => 'Le service selectionne ne correspond pas a la direction.']);
            }
            $directionId = (int) $service->direction_id;
        }

        if ($directionId === null) {
            $directionId = $actor->direction_id;
        }
        if ($serviceId === null && ! $this->hasGlobalReviewScope($actor)) {
            $serviceId = $actor->service_id;
        }

        if ($directionId === null) {
            throw ValidationException::withMessages(['direction_id' => 'Choisissez une direction pour ce rapport.']);
        }

        if (! $this->hasGlobalReviewScope($actor)) {
            if ($actor->direction_id !== null && $directionId !== (int) $actor->direction_id) {
                throw ValidationException::withMessages(['direction_id' => 'Vous ne pouvez deposer un rapport que dans votre direction.']);
            }
            if ($actor->service_id !== null && $serviceId !== null && $serviceId !== (int) $actor->service_id && ! $actor->hasRole(User::ROLE_DIRECTION)) {
                throw ValidationException::withMessages(['service_id' => 'Vous ne pouvez deposer un rapport que pour votre service.']);
            }
        }

        return ['direction_id' => $directionId, 'service_id' => $serviceId];
    }

    private function nextStatus(string $status): string
    {
        return match ($status) {
            InstitutionalReport::STATUS_SUBMITTED_SCIQ => InstitutionalReport::STATUS_SUBMITTED_PLANNING,
            InstitutionalReport::STATUS_SUBMITTED_PLANNING => InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF,
            InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF => InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF,
            InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF => InstitutionalReport::STATUS_VERIFIED,
            default => throw ValidationException::withMessages(['report' => 'Etape de verification invalide.']),
        };
    }

    /** @return array{at:string,user_id:int,user:string,role:string,event:string,message:string,note:?string} */
    private function historyEntry(User $actor, string $event, string $message, ?string $note = null): array
    {
        return [
            'at' => Carbon::now()->toIso8601String(),
            'user_id' => (int) $actor->id,
            'user' => (string) $actor->name,
            'role' => (string) $actor->roleLabel(),
            'event' => $event,
            'message' => $message,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function appendHistory(InstitutionalReport $report, User $actor, string $event, string $message, ?string $note = null): array
    {
        $history = is_array($report->review_history) ? $report->review_history : [];
        $history[] = $this->historyEntry($actor, $event, $message, $note);

        return $history;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /** @param array<string, mixed> $payload */
    private function defaultMeetingTitle(array $payload): string
    {
        if ((string) ($payload['report_type'] ?? '') !== InstitutionalReport::TYPE_MEETING) {
            return 'Rapport sans objet';
        }

        $type = (string) ($payload['meeting_type'] ?? 'service') === InstitutionalReport::MEETING_TYPE_DIRECTION
            ? 'direction'
            : 'service';
        $scheduledAt = isset($payload['scheduled_at']) ? Carbon::parse((string) $payload['scheduled_at'])->format('d/m/Y') : 'à programmer';

        return 'Réunion de '.$type.' du '.$scheduledAt;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
