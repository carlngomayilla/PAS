<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\User;
use App\Services\Meetings\MeetingAccessService;

class MeetingPolicy
{
    public function __construct(
        private readonly MeetingAccessService $access
    ) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->access->canViewModule($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Meeting $meeting): bool
    {
        return $this->access->canViewMeeting($user, $meeting);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->access->canScheduleAny($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Meeting $meeting): bool
    {
        return $this->access->canScheduleForMeeting($user, $meeting);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Meeting $meeting): bool
    {
        return false;
    }

    public function downloadReport(User $user, Meeting $meeting, MeetingReport $report): bool
    {
        return (int) $report->meeting_id === (int) $meeting->id
            && $this->access->canDownloadReport($user, $report);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Meeting $meeting): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Meeting $meeting): bool
    {
        return false;
    }
}
