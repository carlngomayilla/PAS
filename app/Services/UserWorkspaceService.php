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
        $isAgent = $user->isAgent();
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
        $isPlanification = $user->hasRole(User::ROLE_PLANIFICATION);
        $isCabinet = $user->hasRole(User::ROLE_CABINET);

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
            && ! $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET);
        $isDirectionOnly = $user->hasRole(User::ROLE_DIRECTION)
            && ! $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET);

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

        if ($canReadPlanning || $isAgent || $hasDelegatedActionReview) {
            $modules[] = [
                'code' => 'execution',
                'label' => 'Actions',
                'description' => 'Exécution des tâches et suivi de progression',
                'endpoint' => '/api/v1/actions',
                'can_write' => $canWriteOperational || $isAgent || $hasDelegatedActionReview,
                'actions' => $isAgent
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
