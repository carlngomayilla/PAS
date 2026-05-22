<?php

namespace App\Services;

use App\Models\User;

class UserWorkspaceService
{
    public function __construct(
        private readonly WorkspaceModuleSettings $workspaceModuleSettings
    ) {
    }

    /**
     * Retourne la liste des modules accessibles pour l'utilisateur donné.
     *
     * @return array<int, array<string, mixed>>
     */
    public function modulesFor(User $user): array
    {
        $matrixRole = $this->matrixRole($user);
        $hasDelegatedPlanningRead = $user->hasDelegatedPermission('planning_read')
            || $user->hasDelegatedPermission('planning_write');
        $hasDelegatedPlanningWrite = $user->hasDelegatedPermission('planning_write');
        $hasDelegatedActionReview = $user->hasDelegatedPermission('action_review');

        $canReadPlanning = $user->hasPermission('planning.read')
            || $hasDelegatedPlanningRead;
        $canWriteGlobal = $user->hasPermission('planning.write.global');
        $canWriteDirection = $user->hasPermission('planning.write.direction')
            || $hasDelegatedPlanningWrite;
        $canWriteService = $user->hasPermission('planning.write.service')
            || $hasDelegatedPlanningWrite;
        $isAgent = $user->isAgent();
        $hasOwnExecutionSpace = $isAgent || $user->hasRole(User::ROLE_PLANIFICATION, User::ROLE_DG);
        $canWriteOperational = $canWriteGlobal || $canWriteDirection || $canWriteService;
        $canManageActions = $canWriteOperational && ! $isAgent;
        $canReadReferentiel = $user->hasAnyPermission('referentiel.read', 'referentiel.write', 'users.manage', 'users.manage_roles');
        $canWriteReferentiel = $user->hasPermission('referentiel.write');
        $canManageUsers = $user->hasAnyPermission('users.manage', 'users.manage_roles');
        $canReadAudit = $user->hasPermission('audit.read');
        $canReadApiDocs = $user->hasPermission('api_docs.read');
        $canReadRetention = $user->hasAnyPermission('retention.read', 'retention.manage');
        $canManageRetention = $user->hasPermission('retention.manage');
        $canManageDelegations = $user->hasPermission('delegations.manage');
        $canReadReporting = ($canReadPlanning || $isAgent) && $user->hasPermission('reporting.read');
        $canReadAlerts = $canReadPlanning && $user->hasPermission('alerts.read');
        $canUseMessaging = $user->hasPermission('messagerie.read');
        $isTechnicalAdmin = in_array($matrixRole, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_FONCTIONNEL], true);
        // Admin fonctionnel et SCIQ suivi global ont un rôle proche de Planification
        // pour la gestion des référentiels métier.
        $isPlanification = $user->hasRole(User::ROLE_PLANIFICATION, User::ROLE_ADMIN_FONCTIONNEL);
        // Auditeurs et superviseurs (Cabinet, DG, DGA) peuvent consulter l'audit
        // dès lors qu'ils ont la permission `audit.read`. On garde un fallback
        // role-based pour la rétrocompatibilité, mais la permission prime.
        $isAuditor = $user->hasRole(
            User::ROLE_AUDITEUR,
            User::ROLE_DG,
            User::ROLE_CABINET,
            User::ROLE_CABINET_SUPERVISION,
            User::ROLE_DGA_SUPERVISION
        );
        $canSeeAuditModule = $canReadAudit && in_array($matrixRole, [
            User::ROLE_SUPER_ADMIN,
            User::ROLE_ADMIN_FONCTIONNEL,
            User::ROLE_DG,
            User::ROLE_CABINET,
            User::ROLE_AUDITEUR,
        ], true);

        $modules = [[
            'code' => 'pilotage',
            'label' => 'Pilotage',
            'description' => 'Tableau de bord et synthèse de pilotage',
            'endpoint' => '/dashboard',
            'can_write' => false,
            'actions' => ['Consulter'],
        ]];

        if ($canUseMessaging) {
            $modules[] = [
                'code' => 'messagerie',
                'label' => 'Messagerie',
                'description' => 'Annuaire interactif et échanges internes',
                'endpoint' => '/workspace/messagerie',
                'can_write' => true,
                'actions' => ['Consulter', 'Écrire', 'Suivre non lus'],
            ];
        }

        $isServiceOnly = $matrixRole === User::ROLE_SERVICE;
        $isDirectionOnly = $matrixRole === User::ROLE_DIRECTION;

        if ($canReadPlanning && ! $isServiceOnly && ! $isDirectionOnly) {
            $modules[] = [
                'code' => 'pas',
                'label' => 'PAS',
                'description' => 'Vision stratégique pluriannuelle',
                'endpoint' => '/api/v1/pas',
                'can_write' => $canWriteGlobal,
                'actions' => $canWriteGlobal
                    ? ['Consulter', 'Créer', 'Modifier', 'Valider', 'Verrouiller']
                    : ['Consulter'],
            ];
        }

        if ($canReadPlanning && ! $isServiceOnly) {
            $modules[] = [
                'code' => 'pao',
                'label' => 'PAO',
                'description' => 'Déclinaison annuelle par direction',
                'endpoint' => '/api/v1/paos',
                'can_write' => $canWriteGlobal || $canWriteDirection,
                'actions' => ($canWriteGlobal || $canWriteDirection)
                    ? ['Consulter', 'Créer', 'Modifier', 'Suivre']
                    : ['Consulter'],
            ];
        }

