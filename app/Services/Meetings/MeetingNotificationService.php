<?php

namespace App\Services\Meetings;

use App\Enums\MeetingType;
use App\Models\Meeting;
use App\Models\MeetingNotification;
use App\Models\MeetingPlan;
use App\Models\MeetingReport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Notifications ciblees du module.
 *
 * La diffusion repose sur la structure et le role : on ne notifie jamais tous
 * les utilisateurs de l'application.
 */
class MeetingNotificationService
{
    /**
     * Destinataires d'une reunion.
     *
     * Reunion de service : membres du service, chef de service, directeur de
     * rattachement, profils SCIQ et Planification.
     * Reunion de direction : directeur, chefs de service rattaches, agents de la
     * direction, profils SCIQ et Planification.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(Meeting $meeting): Collection
    {
        $scope = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($meeting): void {
                if ($meeting->meeting_type === MeetingType::Service && $meeting->service_id !== null) {
                    // Le service concerne, plus son directeur de rattachement.
                    $query->where('service_id', $meeting->service_id)
                        ->orWhere(function ($directionQuery) use ($meeting): void {
                            $directionQuery->where('direction_id', $meeting->direction_id)
                                ->where('role', User::ROLE_DIRECTION);
                        });

                    return;
                }

                // Reunion de direction : toute la direction.
                $query->where('direction_id', $meeting->direction_id);
            })
            ->get();

        $controllers = User::query()
            ->where('is_active', true)
            ->whereIn('role', array_merge(
                MeetingAccessService::SCIQ_ROLES,
                MeetingAccessService::PLANIFICATION_ROLES
            ))
            ->get();

        return $scope->concat($controllers)->unique('id')->values();
    }

    /** @return Collection<int, User> */
    public function sciqReviewers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', MeetingAccessService::SCIQ_ROLES)
            ->get();
    }

    /** @return Collection<int, User> */
    public function planificationReviewers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', MeetingAccessService::PLANIFICATION_ROLES)
            ->get();
    }

    /** Diffusion du programme trimestriel publie par le SCIQ. */
    public function planPublished(MeetingPlan $plan): int
    {
        $recipients = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($plan): void {
                if ($plan->service_id !== null) {
                    $query->where('service_id', $plan->service_id);

                    return;
                }

                $query->where('direction_id', $plan->direction_id);
            })
            ->get();

        $message = 'Objectif de réunions publié : '.$plan->expected_count
            .' réunion(s) attendue(s) pour '.$plan->structureLabel()
            .' (mois '.$plan->month.'/'.$plan->year.').';

        $count = 0;
        foreach ($recipients as $user) {
            MeetingNotification::query()->create([
                'meeting_id' => null,
                'user_id' => $user->id,
                'notification_type' => MeetingNotification::TYPE_PLAN_PUBLISHED,
                'message' => $message,
                'sent_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    public function meetingScheduled(Meeting $meeting): int
    {
        return $this->dispatch(
            $meeting,
            null,
            MeetingNotification::TYPE_SCHEDULED,
            'Réunion programmée le '.$this->dateLabel($meeting).' — '.$meeting->label.' ('.$meeting->structureLabel().').'
        );
    }

    public function meetingPostponed(Meeting $meeting, ?string $previousDate, string $reason): int
    {
        $from = $previousDate !== null ? Carbon::parse($previousDate)->format('d/m/Y') : 'date initiale';

        return $this->dispatch(
            $meeting,
            null,
            MeetingNotification::TYPE_POSTPONED,
            'Réunion reportée du '.$from.' au '.$this->dateLabel($meeting).' — motif : '.$reason.'.'
        );
    }

    public function meetingCancelled(Meeting $meeting, string $reason): int
    {
        return $this->dispatch(
            $meeting,
            null,
            MeetingNotification::TYPE_CANCELLED,
            'Réunion annulée ('.$meeting->label.') — motif : '.$reason.'.'
        );
    }

    /** Rappel avant la reunion, selon un delai configurable. */
    public function reminder(Meeting $meeting): int
    {
        return $this->dispatch(
            $meeting,
            null,
            MeetingNotification::TYPE_REMINDER,
            'Rappel : réunion « '.$meeting->label.' » le '.$this->dateLabel($meeting).'.'
        );
    }

    /** Le PV est depose : seuls les controleurs SCIQ sont sollicites. */
    public function reportSubmitted(Meeting $meeting, MeetingReport $report): int
    {
        return $this->notifyUsers(
            $this->sciqReviewers(),
            $meeting,
            $report,
            MeetingNotification::TYPE_REPORT_SUBMITTED,
            'PV à contrôler (version '.$report->version.') — '.$meeting->label.' ('.$meeting->structureLabel().').'
        );
    }

    /** Visa SCIQ pose : la Planification prend le relais. */
    public function reportAwaitingPlanification(Meeting $meeting, MeetingReport $report): int
    {
        return $this->notifyUsers(
            $this->planificationReviewers(),
            $meeting,
            $report,
            MeetingNotification::TYPE_REPORT_SUBMITTED,
            'PV validé par le SCIQ, en attente de validation définitive — '.$meeting->label.'.'
        );
    }

    /** Retour pour correction : la structure concernee est prevenue. */
    public function correctionRequested(Meeting $meeting, MeetingReport $report, string $reason): int
    {
        return $this->notifyUsers(
            $this->structureRecipients($meeting),
            $meeting,
            $report,
            MeetingNotification::TYPE_CORRECTION_REQUESTED,
            'PV retourné pour correction — '.$meeting->label.' : '.$reason
        );
    }

    /** PV definitivement valide : diffusion aux destinataires de la reunion. */
    public function reportValidated(Meeting $meeting, MeetingReport $report): int
    {
        return $this->notifyUsers(
            $this->recipientsFor($meeting),
            $meeting,
            $report,
            MeetingNotification::TYPE_VALIDATED,
            'PV validé définitivement et diffusé — '.$meeting->label.' ('.$this->dateLabel($meeting).').'
        );
    }

    /** @return Collection<int, User> */
    private function structureRecipients(Meeting $meeting): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($meeting): void {
                if ($meeting->service_id !== null) {
                    $query->where('service_id', $meeting->service_id);

                    return;
                }

                $query->where('direction_id', $meeting->direction_id);
            })
            ->get();
    }

    private function dispatch(Meeting $meeting, ?MeetingReport $report, string $type, string $message): int
    {
        return $this->notifyUsers($this->recipientsFor($meeting), $meeting, $report, $type, $message);
    }

    /** @param Collection<int, User> $users */
    private function notifyUsers(Collection $users, Meeting $meeting, ?MeetingReport $report, string $type, string $message): int
    {
        $count = 0;
        foreach ($users as $user) {
            MeetingNotification::query()->create([
                'meeting_id' => $meeting->id,
                'meeting_report_id' => $report?->id,
                'user_id' => $user->id,
                'notification_type' => $type,
                'message' => $message,
                'sent_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    private function dateLabel(Meeting $meeting): string
    {
        $date = $meeting->current_scheduled_date?->format('d/m/Y') ?? '-';
        $time = $meeting->scheduled_time;

        return $time !== null ? $date.' à '.substr((string) $time, 0, 5) : $date;
    }
}
