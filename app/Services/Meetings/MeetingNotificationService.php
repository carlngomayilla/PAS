<?php

namespace App\Services\Meetings;

use App\Enums\MeetingType;
use App\Models\Meeting;
use App\Models\MeetingNotification;
use App\Models\MeetingPlan;
use App\Models\MeetingReport;
use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class MeetingNotificationService
{
    /** @return Collection<int, User> */
    public function recipientsFor(Meeting $meeting): Collection
    {
        $scope = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($meeting): void {
                if ($meeting->meeting_type === MeetingType::Service && $meeting->service_id !== null) {
                    $query->where('service_id', $meeting->service_id)
                        ->orWhere(function ($directionQuery) use ($meeting): void {
                            $directionQuery->where('direction_id', $meeting->direction_id)
                                ->where('role', User::ROLE_DIRECTION);
                        });

                    return;
                }

                $query->where('direction_id', $meeting->direction_id);
            })
            ->get();

        return $scope
            ->concat($this->usersWithRoles(array_merge(
                MeetingAccessService::SCIQ_ROLES,
                MeetingAccessService::PLANIFICATION_ROLES
            )))
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, User> */
    public function sciqReviewers(): Collection
    {
        return $this->usersWithRoles(MeetingAccessService::SCIQ_ROLES);
    }

    /** @return Collection<int, User> */
    public function planificationReviewers(): Collection
    {
        return $this->usersWithRoles(MeetingAccessService::PLANIFICATION_ROLES);
    }

    public function planPublished(MeetingPlan $plan): int
    {
        $recipients = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($plan): void {
                if ($plan->service_id !== null) {
                    $query->where('service_id', $plan->service_id)
                        ->orWhere(function ($direction) use ($plan): void {
                            $direction->where('direction_id', $plan->direction_id)
                                ->where('role', User::ROLE_DIRECTION);
                        });

                    return;
                }

                $query->where('direction_id', $plan->direction_id);
            })
            ->get();

        return $this->notifyUsers(
            $recipients,
            null,
            null,
            MeetingNotification::TYPE_PLAN_PUBLISHED,
            'Objectif publié : '.$plan->expected_count.' réunion(s) attendue(s) pour '
                .$plan->structureLabel().' en '.$plan->month.'/'.$plan->year.'.',
            route('workspace.meetings.index', ['view' => 'plans']),
            $plan
        );
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
        $from = $previousDate !== null ? Carbon::parse($previousDate)->format('d/m/Y') : 'la date initiale';

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

    public function reminder(Meeting $meeting): int
    {
        return $this->dispatch(
            $meeting,
            null,
            MeetingNotification::TYPE_REMINDER,
            'Rappel : réunion « '.$meeting->label.' » le '.$this->dateLabel($meeting).'.'
        );
    }

    public function reportExpected(Meeting $meeting): int
    {
        return $this->notifyUsers(
            $this->structureRecipients($meeting),
            $meeting,
            null,
            MeetingNotification::TYPE_REPORT_EXPECTED,
            'PV attendu : la réunion « '.$meeting->label.' » est arrivée à échéance.'
        );
    }

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

    public function reportAwaitingPlanification(Meeting $meeting, MeetingReport $report): int
    {
        return $this->notifyUsers(
            $this->planificationReviewers(),
            $meeting,
            $report,
            MeetingNotification::TYPE_REPORT_AWAITING_PLANIFICATION,
            'PV validé par le SCIQ, en attente du visa final — '.$meeting->label.'.'
        );
    }

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
                    $query->where('service_id', $meeting->service_id)
                        ->orWhere(function ($direction) use ($meeting): void {
                            $direction->where('direction_id', $meeting->direction_id)
                                ->where('role', User::ROLE_DIRECTION);
                        });

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
    private function notifyUsers(
        Collection $users,
        ?Meeting $meeting,
        ?MeetingReport $report,
        string $type,
        string $message,
        ?string $url = null,
        ?MeetingPlan $plan = null
    ): int {
        try {
            $newRecipients = collect();
            foreach ($users->filter(fn ($user): bool => $user instanceof User)->unique('id') as $user) {
                $record = MeetingNotification::query()->firstOrCreate(
                    [
                        'meeting_id' => $meeting?->id,
                        'meeting_plan_id' => $plan?->id,
                        'meeting_report_id' => $report?->id,
                        'user_id' => $user->id,
                        'notification_type' => $type,
                        'message' => $message,
                    ],
                    ['sent_at' => now()]
                );

                if ($record->wasRecentlyCreated) {
                    $newRecipients->push($user);
                }
            }

            if ($newRecipients->isNotEmpty()) {
                Notification::sendNow($newRecipients, new WorkspaceModuleNotification([
                    'title' => $this->titleFor($type),
                    'message' => $message,
                    'module' => 'meetings',
                    'entity_type' => $meeting instanceof Meeting ? 'meeting' : 'meeting_plan',
                    'entity_id' => $meeting?->id ?? $plan?->id,
                    'url' => $url ?? ($meeting instanceof Meeting
                        ? route('workspace.meetings.show', $meeting)
                        : route('workspace.meetings.index')),
                    'icon' => 'calendar',
                    'status' => $this->levelFor($type),
                    'priority' => in_array($type, [
                        MeetingNotification::TYPE_CORRECTION_REQUESTED,
                        MeetingNotification::TYPE_REPORT_EXPECTED,
                        MeetingNotification::TYPE_REPORT_SUBMITTED,
                        MeetingNotification::TYPE_REPORT_AWAITING_PLANIFICATION,
                    ], true) ? 'high' : 'normal',
                    'direction_id' => $meeting?->direction_id,
                    'service_id' => $meeting?->service_id,
                    'meta' => [
                        'event' => $type,
                        'meeting_id' => $meeting?->id,
                        'report_id' => $report?->id,
                    ],
                ]));
            }

            return $newRecipients->count();
        } catch (Throwable $exception) {
            Log::critical('Échec non bloquant des notifications du module Réunions.', [
                'meeting_id' => $meeting?->id,
                'report_id' => $report?->id,
                'type' => $type,
                'exception' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /** @param list<string> $roles @return Collection<int, User> */
    private function usersWithRoles(array $roles): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->hasRole(...$roles))
            ->values();
    }

    private function titleFor(string $type): string
    {
        return match ($type) {
            MeetingNotification::TYPE_PLAN_PUBLISHED => 'Objectif de réunions publié',
            MeetingNotification::TYPE_SCHEDULED => 'Nouvelle réunion programmée',
            MeetingNotification::TYPE_POSTPONED => 'Réunion reportée',
            MeetingNotification::TYPE_CANCELLED => 'Réunion annulée',
            MeetingNotification::TYPE_REMINDER => 'Rappel de réunion',
            MeetingNotification::TYPE_REPORT_EXPECTED => 'Procès-verbal attendu',
            MeetingNotification::TYPE_REPORT_SUBMITTED => 'PV en attente du SCIQ',
            MeetingNotification::TYPE_REPORT_AWAITING_PLANIFICATION => 'PV en attente de la Planification',
            MeetingNotification::TYPE_CORRECTION_REQUESTED => 'Correction du PV requise',
            MeetingNotification::TYPE_VALIDATED => 'PV définitivement validé',
            default => 'Mise à jour d’une réunion',
        };
    }

    private function levelFor(string $type): string
    {
        return match ($type) {
            MeetingNotification::TYPE_CANCELLED,
            MeetingNotification::TYPE_CORRECTION_REQUESTED,
            MeetingNotification::TYPE_REPORT_EXPECTED => 'warning',
            MeetingNotification::TYPE_VALIDATED => 'conforme',
            default => 'info',
        };
    }

    private function dateLabel(Meeting $meeting): string
    {
        $date = $meeting->current_scheduled_date?->format('d/m/Y') ?? '-';
        $time = trim((string) $meeting->scheduled_time);

        return $time !== '' ? $date.' à '.substr($time, 0, 5) : $date;
    }
}