        if ($canReadPlanning) {
            $modules[] = [
                'code' => 'pta',
                'label' => 'PTA',
                'description' => 'Planification opérationnelle par service',
                'endpoint' => '/api/v1/ptas',
                'can_write' => $canWriteGlobal || $canWriteService,
                'actions' => ($canWriteGlobal || $canWriteService)
                    ? ['Consulter', 'Créer', 'Modifier', 'Exécuter']
                    : ['Consulter'],
            ];
        }

        if ($canReadPlanning || $hasOwnExecutionSpace || $hasDelegatedActionReview) {
            $modules[] = [
                'code' => 'execution',
                'label' => 'Actions',
                'description' => 'Exécution des tâches et suivi de progression',
                'endpoint' => '/api/v1/actions',
                'can_write' => $canWriteOperational || $hasOwnExecutionSpace || $hasDelegatedActionReview,
                'actions' => ($isAgent || ($hasOwnExecutionSpace && ! $canManageActions))
                    ? ['Consulter', 'Renseigner suivi hebdomadaire', 'Téléverser justificatifs hebdomadaires']
                    : ($canManageActions
                        ? ['Consulter', 'Créer', 'Modifier', 'Paramétrer indicateur', 'Supprimer', 'Clôturer', 'Suivi hebdomadaire']
                        : ($hasDelegatedActionReview
                            ? ['Consulter', 'Évaluer', 'Valider ou rejeter']
                            : ['Consulter'])),
            ];
        }

        if ($canReadReporting) {
            $modules[] = [
                'code' => 'reporting',
                'label' => 'Reporting',
                'description' => 'Reporting consolidé, exports et diffusion',
                'endpoint' => '/workspace/reporting',
                'can_write' => false,
                'actions' => ['Consulter', 'Exporter'],
            ];
        }

        if ($canReadAlerts) {
            $modules[] = [
                'code' => 'alertes',
                'label' => 'Alertes',
                'description' => 'Centre des alertes et écarts de suivi',
                'endpoint' => '/workspace/alertes',
                'can_write' => false,
                'actions' => ['Consulter', 'Marquer comme lu'],
            ];
        }

        if ($matrixRole === User::ROLE_SUPER_ADMIN) {
            $modules[] = [
                'code' => 'super_admin',
                'label' => 'Super Administration',
                'description' => "Paramétrage profond, templates d'export et gouvernance de plateforme",
                'endpoint' => '/workspace/super-admin',
                'can_write' => true,
                'actions' => ['Consulter', 'Configurer', 'Publier', 'Auditer'],
            ];
        }

        // Le module Référentiel devient permission-based : tout profil ayant
        // au moins une permission `referentiel.*` ou `users.*` y a accès. Le
        // niveau d'écriture est ensuite gradué par `canWriteReferentiel` /
        // `canManageUsers`.
        if ($canReadReferentiel) {
            $modules[] = [
                'code' => 'referentiel',
                'label' => 'Référentiels',
                'description' => 'Directions, services, utilisateurs',
                'endpoint' => '/api/v1/referentiel/utilisateurs',
                'can_write' => $canWriteReferentiel || $canManageUsers,
                'actions' => ($canWriteReferentiel || $canManageUsers)
                    ? ['Consulter', 'Administrer']
                    : ['Consulter'],
            ];
        }

        // Le module Audit devient permission-based : la permission
        // `audit.read` suffit. Le filtre isAuditor reste comme garde-fou
        // organisationnel pour les profils qui auraient la permission par
        // erreur de configuration (sécurité en profondeur).
        if ($canSeeAuditModule) {
            $modules[] = [
                'code' => 'audit',
                'label' => 'Journal Audit',
                'description' => 'Traçabilité des actions utilisateurs',
                'endpoint' => '/api/v1/journal-audit',
                'can_write' => false,
                'actions' => ['Consulter'],
            ];
        }

        if ($canReadApiDocs && $isTechnicalAdmin) {
            $modules[] = [
                'code' => 'api_docs',
                'label' => 'Documentation API',
                'description' => 'Contrats OpenAPI et Swagger UI',
                'endpoint' => '/workspace/documentation-api',
                'can_write' => false,
                'actions' => ['Consulter'],
            ];
        }

        if ($canReadRetention && $isTechnicalAdmin) {
            $modules[] = [
                'code' => 'retention',
                'label' => 'Rétention',
                'description' => 'Archivage et gouvernance des données',
                'endpoint' => '/workspace/retention',
                'can_write' => $canManageRetention,
                'actions' => $canManageRetention ? ['Consulter', 'Piloter'] : ['Consulter'],
            ];
        }

        // Délégations : la permission `delegations.manage` suffit. Le filtre
        // role précédent excluait SCIQ et chef_unite_sciq qui ont pourtant
        // cette permission dans la matrice (rôles métier de pilotage).
        if ($canManageDelegations) {
            $modules[] = [
                'code' => 'delegations',
                'label' => 'Délégations',
                'description' => 'Suppléance temporaire de validation',
                'endpoint' => '/workspace/referentiel/delegations',
                'can_write' => true,
                'actions' => ['Consulter', 'Créer', 'Annuler'],
            ];
        }

        return $this->workspaceModuleSettings->applyToModules($modules);
    }

    private function matrixRole(User $user): string
    {
        $registry = app(RoleRegistryService::class);
        $role = $user->effectiveRoleCode();

        if (array_key_exists($role, $registry->labels())) {
            return $registry->baseRole($role);
        }

        return $registry->baseRole((string) $user->role);
    }
}
