<?php

namespace App\Services\Meetings;

use App\Enums\MeetingApprovalDecision;
use App\Enums\MeetingApprovalLevel;
use App\Enums\MeetingStatus;
use App\Enums\MeetingType;
use App\Models\Meeting;
use App\Models\MeetingApproval;
use App\Models\MeetingPlan;
use App\Models\MeetingReport;
use App\Models\MeetingStatusHistory;
use App\Models\Service;
use App\Models\User;
use App\Services\Analytics\AnalyticsCacheVersionService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class MeetingWorkflowService
{
    public function __construct(
        private readonly MeetingAccessService $access,
        private readonly MeetingNotificationService $notifications,
        private readonly SecureJustificatifStorage $storage,
        private readonly AnalyticsCacheVersionService $cacheVersions
    ) {}

    /**
     * @param  array{direction_id:int,service_id:?int,meeting_type:string,year:int,month:int,expected_count:int}  $data
     */
    public function definePlan(array $data, User $actor): MeetingPlan
    {
        if (! $this->access->canDefinePlans($actor)) {
            throw new InvalidArgumentException('Seul le SCIQ peut définir les objectifs de réunions.');
        }

        $type = MeetingType::from((string) $data['meeting_type']);
        $directionId = (int) $data['direction_id'];
        $serviceId = $type->requiresService() ? (int) ($data['service_id'] ?? 0) : null;
        if ($type->requiresService() && $serviceId <= 0) {
            throw new InvalidArgumentException('Une réunion de service doit viser un service.');
        }
        if ($serviceId !== null && ! Service::query()
            ->whereKey($serviceId)
            ->where('direction_id', $directionId)
            ->where('actif', true)
            ->exists()) {
            throw new InvalidArgumentException('Le service sélectionné n’appartient pas à la direction concernée.');
        }

        $month = (int) $data['month'];
        $year = (int) $data['year'];
        $scopeKey = MeetingPlan::scopeKey($type, $directionId, $serviceId);

        $plan = DB::transaction(function () use ($data, $actor, $type, $directionId, $serviceId, $month, $year, $scopeKey): MeetingPlan {
            $plan = MeetingPlan::query()
                ->where('scope_key', $scopeKey)
                ->where('meeting_type', $type->value)
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if (! $plan instanceof MeetingPlan) {
                $plan = new MeetingPlan([
                    'direction_id' => $directionId,
                    'service_id' => $serviceId,
                    'scope_key' => $scopeKey,
                    'meeting_type' => $type,
                    'year' => $year,
                    'month' => $month,
                ]);
            }

            $plan->forceFill([
                'quarter' => MeetingPlan::quarterForMonth($month),
                'expected_count' => max(0, (int) $data['expected_count']),
                'created_by' => $actor->id,
            ])->save();

            Meeting::query()
                ->whereNull('meeting_plan_id')
                ->where('direction_id', $directionId)
                ->where('service_id', $serviceId)
                ->where('meeting_type', $type->value)
                ->where('year', $year)
                ->where('month', $month)
                ->update(['meeting_plan_id' => $plan->id]);

            $this->synchronizeExtraFlags($plan);

            return $plan->refresh();
        });

        $this->notifications->planPublished($plan);
        $this->cacheVersions->bumpAlerts();

        return $plan;
    }

    /**
     * @param  array{direction_id:int,service_id:?int,meeting_type:string,label:string,location:string,agenda?:?string,responsible_id:int,participant_ids?:list<int>,scheduled_date:string,scheduled_time:string}  $data
     */
    public function scheduleMeeting(array $data, User $actor): Meeting
    {
        $type = MeetingType::from((string) $data['meeting_type']);
        $directionId = (int) $data['direction_id'];
        $serviceId = $type->requiresService() ? (int) ($data['service_id'] ?? 0) : null;
        if (! $this->access->canScheduleFor($actor, $type, $directionId, $serviceId)) {
            throw new InvalidArgumentException('Vous ne pouvez pas programmer de réunion pour cette structure.');
        }
        if ($serviceId !== null && ! Service::query()
            ->whereKey($serviceId)
            ->where('direction_id', $directionId)
            ->where('actif', true)
            ->exists()) {
            throw new InvalidArgumentException('Le service sélectionné n’appartient pas à la direction concernée.');
        }

        $responsible = User::query()->where('is_active', true)->find((int) $data['responsible_id']);
        if (! $responsible instanceof User
            || ! $this->access->canScheduleFor($responsible, $type, $directionId, $serviceId)) {
            throw new InvalidArgumentException('Le responsable doit être le chef de la structure concernée ou son directeur.');
        }

        $participantIds = $this->normalizedIds($data['participant_ids'] ?? []);
        $participants = User::query()->whereIn('id', $participantIds)->get(['id', 'direction_id', 'is_active']);
        if ($participants->count() !== count($participantIds)
            || $participants->contains(fn (User $participant): bool => ! $participant->is_active
                || (int) $participant->direction_id !== $directionId)) {
            throw new InvalidArgumentException('Tous les participants doivent être actifs et appartenir à la direction concernée.');
        }

        $date = Carbon::parse((string) $data['scheduled_date'])->startOfDay();
        $time = trim((string) $data['scheduled_time']);
        $scheduledAt = Carbon::parse($date->toDateString().' '.$time);
        if ($scheduledAt->isPast()) {
            throw new InvalidArgumentException('Une réunion ne peut pas être programmée dans le passé.');
        }

        $meeting = DB::transaction(function () use ($data, $actor, $type, $directionId, $serviceId, $date, $time, $participantIds): Meeting {
            $plan = $this->lockedPlanFor($type, $directionId, $serviceId, (int) $date->year, (int) $date->month);
            $scheduledCount = $plan instanceof MeetingPlan
                ? Meeting::query()
                    ->where('meeting_plan_id', $plan->id)
                    ->where('status', '!=', MeetingStatus::Annulee->value)
                    ->lockForUpdate()
                    ->get(['id'])
                    ->count()
                : 0;

            $meeting = Meeting::query()->create([
                'meeting_plan_id' => $plan?->id,
                'direction_id' => $directionId,
                'service_id' => $serviceId,
                'meeting_type' => $type->value,
                'label' => trim((string) $data['label']),
                'location' => trim((string) $data['location']),
                'agenda' => $this->nullableText($data['agenda'] ?? null),
                'participant_ids' => $participantIds,
                'responsible_id' => (int) $data['responsible_id'],
                'year' => (int) $date->year,
                'quarter' => MeetingPlan::quarterForMonth((int) $date->month),
                'month' => (int) $date->month,
                'original_scheduled_date' => $date->toDateString(),
                'current_scheduled_date' => $date->toDateString(),
                'scheduled_time' => $time,
                'status' => MeetingStatus::Programmee->value,
                'is_extra' => ! $plan instanceof MeetingPlan || $scheduledCount >= (int) $plan->expected_count,
                'created_by' => $actor->id,
            ]);

            $this->log($meeting, null, null, MeetingStatus::Programmee, $actor, 'Réunion programmée.');

            return $meeting->refresh();
        });

        $this->notifications->meetingScheduled($meeting);
        $this->cacheVersions->bumpAlerts();

        return $meeting;
    }

    public function postponeMeeting(Meeting $meeting, string $newDate, string $newTime, string $reason, User $actor): Meeting
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Le motif du report est obligatoire.');
        }

        $date = Carbon::parse($newDate)->startOfDay();
        $scheduledAt = Carbon::parse($date->toDateString().' '.$newTime);
        if ($scheduledAt->isPast()) {
            throw new InvalidArgumentException('La nouvelle date doit être située dans le futur.');
        }

        $previousDate = null;
        $updated = DB::transaction(function () use ($meeting, $date, $newTime, $reason, $actor, &$previousDate): Meeting {
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();
            if (! $this->access->canPostpone($actor, $locked)) {
                throw new InvalidArgumentException('Vous ne pouvez pas reporter cette réunion.');
            }
            if ($locked->hasOccurred() && ! $this->access->isAdministrator($actor)) {
                throw new InvalidArgumentException('Une réunion échue ne peut plus être reportée. Déposez son PV ou contactez un administrateur.');
            }
            if ($locked->scheduledAt()?->gte(Carbon::parse($date->toDateString().' '.$newTime))) {
                throw new InvalidArgumentException('La nouvelle programmation doit être postérieure à la date actuelle.');
            }

            $this->assertTransitionAllowed($locked->status, MeetingStatus::Reportee);
            $oldStatus = $locked->status;
            $previousDate = $locked->current_scheduled_date?->toDateString();
            $oldPlan = $locked->plan()->lockForUpdate()->first();
            $newPlan = $this->lockedPlanFor(
                $locked->meeting_type,
                (int) $locked->direction_id,
                $locked->service_id !== null ? (int) $locked->service_id : null,
                (int) $date->year,
                (int) $date->month
            );
            $scheduledCount = $newPlan instanceof MeetingPlan
                ? Meeting::query()
                    ->where('meeting_plan_id', $newPlan->id)
                    ->whereKeyNot($locked->id)
                    ->where('status', '!=', MeetingStatus::Annulee->value)
                    ->lockForUpdate()
                    ->get(['id'])
                    ->count()
                : 0;

            $locked->forceFill([
                'meeting_plan_id' => $newPlan?->id,
                'current_scheduled_date' => $date->toDateString(),
                'scheduled_time' => $newTime,
                'year' => (int) $date->year,
                'month' => (int) $date->month,
                'quarter' => MeetingPlan::quarterForMonth((int) $date->month),
                'status' => MeetingStatus::Reportee->value,
                'is_extra' => ! $newPlan instanceof MeetingPlan || $scheduledCount >= (int) $newPlan->expected_count,
                'was_postponed' => true,
                'postponement_count' => (int) $locked->postponement_count + 1,
            ])->save();

            $this->log($locked, null, $oldStatus, MeetingStatus::Reportee, $actor, $reason, [
                'ancienne_date' => $previousDate,
                'nouvelle_date' => $date->toDateString(),
            ]);

            if ($oldPlan instanceof MeetingPlan) {
                $this->synchronizeExtraFlags($oldPlan);
            }
            if ($newPlan instanceof MeetingPlan) {
                $this->synchronizeExtraFlags($newPlan);
            }

            return $locked->refresh();
        });

        $this->notifications->meetingPostponed($updated, $previousDate, $reason);
        $this->cacheVersions->bumpAlerts();

        return $updated;
    }

    public function cancelMeeting(Meeting $meeting, string $reason, User $actor): Meeting
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException("Le motif d'annulation est obligatoire.");
        }

        $updated = DB::transaction(function () use ($meeting, $reason, $actor): Meeting {
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();
            if (! $this->access->canCancel($actor, $locked)) {
                throw new InvalidArgumentException('Vous ne pouvez pas annuler cette réunion.');
            }
            if ($locked->hasOccurred() && ! $this->access->isAdministrator($actor)) {
                throw new InvalidArgumentException('Une réunion échue ne peut plus être annulée.');
            }

            $this->assertTransitionAllowed($locked->status, MeetingStatus::Annulee);
            $old = $locked->status;
            $locked->forceFill([
                'status' => MeetingStatus::Annulee->value,
                'cancellation_reason' => trim($reason),
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ])->save();

            $this->log($locked, null, $old, MeetingStatus::Annulee, $actor, $reason);
            $plan = $locked->plan()->lockForUpdate()->first();
            if ($plan instanceof MeetingPlan) {
                $this->synchronizeExtraFlags($plan);
            }

            return $locked->refresh();
        });

        $this->notifications->meetingCancelled($updated, $reason);
        $this->cacheVersions->bumpAlerts();

        return $updated;
    }

    /**
     * @param  array{observation?:?string,summary:string,actual_agenda?:?string,decisions?:?string,recommendations?:?string,difficulties?:?string,observations?:?string}  $data
     */
    public function submitReport(Meeting $meeting, UploadedFile $file, array $data, User $actor): MeetingReport
    {
        $storedPath = null;

        try {
            $report = DB::transaction(function () use ($meeting, $file, $data, $actor, &$storedPath): MeetingReport {
                $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();
                if (! $this->access->canSubmitReport($actor, $locked)) {
                    throw new InvalidArgumentException('Vous ne pouvez pas déposer de PV pour cette réunion.');
                }
                if (! $locked->hasOccurred()) {
                    throw new InvalidArgumentException('Le PV ne peut être déposé qu’après l’heure prévue de la réunion.');
                }
                if ($locked->status->isUnderReview()) {
                    throw new InvalidArgumentException('Un PV est déjà en cours de validation pour cette réunion.');
                }

                $this->assertTransitionAllowed($locked->status, MeetingStatus::EnValidationSciq);
                $checksum = $file->getRealPath() !== false ? hash_file('sha256', $file->getRealPath()) : null;
                $stored = $this->storage->store($file, 'meetings/pv/'.date('Y/m'));
                $storedPath = $stored['path'];
                $version = (int) MeetingReport::query()->where('meeting_id', $locked->id)->max('version') + 1;

                $report = MeetingReport::query()->create([
                    'meeting_id' => $locked->id,
                    'file_path' => $stored['path'],
                    'original_file_name' => $stored['nom_original'],
                    'file_size' => $stored['taille_octets'],
                    'mime_type' => $stored['mime_type'],
                    'checksum' => $checksum !== false ? $checksum : null,
                    'is_encrypted' => $stored['est_chiffre'],
                    'version' => $version,
                    'status' => MeetingStatus::EnValidationSciq->value,
                    'observation' => $this->nullableText($data['observation'] ?? null),
                    'summary' => trim((string) $data['summary']),
                    'actual_agenda' => $this->nullableText($data['actual_agenda'] ?? null),
                    'decisions' => $this->nullableText($data['decisions'] ?? null),
                    'recommendations' => $this->nullableText($data['recommendations'] ?? null),
                    'difficulties' => $this->nullableText($data['difficulties'] ?? null),
                    'observations' => $this->nullableText($data['observations'] ?? null),
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => now(),
                ]);

                $old = $locked->status;
                $locked->forceFill([
                    'status' => MeetingStatus::EnValidationSciq->value,
                    'held_at' => $locked->held_at ?? now(),
                ])->save();
                $this->log($locked, $report, $old, MeetingStatus::EnValidationSciq, $actor, 'PV déposé (version '.$version.').');

                return $report->refresh();
            });
        } catch (Throwable $exception) {
            $this->storage->deleteByPath($storedPath);

            throw $exception;
        }

        $this->notifications->reportSubmitted($report->meeting, $report);
        $this->cacheVersions->bumpAlerts();

        return $report;
    }

    public function review(
        MeetingReport $report,
        MeetingApprovalDecision $decision,
        ?string $comment,
        User $actor
    ): MeetingReport {
        if ($decision === MeetingApprovalDecision::CorrectionRequested && trim((string) $comment) === '') {
            throw new InvalidArgumentException('Le motif est obligatoire pour demander une correction.');
        }

        $outcome = null;
        $updated = DB::transaction(function () use ($report, $decision, $comment, $actor, &$outcome): MeetingReport {
            $locked = MeetingReport::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
            $meeting = Meeting::query()->whereKey($locked->meeting_id)->lockForUpdate()->firstOrFail();
            $expected = $locked->pendingLevel();

            if (! $expected instanceof MeetingApprovalLevel || ! $this->access->canReviewAt($actor, $expected)) {
                throw new InvalidArgumentException("Vous n'êtes pas autorisé à poser le visa attendu.");
            }
            if ($locked->isLocked()) {
                throw new InvalidArgumentException('Ce PV est définitivement validé et verrouillé.');
            }
            if ($meeting->status !== $locked->status || (int) $meeting->currentReport()->value('id') !== (int) $locked->id) {
                throw new InvalidArgumentException('Seule la version courante du PV peut être instruite.');
            }
            if ((int) $locked->uploaded_by === (int) $actor->id) {
                throw new InvalidArgumentException('Un contrôleur ne peut pas valider son propre dépôt.');
            }
            if (MeetingApproval::query()
                ->where('meeting_report_id', $locked->id)
                ->where('reviewer_id', $actor->id)
                ->exists()) {
                throw new InvalidArgumentException('Deux visas distincts doivent être posés par deux personnes différentes.');
            }

            MeetingApproval::query()->create([
                'meeting_report_id' => $locked->id,
                'approval_level' => $expected->value,
                'decision' => $decision->value,
                'comment' => $this->nullableText($comment),
                'reviewer_id' => $actor->id,
                'reviewed_at' => now(),
            ]);

            $old = $meeting->status;
            if ($decision === MeetingApprovalDecision::CorrectionRequested) {
                $this->assertTransitionAllowed($old, MeetingStatus::ACorriger);
                $locked->forceFill(['status' => MeetingStatus::ACorriger->value])->save();
                $meeting->forceFill(['status' => MeetingStatus::ACorriger->value])->save();
                $this->log($meeting, $locked, $old, MeetingStatus::ACorriger, $actor, $comment);
                $outcome = MeetingApprovalDecision::CorrectionRequested;

                return $locked->refresh();
            }

            $next = $expected->validatedStatus();
            $this->assertTransitionAllowed($old, $next);
            $locked->forceFill([
                'status' => $next->value,
                'locked_at' => $next === MeetingStatus::ValideeDefinitivement ? now() : null,
            ])->save();
            $meeting->forceFill([
                'status' => $next->value,
                'validated_at' => $next === MeetingStatus::ValideeDefinitivement ? now() : null,
            ])->save();
            $this->log($meeting, $locked, $old, $next, $actor, $comment);
            $outcome = $next;

            return $locked->refresh();
        });

        $meeting = $updated->meeting;
        if ($outcome === MeetingApprovalDecision::CorrectionRequested) {
            $this->notifications->correctionRequested($meeting, $updated, (string) $comment);
        } elseif ($outcome === MeetingStatus::ValideeDefinitivement) {
            $this->notifications->reportValidated($meeting, $updated);
        } else {
            $this->notifications->reportAwaitingPlanification($meeting, $updated);
        }
        $this->cacheVersions->bumpAlerts();

        return $updated;
    }

    public function markDueMeetingsAsAwaitingReport(?Carbon $reference = null): int
    {
        $reference = $reference?->copy() ?? now();
        $count = 0;
        $dueMeetings = collect();

        Meeting::query()
            ->whereIn('status', [MeetingStatus::Programmee->value, MeetingStatus::Reportee->value])
            ->whereDate('current_scheduled_date', '<=', $reference->toDateString())
            ->orderBy('id')
            ->chunkById(200, function ($meetings) use ($reference, &$count, $dueMeetings): void {
                foreach ($meetings as $meeting) {
                    DB::transaction(function () use ($meeting, $reference, &$count, $dueMeetings): void {
                        $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
                        if (! $locked instanceof Meeting || ! $locked->hasOccurred($reference)
                            || ! $locked->status->canTransitionTo(MeetingStatus::PvAttendu)) {
                            return;
                        }

                        $old = $locked->status;
                        $locked->forceFill(['status' => MeetingStatus::PvAttendu->value])->save();
                        $this->log($locked, null, $old, MeetingStatus::PvAttendu, null, 'Échéance dépassée sans PV déposé.');
                        $dueMeetings->push($locked->fresh());
                        $count++;
                    });
                }
            });

        if ($count > 0) {
            $dueMeetings->each(fn (Meeting $meeting): int => $this->notifications->reportExpected($meeting));
            $this->cacheVersions->bumpAlerts();
        }

        return $count;
    }

    private function lockedPlanFor(MeetingType $type, int $directionId, ?int $serviceId, int $year, int $month): ?MeetingPlan
    {
        return MeetingPlan::query()
            ->where('scope_key', MeetingPlan::scopeKey($type, $directionId, $serviceId))
            ->where('meeting_type', $type->value)
            ->where('year', $year)
            ->where('month', $month)
            ->lockForUpdate()
            ->first();
    }

    private function synchronizeExtraFlags(MeetingPlan $plan): void
    {
        Meeting::query()
            ->where('meeting_plan_id', $plan->id)
            ->where('status', '!=', MeetingStatus::Annulee->value)
            ->orderBy('original_scheduled_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'is_extra'])
            ->values()
            ->each(function (Meeting $meeting, int $index) use ($plan): void {
                $isExtra = $index >= (int) $plan->expected_count;
                if ((bool) $meeting->is_extra !== $isExtra) {
                    $meeting->forceFill(['is_extra' => $isExtra])->save();
                }
            });
    }

    private function assertTransitionAllowed(MeetingStatus $from, MeetingStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw new InvalidArgumentException(
                'Transition refusée : « '.$from->label().' » ne peut pas devenir « '.$to->label().' ». '
            );
        }
    }

    /** @param array<string, mixed> $context */
    private function log(
        Meeting $meeting,
        ?MeetingReport $report,
        ?MeetingStatus $old,
        MeetingStatus $new,
        ?User $actor,
        ?string $comment = null,
        array $context = []
    ): void {
        MeetingStatusHistory::query()->create([
            'meeting_id' => $meeting->id,
            'meeting_report_id' => $report?->id,
            'old_status' => $old?->value,
            'new_status' => $new->value,
            'comment' => $this->nullableText($comment),
            'context' => $context !== [] ? $context : null,
            'changed_by' => $actor?->id,
            'changed_at' => now(),
        ]);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /** @return list<int> */
    private function normalizedIds(mixed $ids): array
    {
        return collect(is_array($ids) ? $ids : [])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
