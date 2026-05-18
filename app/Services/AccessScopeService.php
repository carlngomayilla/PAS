<?php

namespace App\Services;

use App\Models\User;

/**
 * Service centralisé qui calcule le périmètre d'accès (scope) d'un utilisateur.
 *
 * Permet de décorréler le rôle applicatif (ce que l'utilisateur peut faire)
 * du périmètre organisationnel (sur quelles données il peut agir).
 *
 * Exemple typique : un user `planification` rattaché à la direction DS doit voir
 * toute l'agence — alors qu'un user `direction` rattaché à la DS ne doit voir
 * que la DS. C'est ici qu'on tranche cette ambiguïté.
 */
class AccessScopeService
{
    public const TYPE_GLOBAL = 'global';
    public const TYPE_DIRECTION = 'direction';
    public const TYPE_SERVICE = 'service';
    public const TYPE_UNITE = 'unite';
    public const TYPE_AGENT = 'agent';
    public const TYPE_LIMITED = 'limited';

    /**
     * Calcule le périmètre d'accès de l'utilisateur.
     *
     * @return array{
     *     scope_type: string,
     *     direction_id: int|null,
     *     service_id: int|null,
     *     unite_dg_id: int|null,
     *     user_id: int|null,
     *     can_validate: bool,
     *     can_export: bool,
     *     is_global: bool,
     *     is_read_only: bool,
     * }
     */
    public function scopeFor(User $user): array
    {
        // 1) Portée globale — voit toute l'agence.
        // Inclut : super_admin, admin, admin_fonctionnel, dg, planification,
        // sciq_suivi_global, chef_unite_sciq, dga_supervision, chef_unite_dga,
        // cabinet, cabinet_supervision, chef_unite_cabinet.
        if ($user->isSuperAdmin() || $user->hasGlobalReadAccess()) {
            return [
                'scope_type' => self::TYPE_GLOBAL,
                'direction_id' => null,
                'service_id' => null,
                'unite_dg_id' => $user->unite_dg_id !== null ? (int) $user->unite_dg_id : null,
                'user_id' => null,
                'can_validate' => $user->hasGlobalWriteAccess()
                    || $user->hasPermission('planning.write.global')
                    || $user->hasPermission('planning.strategic.manage'),
                'can_export' => $user->hasPermission('reporting.read'),
                'is_global' => true,
                'is_read_only' => $this->isReadOnlyRole($user),
            ];
        }

        // 2) Chef d'unité UCAS — limité à son unité DG.
        if ($user->hasRole(User::ROLE_CHEF_UNITE_UCAS) && $user->unite_dg_id !== null) {
            return [
                'scope_type' => self::TYPE_UNITE,
                'direction_id' => $user->direction_id !== null ? (int) $user->direction_id : null,
                'service_id' => null,
                'unite_dg_id' => (int) $user->unite_dg_id,
                'user_id' => null,
                'can_validate' => $user->hasPermission('planning.write.service'),
                'can_export' => $user->hasPermission('reporting.read'),
                'is_global' => false,
                'is_read_only' => false,
            ];
        }

        // 3) Directeur de direction — voit sa direction.
        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            return [
                'scope_type' => self::TYPE_DIRECTION,
                'direction_id' => (int) $user->direction_id,
                'service_id' => null,
                'unite_dg_id' => null,
                'user_id' => null,
                'can_validate' => $user->hasPermission('planning.write.direction'),
                'can_export' => $user->hasPermission('reporting.read'),
                'is_global' => false,
                'is_read_only' => false,
            ];
        }

        // 4) Chef de service — voit son service.
        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            return [
                'scope_type' => self::TYPE_SERVICE,
                'direction_id' => $user->direction_id !== null ? (int) $user->direction_id : null,
                'service_id' => (int) $user->service_id,
                'unite_dg_id' => null,
                'user_id' => null,
                'can_validate' => $user->hasPermission('planning.write.service'),
                'can_export' => $user->hasPermission('reporting.read'),
                'is_global' => false,
                'is_read_only' => false,
            ];
        }

        // 5) Agent — ne voit que ses propres actions.
        if ($user->isAgent() || $user->hasRole(User::ROLE_AGENT)) {
            return [
                'scope_type' => self::TYPE_AGENT,
                'direction_id' => $user->direction_id !== null ? (int) $user->direction_id : null,
                'service_id' => $user->service_id !== null ? (int) $user->service_id : null,
                'unite_dg_id' => $user->unite_dg_id !== null ? (int) $user->unite_dg_id : null,
                'user_id' => (int) $user->id,
                'can_validate' => false,
                'can_export' => $user->hasPermission('reporting.read'),
                'is_global' => false,
                'is_read_only' => true,
            ];
        }

        // 6) Auditeur / Invité — accès très limité, lecture seule.
        return [
            'scope_type' => self::TYPE_LIMITED,
            'direction_id' => null,
            'service_id' => null,
            'unite_dg_id' => null,
            'user_id' => (int) $user->id,
            'can_validate' => false,
            'can_export' => $user->hasPermission('reporting.read'),
            'is_global' => false,
            'is_read_only' => true,
        ];
    }

    /**
     * Indique si l'utilisateur a une portée globale (voit toute l'agence).
     */
    public function hasGlobalScope(User $user): bool
    {
        return $this->scopeFor($user)['is_global'];
    }

    /**
     * Indique si l'utilisateur est en lecture seule (auditeur, invité, agent).
     */
    public function isReadOnly(User $user): bool
    {
        return $this->scopeFor($user)['is_read_only'];
    }

    private function isReadOnlyRole(User $user): bool
    {
        $readOnlyRoles = [
            User::ROLE_AUDITEUR,
            User::ROLE_INVITE_LECTURE,
            User::ROLE_DGA_SUPERVISION,
            User::ROLE_CABINET_SUPERVISION,
            User::ROLE_CABINET,
            User::ROLE_DG,
        ];

        return $user->hasRole(...$readOnlyRoles);
    }
}
