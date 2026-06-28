<?php

namespace App\Services;

use App\Models\User;

class UserWorkspaceService
{
    public function __construct(
        private readonly WorkspaceModuleSettings $workspaceModuleSettings
    ) {}

    /**
     * Retourne la liste des modules accessibles pour l'utilisateur donné.
     *
     * Refondu pour s'aligner sur la spec canonique PAS ANBG. Chaque sidebar est
     * déterminée par le « rôle métier » resolu via
     * {@see self::specSidebarRole()} : agent, chef, sciq_planif, directeur,
     * directeur_daf, dg, dga_cabinet, ucas, super_admin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function modulesFor(User $user): array
    {
        $specRole = $this->specSidebarRole($user);
        $modules = $this->modulesForSpecRole($specRole, $user);
        $modules = array_values(array_filter(
            $modules,
            fn (array $module): bool => $this->moduleAllowedByPermissions($module, $user)
        ));

        return $this->workspaceModuleSettings->applyToModules($modules);
    }

    /**
     * Ancienne logique permission-based de modulesFor(), conservee pour
     * compatibilite avec d'eventuels appels externes. Ne pas l'utiliser pour
     * la sidebar (preferer modulesFor()).
     *
     * @return array<int, array<string, mixed>>
     */
    public function legacyModulesFor(User $user): array
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
                    ? ['Consulter', 'Creer', 'Modifier', 'Cloturer', 'Archiver']
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
                'label' => 'Action',
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

    /**
     * Resout le « role sidebar » d'apres la spec canonique. Renvoie un parmi :
     * agent, chef, sciq_planif, directeur, directeur_daf, dg, dga_cabinet,
     * ucas, super_admin.
     */
    public function specSidebarRole(User $user): string
    {
        $base = $this->matrixRole($user);

        if (in_array($base, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_FONCTIONNEL], true)) {
            return 'super_admin';
        }

        if ($base === User::ROLE_DG) {
            return 'dg';
        }

        if (in_array($base, [
            User::ROLE_CABINET,
            User::ROLE_CABINET_SUPERVISION,
            User::ROLE_DGA_SUPERVISION,
            User::ROLE_COLLABORATEUR,
        ], true)) {
            return 'dga_cabinet';
        }

        if (in_array($base, [
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ,
        ], true)) {
            return 'sciq_planif';
        }

        if ($base === User::ROLE_DIRECTION) {
            $directionCode = (string) ($user->direction?->code ?? '');

            return $directionCode === 'DAF' ? 'directeur_daf' : 'directeur';
        }

        if (in_array($base, [User::ROLE_UCAS, User::ROLE_CHEF_UNITE_UCAS], true)) {
            return 'ucas';
        }

        if (in_array($base, [
            User::ROLE_SERVICE,
            User::ROLE_CHEF_UNITE,
            User::ROLE_CHEF_UNITE_DGA,
            User::ROLE_CHEF_UNITE_CABINET,
        ], true)) {
            return 'chef';
        }

