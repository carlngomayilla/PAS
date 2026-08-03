<?php

namespace App\Policies;

use App\Models\InstitutionalReport;
use App\Models\User;
use App\Services\InstitutionalReportingService;

class InstitutionalReportPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return app(InstitutionalReportingService::class)->canView($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, InstitutionalReport $institutionalReport): bool
    {
        return app(InstitutionalReportingService::class)->canViewReport($user, $institutionalReport);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return app(InstitutionalReportingService::class)->canSubmit($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, InstitutionalReport $institutionalReport): bool
    {
        return app(InstitutionalReportingService::class)->canAmend($user, $institutionalReport);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, InstitutionalReport $institutionalReport): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, InstitutionalReport $institutionalReport): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, InstitutionalReport $institutionalReport): bool
    {
        return false;
    }
}
