<?php

namespace App\Services\Meetings;

use App\Enums\MeetingApprovalLevel;
use App\Enums\MeetingType;
use App\Models\Meeting;
use App\Models\User;

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
        return $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN);
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
        return $this->canScheduleForMeeting($user, $meeting);
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
            return true;
        }

        if ($meeting->isOfficiallyHeld()) {
            return $this->belongsToScope($user, $meeting);
        }

        return $this->canScheduleForMeeting($user, $meeting);
    }

    /** L'utilisateur appartient-il au perimetre de la reunion ? */
    public function belongsToScope(User $user, Meeting $meeting): bool
    {
        if ((int) $user->direction_id !== (int) $meeting->direction_id) {
            return false;
        }

        if ($meeting->meeting_type === MeetingType::Direction) {
            return true;
        }

        return $meeting->service_id === null
            || (int) $user->service_id === (int) $meeting->service_id;
    }
}
