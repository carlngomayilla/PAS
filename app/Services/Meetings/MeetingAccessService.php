<?php

namespace App\Services\Meetings;

use App\Enums\MeetingApprovalLevel;
use App\Enums\MeetingStatus;
use App\Enums\MeetingType;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qui peut faire quoi dans le module de reunions.
 *
 * Les autorisations reposent sur les roles et le perimetre de la structure,
 * jamais sur des noms d'utilisateurs (regle metier 13).
 */
class MeetingAccessService
{
    /** Profils autorises a definir les objectifs et a poser le visa SCIQ. */
    public const SCIQ_ROLES = [
        User::ROLE_SCIQ,
        User::ROLE_SCIQ_SUIVI_GLOBAL,
        User::ROLE_CHEF_UNITE_SCIQ,
    ];

    /** Profils autorises a poser le visa final. */
    public const PLANIFICATION_ROLES = [
        User::ROLE_PLANIFICATION,
        User::ROLE_CHEF_PLANIFICATION,
    ];

    public function isSciq(User $user): bool
    {
        return $user->hasRole(...self::SCIQ_ROLES);
    }

    public function isPlanification(User $user): bool
    {
        return $user->hasRole(...self::PLANIFICATION_ROLES);
    }

    public function isAdministrator(User $user): bool
    {
        return $user->hasRole(
            User::ROLE_SUPER_ADMIN,
            User::ROLE_ADMIN,
            User::ROLE_ADMIN_FONCTIONNEL
        );
    }

    /** Seul le SCIQ definit le nombre de reunions attendu (regle metier 1). */
    public function canDefinePlans(User $user): bool
    {
        return $this->isSciq($user) || $this->isAdministrator($user);
    }

    /** Le module est consultable par tout utilisateur actif. */
    public function canViewModule(User $user): bool
    {
        return (bool) $user->is_active;
    }

    public function canViewAllMeetings(User $user): bool
    {
        return $this->isAdministrator($user)
            || $this->isSciq($user)
            || $this->isPlanification($user)
            || $user->hasGlobalReadAccess()
            || $user->hasRole(
                User::ROLE_DG,
                User::ROLE_CABINET,
                User::ROLE_CABINET_SUPERVISION,
                User::ROLE_DGA_SUPERVISION,
                User::ROLE_AUDITEUR,
                User::ROLE_INVITE_LECTURE
            );
    }

    public function canScheduleAny(User $user): bool
    {
        return $this->isAdministrator($user)
            || $user->hasRole(User::ROLE_DIRECTION)
            || ($user->isServiceOrUnitChief() && $user->direction_id !== null && $user->service_id !== null);
    }

    /**
     * Peut programmer une reunion pour cette structure.
     *
     * Le chef de service programme les reunions de SON service, le directeur
     * celles de SA direction (regles metier 2 et 3).
     */
    public function canScheduleFor(User $user, MeetingType $type, int $directionId, ?int $serviceId): bool
    {
        if ($this->isAdministrator($user)) {
            return true;
        }

        if ($type === MeetingType::Direction) {
            return $user->hasRole(User::ROLE_DIRECTION)
                && (int) $user->direction_id === $directionId;
        }

        // Reunion de service : le chef du service concerne, ou son directeur.
        if ($serviceId === null) {
            return false;
        }

        if ($user->isServiceOrUnitChief() && (int) $user->service_id === $serviceId) {
            return true;
        }

        return $user->hasRole(User::ROLE_DIRECTION) && (int) $user->direction_id === $directionId;
    }

    public function canScheduleForMeeting(User $user, Meeting $meeting): bool
    {
        return $this->canScheduleFor(
            $user,
            $meeting->meeting_type,
            (int) $meeting->direction_id,
            $meeting->service_id !== null ? (int) $meeting->service_id : null
        );
    }

    /** Peut deposer ou corriger le PV de cette reunion. */
    public function canSubmitReport(User $user, Meeting $meeting): bool
    {
        return $this->canScheduleForMeeting($user, $meeting)
            && in_array($meeting->status, [
                MeetingStatus::Programmee,
                MeetingStatus::Reportee,
                MeetingStatus::PvAttendu,
                MeetingStatus::ACorriger,
            ], true);
    }

    /** Peut poser le visa du niveau demande. */
    public function canReviewAt(User $user, MeetingApprovalLevel $level): bool
    {
        if ($this->isAdministrator($user)) {
            return true;
        }

        return match ($level) {
            MeetingApprovalLevel::Sciq => $this->isSciq($user),
            MeetingApprovalLevel::Planification => $this->isPlanification($user),
        };
    }

    /**
     * Peut consulter le PV de cette reunion.
     *
     * Avant validation definitive, l'acces est limite a la structure concernee,
     * au SCIQ et — une fois le visa SCIQ pose — a la Planification.
     */
    public function canViewReport(User $user, Meeting $meeting): bool
    {
        if ($this->isAdministrator($user) || $this->isSciq($user)) {
            return true;
        }

        if ($this->isPlanification($user)) {
            return in_array($meeting->status, [
                MeetingStatus::EnValidationPlanification,
                MeetingStatus::ValideeDefinitivement,
            ], true);
        }

        if ($this->canScheduleForMeeting($user, $meeting)) {
            return true;
        }

        if ($meeting->isOfficiallyHeld()) {
            return $this->canViewMeeting($user, $meeting);
        }

        return false;
    }

    public function canViewMeeting(User $user, Meeting $meeting): bool
    {
        return $this->canViewModule($user)
            && ($this->canViewAllMeetings($user) || $this->belongsToScope($user, $meeting));
    }

    public function canDownloadReport(User $user, MeetingReport $report): bool
    {
        $meeting = $report->meeting;

        return $meeting instanceof Meeting
            && $this->canViewReport($user, $meeting);
    }

    public function canPostpone(User $user, Meeting $meeting): bool
    {
        return $this->canScheduleForMeeting($user, $meeting)
            && in_array($meeting->status, [MeetingStatus::Programmee, MeetingStatus::Reportee], true);
    }

    public function canCancel(User $user, Meeting $meeting): bool
    {
        return $this->canScheduleForMeeting($user, $meeting)
            && in_array($meeting->status, [MeetingStatus::Programmee, MeetingStatus::Reportee], true);
    }

    public function canReviewReport(User $user, MeetingReport $report): bool
    {
        $level = $report->pendingLevel();

        return $level instanceof MeetingApprovalLevel
            && $this->canReviewAt($user, $level)
            && (int) $report->uploaded_by !== (int) $user->id
            && ! $report->approvals()->where('reviewer_id', $user->id)->exists();
    }

    public function scopeQueryToUser(Builder $query, User $user): Builder
    {
        if ($this->canViewAllMeetings($user)) {
            return $query;
        }

        if ($user->direction_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('direction_id', (int) $user->direction_id);

        if ($user->hasRole(User::ROLE_DIRECTION) || $user->service_id === null) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($user): void {
            $scope
                ->where('meeting_type', MeetingType::Direction->value)
                ->orWhere('service_id', (int) $user->service_id);
        });
    }

    /** L'utilisateur appartient-il au perimetre de la reunion ? */
    public function belongsToScope(User $user, Meeting $meeting): bool
    {
        if ((int) $user->direction_id !== (int) $meeting->direction_id) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return true;
        }

        if ($meeting->meeting_type === MeetingType::Direction) {
            return true;
        }

        return $meeting->service_id === null
            || (int) $user->service_id === (int) $meeting->service_id;
    }
}
