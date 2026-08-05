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
use App\Models\User;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Coeur metier du module de reunions.
 *
 * Toutes les transitions passent par `assertTransitionAllowed()`, qui refuse
 * tout saut non declare dans la machine a etats : le circuit ne peut pas etre
 * court-circuite. Chaque operation sensible est verrouillee en base et tracee.
 */
class MeetingWorkflowService
{
    public function __construct(
        private readonly MeetingAccessService $access,
        private readonly MeetingNotificationService $notifications,
        private readonly SecureJustificatifStorage $storage
    ) {}

    /**
     * Definit ou met a jour l'objectif d'un mois. Seul le SCIQ y a acces.
     *
     * @param  array{direction_id:int,service_id:?int,meeting_type:string,year:int,month:int,expected_count:int}  $data
     */
    public function definePlan(array $data, User $actor): MeetingPlan
    {
        if (! $this->access->canDefinePlans($actor)) {
            throw new InvalidArgumentException('Seul le SCIQ definit le nombre de reunions attendu.');
        }

        $type = MeetingType::from((string) $data['meeting_type']);
        $serviceId = $type->requiresService() ? ($data['service_id'] ?? null) : null;

        if ($type->requiresService() && $serviceId === null) {
            throw new InvalidArgumentException('Une reunion de service doit viser un service.');
        }

        $month = (int) $data['month'];

        return MeetingPlan::query()->updateOrCreate(
            [
                'direction_id' => (int) $data['direction_id'],
                'service_id' => $serviceId !== null ? (int) $serviceId : null,
                'meeting_type' => $type->value,
                'year' => (int) $data['year'],
                'month' => $month,
            ],
            [
                'quarter' => MeetingPlan::quarterForMonth($month),
                'expected_count' => max(0, (int) $data['expected_count']),
                'created_by' => $actor->id,
            ]
        );
    }

    /**
     * Programme une reunion. La structure, la periode et le statut sont deduits :
     * le formulaire ne demande que la date, l'heure et le libelle.
     *
     * @param  array{direction_id:int,service_id:?int,meeting_type:string,label:string,scheduled_date:string,scheduled_time:?string}  $data
     */
    public function scheduleMeeting(array $data, User $actor): Meeting
    {
        $type = MeetingType::from((string) $data['meeting_type']);
        $directionId = (int) $data['direction_id'];
        $serviceId = $type->requiresService() ? (int) $data['service_id'] : null;

        if (! $this->access->canScheduleFor($actor, $type, $directionId, $serviceId)) {
            throw new InvalidArgumentException('Vous ne pouvez pas programmer de reunion pour cette structure.');
        }

        $date = Carbon::parse((string) $data['scheduled_date'])->startOfDay();
        $month = (int) $date->month;
        $year = (int) $date->year;

        $plan = MeetingPlan::query()
            ->where('direction_id', $directionId)
            ->where('service_id', $serviceId)
            ->where('meeting_type', $type->value)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        // Une structure peut programmer au-dela de l'objectif : la reunion est
        // alors identifiee comme supplementaire, jamais bloquee.
        $alreadyScheduled = $plan !== null
            ? Meeting::query()
                ->where('meeting_plan_id', $plan->id)
                ->where('status', '!=', MeetingStatus::Annulee->value)
                ->count()
            : 0;
        $isExtra = $plan === null || $alreadyScheduled >= (int) $plan->expected_count;

        $meeting = new Meeting([
            'meeting_plan_id' => $plan?->id,
            'direction_id' => $directionId,
            'service_id' => $serviceId,
            'meeting_type' => $type->value,
            'label' => trim((string) $data['label']),
            'year' => $year,
            'quarter' => MeetingPlan::quarterForMonth($month),
            'month' => $month,
            'original_scheduled_date' => $date->toDateString(),
            'current_scheduled_date' => $date->toDateString(),
            'scheduled_time' => $data['scheduled_time'] ?? null,
            'status' => MeetingStatus::Programmee->value,
            'is_extra' => $isExtra,
            'created_by' => $actor->id,
        ]);
        $meeting->save();

        $this->log($meeting, null, null, MeetingStatus::Programmee, $actor, 'Réunion programmée.');
        $this->notifications->meetingScheduled($meeting);

        return $meeting->refresh();
    }