        // Defaut : agent / RMO
        return 'agent';
    }

    /**
     * Modules d'une sidebar pour un role sidebar donne (selon spec).
     *
     * @return array<int, array<string, mixed>>
     */
    private function modulesForSpecRole(string $specRole, User $user): array
    {
        // Helper interne pour batir un module.
        $m = static fn (string $code, string $label, string $endpoint, array $extra = []): array => array_merge([
            'code' => $code,
            'label' => $label,
            'endpoint' => $endpoint,
            'can_write' => false,
            'actions' => ['Consulter'],
        ], $extra);

        return match ($specRole) {
            // Note (2026-05-29) : les modules historiques sans page dediee
            // (controle, corrections, agents, synthese_agence, arbitrages,
            // financements_critiques, rapports_consolides, supervision) ont ete
            // recables vers les pages fonctionnelles existantes avec les bons
            // filtres URL, evitant l'ecran placeholder "en cours de raccordement".
            'agent' => [
                $m('pilotage', 'Dashboard', '/dashboard'),
                $m('mes_taches', 'Mes tâches', '/workspace/mes-taches'),
                $m('execution', 'Action', '/workspace/actions?vue=mes_actions', ['can_write' => true, 'actions' => ['Consulter', 'Saisir suivi']]),
                // 'corrections' → vue "Mes actions" filtree sur les corrections demandees.
                $m('corrections', 'Corrections demandées', '/workspace/actions?vue=mes_actions&statut=a_corriger'),
                $m('notifications', 'Notifications', '/workspace/notifications'),
            ],

            'chef' => [
                $m('pilotage', 'Dashboard', '/dashboard'),
                $m('mes_taches', 'Mes tâches', '/workspace/mes-taches'),
                $m('pta', 'PTA', '/workspace/pta', ['can_write' => true, 'actions' => ['Consulter', 'Créer', 'Modifier', 'Clôturer']]),
                $m('ai_imports', 'IA & Imports', '/workspace/ai-imports/pta', ['can_write' => true, 'actions' => ['Charger', 'Corriger', 'Valider']]),
                $m('execution', 'Action', '/workspace/actions', ['can_write' => true, 'actions' => ['Consulter', 'Créer', 'Modifier', 'Valider', 'Renvoyer']]),
                // La validation est désormais traitée dans l'onglet Actions > Validations.
                // 'agents' → liste des utilisateurs du referentiel (deja filtree par scope).
                $m('agents', 'Agents / RMO', '/workspace/referentiel/utilisateurs'),
                $m('reporting', 'Reporting service', '/workspace/reporting'),
                $m('ai_reports', 'Rapports IA', '/workspace/ai-reports', ['can_write' => true, 'actions' => ['Generer', 'Exporter']]),
                $m('notifications', 'Notifications', '/workspace/notifications'),
            ],

            'sciq_planif' => [
                $m('pilotage', 'Dashboard global', '/dashboard'),
                $m('mes_taches', 'Mes tâches', '/workspace/mes-taches'),
                $m('pas', 'PAS', '/workspace/pas', ['can_write' => true, 'actions' => ['Consulter', 'Créer', 'Modifier', 'Clôturer']]),
                $m('pao', 'PAO', '/workspace/pao'),
                $m('pta', 'PTA', '/workspace/pta'),
                $m('imports_excel', 'Imports Excel', '/workspace/imports-excel', ['can_write' => true, 'actions' => ['Verifier', 'Mapper colonnes', 'Importer']]),
                $m('ai_imports', 'IA & Imports', '/workspace/ai-imports/pta', ['can_write' => true, 'actions' => ['Analyser', 'Valider', 'Importer']]),
                $m('execution', 'Action', '/workspace/actions'),
                // 'controle' → onglet "Validations" des actions.
                $m('controle', 'Contrôle', '/workspace/actions?vue=validations', ['can_write' => true, 'actions' => ['Signaler', 'Bloquer', 'Lever blocage']]),
                ...($user->isPlanningControlChief()
                    ? [$m('referentiel', 'Utilisateurs', '/workspace/referentiel/utilisateurs', ['can_write' => true, 'actions' => ['Consulter', 'Administrer utilisateurs']])]
                    : []),
                $m('reporting', 'Reporting global', '/workspace/reporting'),
                $m('ai_reports', 'Rapports IA', '/workspace/ai-reports', ['can_write' => true, 'actions' => ['Generer', 'Valider', 'Exporter']]),
                $m('notifications', 'Notifications', '/workspace/notifications'),
            ],

            'directeur' => [
                $m('pilotage', 'Dashboard direction', '/dashboard'),
                $m('mes_taches', 'Mes tâches', '/workspace/mes-taches'),
                $m('pao', 'PAO', '/workspace/pao', ['can_write' => true, 'actions' => ['Consulter', 'Créer', 'Modifier', 'Clôturer']]),
                $m('pta', 'PTA des services', '/workspace/pta'),
                $m('ai_imports', 'IA & Imports', '/workspace/ai-imports/pta', ['can_write' => true, 'actions' => ['Charger', 'Corriger', 'Valider']]),
                $m('execution', 'Action', '/workspace/actions'),
                // 'services_agents' → referentiel utilisateurs (filtree par direction).
                $m('services_agents', 'Services / Agents', '/workspace/referentiel/utilisateurs'),
                $m('reporting', 'Reporting direction', '/workspace/reporting'),
                $m('ai_reports', 'Rapports IA', '/workspace/ai-reports', ['can_write' => true, 'actions' => ['Generer', 'Exporter']]),
                $m('notifications', 'Notifications', '/workspace/notifications'),
            ],

            'directeur_daf' => [
                $m('pilotage', 'Dashboard direction', '/dashboard'),
                $m('mes_taches', 'Mes tâches', '/workspace/mes-taches'),
                $m('pao', 'PAO', '/workspace/pao', ['can_write' => true, 'actions' => ['Consulter', 'Créer', 'Modifier', 'Clôturer']]),
                $m('pta', 'PTA des services', '/workspace/pta'),
                $m('ai_imports', 'IA & Imports', '/workspace/ai-imports/pta', ['can_write' => true, 'actions' => ['Charger', 'Corriger', 'Valider']]),
                $m('execution', 'Action', '/workspace/actions'),
                $m('services_agents', 'Services / Agents', '/workspace/referentiel/utilisateurs'),
                $m('reporting', 'Reporting direction', '/workspace/reporting'),
                $m('ai_reports', 'Rapports IA', '/workspace/ai-reports', ['can_write' => true, 'actions' => ['Generer', 'Exporter']]),
                $m('financement', 'Financement des actions', '/workspace/daf/financements-actions', ['can_write' => true, 'actions' => ['Valider', 'Rejeter', 'Demander complément', 'Transmettre DG']]),
                $m('notifications', 'Notifications', '/workspace/notifications'),
            ],

            'dg' => [
                $m('pilotage', 'Dashboard global', '/dashboard'),
                $m('mes_taches', 'Mes tâches', '/workspace/mes-taches'),
                // 'synthese_agence' → reporting global (memes donnees consolidees).
                $m('synthese_agence', 'Synthèse agence', '/workspace/reporting'),
                // 'arbitrages' → demandes de deverrouillage (le DG y prend ses decisions).
                $m('arbitrages', 'Arbitrages', '/workspace/demandes-deverrouillage'),
                $m('deverrouillages', 'Deverrouillages', '/workspace/demandes-deverrouillage', ['can_write' => true, 'actions' => ['Approuver', 'Rejeter']]),
                // 'financements_critiques' → page financement DAF.
                $m('financements_critiques', 'Financements critiques', '/workspace/daf/financements-actions'),
                // 'rapports_consolides' → reporting global avec exports.
                $m('rapports_consolides', 'Rapports consolidés', '/workspace/reporting'),
                $m('ai_reports', 'Rapports IA', '/workspace/ai-reports', ['can_write' => true, 'actions' => ['Generer', 'Exporter']]),
                $m('notifications', 'Notifications', '/workspace/notifications'),
            ],

            'dga_cabinet' => [
                $m('pilotage', 'Dashboard global', '/dashboard'),
                $m('mes_taches', 'Mes tâches', '/workspace/mes-taches'),
                $m('synthese_agence', 'Synthèse agence', '/workspace/reporting'),
                // 'supervision' → reporting global (vue d'ensemble).
                $m('supervision', 'Supervision', '/workspace/reporting'),
                $m('rapports_consolides', 'Rapports', '/workspace/reporting'),
                $m('ai_reports', 'Rapports IA', '/workspace/ai-reports', ['can_write' => true, 'actions' => ['Generer', 'Exporter']]),
                $m('execution', 'Action', '/workspace/actions?vue=mes_actions'),
                $m('notifications', 'Notifications', '/workspace/notifications'),
            ],

            'ucas' => [
                $m('pilotage', 'Dashboard unité', '/dashboard'),
                $m('mes_taches', 'Mes tâches', '/workspace/mes-taches'),
                $m('pta', 'PTA', '/workspace/pta', ['can_write' => true, 'actions' => ['Consulter', 'Créer', 'Modifier']]),
                $m('ai_imports', 'IA & Imports', '/workspace/ai-imports/pta', ['can_write' => true, 'actions' => ['Charger', 'Corriger', 'Valider']]),
                $m('execution', 'Action', '/workspace/actions', ['can_write' => true, 'actions' => ['Consulter', 'Valider', 'Renvoyer']]),
                // Validation fusionnée dans l'onglet Actions > Validations.
                $m('agents', 'Agents / RMO', '/workspace/referentiel/utilisateurs'),
                $m('reporting', 'Reporting unité', '/workspace/reporting'),
                $m('ai_reports', 'Rapports IA', '/workspace/ai-reports', ['can_write' => true, 'actions' => ['Generer', 'Exporter']]),
                $m('notifications', 'Notifications', '/workspace/notifications'),
            ],

            'super_admin' => [
                $m('pilotage', 'Dashboard admin', '/dashboard'),
                $m('super_admin', 'Super Administration', '/workspace/super-admin', ['can_write' => true]),
                $m('imports_excel', 'Imports Excel', '/workspace/imports-excel', ['can_write' => true, 'actions' => ['Verifier', 'Mapper colonnes', 'Importer']]),
                $m('ai_imports', 'IA & Imports', '/workspace/ai-imports/pta', ['can_write' => true, 'actions' => ['Analyser', 'Valider', 'Importer']]),
                $m('ai_reports', 'Rapports IA', '/workspace/ai-reports', ['can_write' => true, 'actions' => ['Generer', 'Valider', 'Exporter']]),
                $m('referentiel', 'Utilisateurs', '/workspace/referentiel/utilisateurs', ['can_write' => true]),
                $m('roles_permissions', 'Rôles & permissions', '/workspace/super-admin/roles-permissions', ['can_write' => true]),
                $m('organisation', 'Directions / Services', '/workspace/super-admin/organisation-utilisateurs', ['can_write' => true]),
                $m('exercices', 'Exercices / periodes', '/workspace/super-admin/exercices-periodes', ['can_write' => true]),
                $m('workflows', 'Workflows', '/workspace/super-admin/workflow-validations', ['can_write' => true]),
                $m('audit', 'Journal audit', '/workspace/audit'),
                $m('retention', 'Rétention / Maintenance', '/workspace/retention', ['can_write' => true]),
                $m('notifications', 'Notifications', '/workspace/notifications'),
            ],

            default => [
                $m('pilotage', 'Dashboard', '/dashboard'),
                $m('notifications', 'Notifications', '/workspace/notifications'),
            ],
        };
    }

    /**
     * La sidebar suit le profil metier canonique, puis les permissions Super
     * Admin retirent les modules sensibles sans toucher aux modules de base.
     *
     * @param  array<string, mixed>  $module
     */
    private function moduleAllowedByPermissions(array $module, User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ((string) ($module['code'] ?? '')) {
            'imports_excel' => $user->hasRole(
                User::ROLE_SUPER_ADMIN,
                User::ROLE_SCIQ,
                User::ROLE_PLANIFICATION,
                User::ROLE_CHEF_PLANIFICATION,
                User::ROLE_CHEF_UNITE_SCIQ
            ),
            'ai_imports' => $user->hasAnyPermission(
                'ai_pta_import.view',
                'ai_pta_import.upload',
                'ai_pta_import.analyze',
                'ai_pta_import.history'
            ),
            'ai_reports' => $user->hasPermission('ai_reports.view'),
            'reporting', 'rapports_consolides' => $user->hasPermission('reporting.read'),
            'financements_critiques' => $user->hasPermission('alerts.read'),
            'audit' => $user->hasPermission('audit.read'),
            'referentiel', 'roles_permissions', 'organisation' => $user->hasAnyPermission(
                'referentiel.read',
                'referentiel.write',
                'users.manage',
                'users.manage_roles'
            ),
            'super_admin', 'workflows' => $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_FONCTIONNEL),
            'retention' => $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_FONCTIONNEL)
                || $user->hasAnyPermission('retention.read', 'retention.manage'),
            default => true,
        };
    }
}
