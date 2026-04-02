<?php

namespace App\Services;

use App\Models\User;

class UserWorkspaceService
{
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
        $hasDelegatedActionReview  = $user->hasDelegatedPermission('action_review');

        $canReadPlanning    = $user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)
            || $hasDelegatedPlanningRead;
        $canWriteGlobal     = $user->hasGlobalWriteAccess();
        $canWriteDirection  = $user->hasRole(User::ROLE_DIRECTION) || $hasDelegatedPlanningWrite;
        $canWriteService    = $user->hasRole(User::ROLE_SERVICE)   || $hasDelegatedPlanningWrite;
        $isAgent            = $user->isAgent();
        $canWriteOperational = $canWriteGlobal || $canWriteDirection || $canWriteService;
        $canManageActions   = $canWriteOperational && ! $isAgent;

        $modules = [];

        // Messagerie — accessible à tous les utilisateurs authentifiés
        $modules[] = [
            'code'        => 'messagerie',
            'label'       => 'Messagerie',
            'description' => 'Annuaire interactif et echanges internes',
            'endpoint'    => '/workspace/messagerie',
            'can_write'   => true,
            'actions'     => ['Consulter', 'Ecrire', 'Suivre non lus'],
        ];

        if ($canReadPlanning) {
            $modules[] = [
                'code'        => 'pas',
                'label'       => 'PAS',
                'description' => 'Vision strategique pluriannuelle',
                'endpoint'    => '/api/v1/pas',
                'can_write'   => $canWriteGlobal,
                'actions'     => $canWriteGlobal
                    ? ['Consulter', 'Creer', 'Modifier', 'Valider', 'Verrouiller']
                    : ['Consulter'],
            ];

            $modules[] = [
                'code'        => 'pao',
                'label'       => 'PAO',
                'description' => 'Declinaison annuelle par direction',
                'endpoint'    => '/api/v1/paos',
                'can_write'   => $canWriteGlobal || $canWriteDirection,
                'actions'     => ($canWriteGlobal || $canWriteDirection)
                    ? ['Consulter', 'Creer', 'Modifier', 'Suivre']
                    : ['Consulter'],
            ];

            $modules[] = [
                'code'        => 'pta',
                'label'       => 'PTA',
                'description' => 'Planification operationnelle par service',
                'endpoint'    => '/api/v1/ptas',
                'can_write'   => $canWriteGlobal || $canWriteDirection || $canWriteService,
                'actions'     => ($canWriteGlobal || $canWriteDirection || $canWriteService)
                    ? ['Consulter', 'Creer', 'Modifier', 'Executer']
                    : ['Consulter'],
            ];

        }

        if ($canReadPlanning || $isAgent || $hasDelegatedActionReview) {
            $modules[] = [
                'code'        => 'execution',
                'label'       => 'Actions',
                'description' => 'Execution des taches et suivi de progression',
                'endpoint'    => '/api/v1/actions',
                'can_write'   => $canWriteOperational || $isAgent || $hasDelegatedActionReview,
                'actions'     => $isAgent
                    ? ['Consulter', 'Renseigner suivi hebdomadaire', 'Televerser justificatifs hebdomadaires']
                    : ($canManageActions
                        ? ['Consulter', 'Creer', 'Modifier', 'Parametrer indicateur', 'Supprimer', 'Cloturer', 'Suivi hebdomadaire']
                        : ($hasDelegatedActionReview
                            ? ['Consulter', 'Evaluer', 'Valider ou rejeter']
                            : ['Consulter'])),
            ];
        }

        if ($canReadPlanning) {
            $modules[] = [
                'code'        => 'alertes',
                'label'       => 'Alertes',
                'description' => 'Retards et indicateurs sous seuil',
                'endpoint'    => '/api/v1/alertes',
                'can_write'   => false,
                'actions'     => ['Consulter'],
            ];

            $modules[] = [
                'code'        => 'reporting',
                'label'       => 'Reporting',
                'description' => 'Tableau de bord consolide des indicateurs',
                'endpoint'    => '/api/v1/reporting/overview',
                'can_write'   => false,
                'actions'     => ['Consulter'],
            ];
        }

        if ($user->hasGlobalReadAccess()) {
            $modules[] = [
                'code'        => 'referentiel',
                'label'       => 'Referentiels',
                'description' => 'Directions, services, utilisateurs',
                'endpoint'    => '/api/v1/referentiel/utilisateurs',
                'can_write'   => $canWriteGlobal,
                'actions'     => $canWriteGlobal
                    ? ['Consulter', 'Administrer']
                    : ['Consulter'],
            ];

            $modules[] = [
                'code'        => 'audit',
                'label'       => 'Journal Audit',
                'description' => 'Tracabilite des actions utilisateurs',
                'endpoint'    => '/api/v1/journal-audit',
                'can_write'   => false,
                'actions'     => ['Consulter'],
            ];

            $modules[] = [
                'code'        => 'api_docs',
                'label'       => 'Documentation API',
                'description' => 'Contrats OpenAPI et Swagger UI',
                'endpoint'    => '/workspace/documentation-api',
                'can_write'   => false,
                'actions'     => ['Consulter'],
            ];

            $modules[] = [
                'code'        => 'retention',
                'label'       => 'Retention',
                'description' => 'Archivage et gouvernance des donnees',
                'endpoint'    => '/workspace/retention',
                'can_write'   => $canWriteGlobal,
                'actions'     => $canWriteGlobal ? ['Consulter', 'Piloter'] : ['Consulter'],
            ];
        }

        if ($canWriteGlobal) {
            $modules[] = [
                'code'        => 'delegations',
                'label'       => 'Delegations',
                'description' => 'Suppleance temporaire de validation',
                'endpoint'    => '/workspace/referentiel/delegations',
                'can_write'   => true,
                'actions'     => ['Consulter', 'Creer', 'Annuler'],
            ];
        }

        return $modules;
    }
}