    /**
     * Reporte une reunion sans la supprimer : la date initiale est conservee et
     * l'historique enregistre chaque report (regles metier 4 et 5).
     */
    public function postponeMeeting(Meeting $meeting, string $newDate, ?string $newTime, string $reason, User $actor): Meeting
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Le motif du report est obligatoire.');
        }

        return DB::transaction(function () use ($meeting, $newDate, $newTime, $reason, $actor): Meeting {
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->access->canScheduleForMeeting($actor, $locked)) {
                throw new InvalidArgumentException('Vous ne pouvez pas reporter cette reunion.');
            }

            $this->assertTransitionAllowed($locked->status, MeetingStatus::Reportee);

            $previousStatus = $locked->status;
            $previousDate = $locked->current_scheduled_date?->toDateString();
            $previousMonth = (int) $locked->month;
            $date = Carbon::parse($newDate)->startOfDay();

            $locked->forceFill([
                'current_scheduled_date' => $date->toDateString(),
                'scheduled_time' => $newTime ?? $locked->scheduled_time,
                'year' => (int) $date->year,
                'month' => (int) $date->month,
                'quarter' => MeetingPlan::quarterForMonth((int) $date->month),
                'status' => MeetingStatus::Reportee->value,
                'was_postponed' => true,
                'postponement_count' => (int) $locked->postponement_count + 1,
            ])->save();

            $this->log($locked, null, $previousStatus, MeetingStatus::Reportee, $actor, $reason, [
                'ancienne_date' => $previousDate,
                'nouvelle_date' => $date->toDateString(),
                'ancien_mois' => $previousMonth,
                'nouveau_mois' => (int) $date->month,
            ]);

            $this->notifications->meetingPostponed($locked, $previousDate, $reason);

            return $locked->refresh();
        });
    }

    public function cancelMeeting(Meeting $meeting, string $reason, User $actor): Meeting
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException("Le motif d'annulation est obligatoire.");
        }

        return DB::transaction(function () use ($meeting, $reason, $actor): Meeting {
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->access->canScheduleForMeeting($actor, $locked)) {
                throw new InvalidArgumentException('Vous ne pouvez pas annuler cette reunion.');
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
            $this->notifications->meetingCancelled($locked, $reason);

            return $locked->refresh();
        });
    }

    /**
     * Depot du PV. Le circuit de validation demarre automatiquement au SCIQ
     * (regles metier 7 et 10).
     */
    public function submitReport(Meeting $meeting, UploadedFile $file, ?string $observation, User $actor): MeetingReport
    {
        return DB::transaction(function () use ($meeting, $file, $observation, $actor): MeetingReport {
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->access->canSubmitReport($actor, $locked)) {
                throw new InvalidArgumentException('Vous ne pouvez pas deposer de PV pour cette reunion.');
            }

            if ($locked->status->isClosed()) {
                throw new InvalidArgumentException('Cette reunion est cloturee : son PV ne peut plus etre modifie.');
            }

            if ($locked->status->isUnderReview()) {
                throw new InvalidArgumentException('Un PV est deja en cours de validation pour cette reunion.');
            }

            $this->assertTransitionAllowed($locked->status, MeetingStatus::EnValidationSciq);

            $checksum = $file->getRealPath() !== false ? hash_file('sha256', $file->getRealPath()) : null;
            $stored = $this->storage->store($file, 'meetings/pv/'.date('Y/m'));
            $version = (int) MeetingReport::query()->where('meeting_id', $locked->id)->max('version') + 1;

            $report = MeetingReport::query()->create([
                'meeting_id' => $locked->id,
                'file_path' => $stored['path'],
                'original_file_name' => $stored['nom_original'],
                'file_size' => $stored['taille_octets'],
                'mime_type' => $stored['mime_type'],
                'checksum' => $checksum !== false ? $checksum : null,
                'version' => $version,
                'status' => MeetingStatus::EnValidationSciq->value,
                'observation' => $observation !== null ? trim($observation) : null,
                'uploaded_by' => $actor->id,
                'uploaded_at' => now(),
            ]);

            $old = $locked->status;
            $locked->forceFill(['status' => MeetingStatus::EnValidationSciq->value])->save();

            $this->log($locked, $report, $old, MeetingStatus::EnValidationSciq, $actor, 'PV déposé (version '.$version.').');
            $this->notifications->reportSubmitted($locked, $report);

            return $report->refresh();
        });
    }

    /**
     * Visa d'un niveau sur la version courante du PV.
     *
     * La Planification ne peut pas viser avant le SCIQ : le statut courant du PV
     * determine seul le niveau attendu.
     */
    public function review(
        MeetingReport $report,
        MeetingApprovalLevel $level,
        MeetingApprovalDecision $decision,
        ?string $comment,
        User $actor
    ): MeetingReport {
        if ($decision === MeetingApprovalDecision::CorrectionRequested && trim((string) $comment) === '') {
            throw new InvalidArgumentException('Le motif est obligatoire pour demander une correction.');
        }

        if (! $this->access->canReviewAt($actor, $level)) {
            throw new InvalidArgumentException("Vous n'etes pas autorise a poser ce visa.");
        }

        return DB::transaction(function () use ($report, $level, $decision, $comment, $actor): MeetingReport {
            $locked = MeetingReport::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
            $meeting = Meeting::query()->whereKey($locked->meeting_id)->lockForUpdate()->firstOrFail();

            if ($locked->isLocked()) {
                throw new InvalidArgumentException('Ce PV est definitivement valide : il ne peut plus etre modifie.');
            }

            $expected = $locked->pendingLevel();
            if ($expected === null) {
                throw new InvalidArgumentException("Ce PV n'attend aucun visa.");
            }

            if ($expected !== $level) {
                throw new InvalidArgumentException(
                    'Ce PV attend le visa « '.$expected->label().' » : le circuit doit etre respecte.'
                );
            }

            // Un controleur ne valide pas son propre depot (regle metier 17).
            if ((int) $locked->uploaded_by === (int) $actor->id) {
                throw new InvalidArgumentException('Un controleur ne peut pas valider son propre depot.');
            }

            MeetingApproval::query()->create([
                'meeting_report_id' => $locked->id,
                'approval_level' => $level->value,
                'decision' => $decision->value,
                'comment' => $comment !== null ? trim($comment) : null,
                'reviewer_id' => $actor->id,
                'reviewed_at' => now(),
            ]);

            $old = $meeting->status;

            if ($decision === MeetingApprovalDecision::CorrectionRequested) {
                $this->assertTransitionAllowed($old, MeetingStatus::ACorriger);
                $locked->forceFill(['status' => MeetingStatus::ACorriger->value])->save();
                $meeting->forceFill(['status' => MeetingStatus::ACorriger->value])->save();

                $this->log($meeting, $locked, $old, MeetingStatus::ACorriger, $actor, $comment);
                $this->notifications->correctionRequested($meeting, $locked, (string) $comment);

                return $locked->refresh();
            }

            $next = $level->validatedStatus();
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

            if ($next === MeetingStatus::ValideeDefinitivement) {
                $this->notifications->reportValidated($meeting, $locked);
            } else {
                $this->notifications->reportAwaitingPlanification($meeting, $locked);
            }

            return $locked->refresh();
        });
    }

    /**
     * Passe en « PV attendu » les reunions dont la date est passee et pour
     * lesquelles aucun PV n'a ete depose (regle metier 6).
     */
    public function markDueMeetingsAsAwaitingReport(?Carbon $reference = null): int
    {
        $reference = $reference?->copy() ?? Carbon::today();

        $meetings = Meeting::query()
            ->whereIn('status', [MeetingStatus::Programmee->value, MeetingStatus::Reportee->value])
            ->whereDate('current_scheduled_date', '<', $reference->toDateString())
            ->get();

        $count = 0;
        foreach ($meetings as $meeting) {
            if (! $meeting->status->canTransitionTo(MeetingStatus::PvAttendu)) {
                continue;
            }

            $old = $meeting->status;
            $meeting->forceFill(['status' => MeetingStatus::PvAttendu->value])->save();
            $this->log($meeting, null, $old, MeetingStatus::PvAttendu, null, 'Échéance dépassée sans PV déposé.');
            $count++;
        }

        return $count;
    }

    /** Refuse toute transition non declaree dans la machine a etats. */
    private function assertTransitionAllowed(MeetingStatus $from, MeetingStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw new InvalidArgumentException(
                'Transition refusee : « '.$from->label().' » ne peut pas devenir « '.$to->label().' ».'
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
            'comment' => $comment !== null ? trim($comment) : null,
            'context' => $context !== [] ? $context : null,
            'changed_by' => $actor?->id,
            'changed_at' => now(),
        ]);
    }
}
