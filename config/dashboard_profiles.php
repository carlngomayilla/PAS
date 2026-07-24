<?php

use App\Models\User;

return [
    'dg' => [
        'roles' => [User::ROLE_DG, User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_FONCTIONNEL],
        'max_cards' => 5,
        'cards' => ['strategic_progress', 'late_actions', 'validation_waiting', 'critical_alerts', 'global_execution'],
    ],
    'direction' => [
        'roles' => [User::ROLE_DIRECTION],
        'max_cards' => 4,
        'cards' => ['direction_progress', 'services_late', 'pta_to_validate', 'critical_alerts'],
    ],
    'service' => [
        'roles' => User::serviceOrUnitChiefRoles(),
        'max_cards' => 4,
        'cards' => ['service_progress', 'actions_to_review', 'agent_blockers', 'deadline_extensions'],
    ],
    'agent' => [
        'roles' => [User::ROLE_AGENT],
        'max_cards' => 3,
        'cards' => ['assigned_actions', 'due_soon', 'corrections_requested'],
    ],
    'planification' => [
        'roles' => [],
        'max_cards' => 5,
        'cards' => ['global_execution', 'pta_to_control', 'deadline_extensions', 'late_actions', 'data_quality'],
    ],
    'suivi_evaluation' => [
        'roles' => [User::ROLE_PLANIFICATION, User::ROLE_CHEF_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL, User::ROLE_CHEF_UNITE_SCIQ, User::ROLE_AUDITEUR, User::ROLE_INVITE_LECTURE],
        'max_cards' => 6,
        'cards' => ['global_execution', 'pta_to_control', 'deadline_extensions', 'late_actions', 'data_quality', 'evidence_gaps'],
    ],
    'default' => [
        'roles' => [],
        'max_cards' => 3,
        'cards' => ['assigned_actions', 'due_soon', 'critical_alerts'],
    ],
];
