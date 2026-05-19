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
        $hasDelegatedPlanningRead = $user->hasDelegatedPermission('planning_read')
            || $user->hasDelegatedPermission('planning_write');
        $hasDelegatedPlanningWrite = $user->hasDelegatedPermission('planning_write');
        $hasDelegatedActionReview = $user->hasDelegatedPermission('action_review');

        $canReadPlanning = $user->hasPermission('planning.read')
            || $hasDelegatedPlanningRead;
        $canWriteGlobal = $user->hasPermission('planning.write.global');
        $canWriteDirection = ($user->hasRole(User::ROLE_DIRECTION) && $user->hasPermission('planning.write.direction'))
            || $hasDelegatedPlanningWrite;
        $canWriteService = ($user->hasRole(User::ROLE_SERVICE) && $user->hasPermission('planning.write.service'))
            || $hasDelegatedPlanningWrite;
        $isAgent = $user->isAgent() || $user->hasRole(User::ROLE_UCAS);
        $hasOwnExecutionSpace = $isAgent || $user->hasRole(User::ROLE_SCIQ, User::ROLE_COLLABORATEUR, User::ROLE_CABINET);
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
        $isTechnicalAdmin = $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN);
        // Admin fonctionnel et SCIQ suivi global ont un rôle proche de Planification
        // pour la gestion des référentiels métier.
        $isPlanification = $user->hasRole(
            User::ROLE_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_ADMIN_FONCTIONNEL,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ,
        );
        // Cabinet et ses variantes (supervision DGA/Cabinet, auditeur) peuvent voir l'audit.
        $isCabinet = $user->hasRole(
            User::ROLE_CABINET,
            User::ROLE_COLLABORATEUR,
            User::ROLE_CABINET_SUPERVISION,
            User::ROLE_DGA_SUPERVISION,
            User::ROLE_CHEF_UNITE_DGA,
            User::ROLE_CHEF_UNITE_CABINET,
            User::ROLE_AUDITEUR,
        );

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

        $isServiceOnly = $user->hasRole(User::ROLE_SERVICE)
            && ! $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_CABINET, User::ROLE_COLLABORATEUR);
        $isDirectionOnly = $user->hasRole(User::ROLE_DIRECTION)
            && ! $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_CABINET, User::ROLE_COLLABORATEUR);

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

        if ($user->isSuperAdmin()) {
            $modules[] = [
                'code' => 'super_admin',
                'label' => 'Super Administration',
                'description' => "Paramétrage profond, templates d'export et gouvernance de plateforme",
                'endpoint' => '/workspace/super-admin',
                'can_write' => true,
                'actions' => ['Consulter', 'Configurer', 'Publier', 'Auditer'],
            ];
        }

        if ($canReadReferentiel && ($isTechnicalAdmin || $isPlanification)) {
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

        if ($canReadAudit && ($isTechnicalAdmin || $isCabinet)) {
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

        if ($canManageDelegations && ($isTechnicalAdmin || $isPlanification || $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE))) {
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
}
