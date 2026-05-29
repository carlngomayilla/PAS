<?php

namespace App\Services;

use App\Models\User;

class UserProfileService
{
    /**
     * Retourne les interactions du profil pour un utilisateur donné.
     *
     * @return array<string, mixed>
     */
    public function interactionsFor(User $user): array
    {
        $items = match ($user->role) {
            User::ROLE_SUPER_ADMIN => [
                [
                    'module'     => 'Super Administration',
                    'operations' => ['Piloter plateforme', 'Configurer exports', 'Publier regles globales', 'Auditer'],
                    'portee'     => 'Globale',
                ],
                [
                    'module'     => 'Gouvernance systeme',
                    'operations' => ['Gérer modules', 'Ajuster navigation', 'Maintenir coherence des parametres'],
                    'portee'     => 'Globale',
                ],
            ],
            User::ROLE_ADMIN_FONCTIONNEL => [
                [
                    'module'     => 'Gouvernance PAS/PAO/PTA',
                    'operations' => ['Creer', 'Modifier', 'Supprimer', 'Cloturer', 'Archiver', 'Consulter'],
                    'portee'     => 'Toutes les directions et services',
                ],
                [
                    'module'     => 'Execution et performance',
                    'operations' => ['Piloter actions', 'Suivre progression globale', 'Consulter alertes'],
                    'portee'     => 'Globale',
                ],
                [
                    'module'     => 'Administration',
                    'operations' => ['Gérer utilisateurs', 'Consulter journal audit'],
                    'portee'     => 'Globale',
                ],
            ],
            User::ROLE_DG => [
                [
                    'module'     => 'PAS',
                    'operations' => ['Consulter', 'Arbitrer exceptions'],
                    'portee'     => 'Globale',
                ],
                [
                    'module'     => 'PAO / PTA',
                    'operations' => ['Superviser', 'Valider', 'Consulter'],
                    'portee'     => 'Globale',
                ],
                [
                    'module'     => 'Reporting',
                    'operations' => ['Consulter tableaux de bord consolides'],
                    'portee'     => 'Globale',
                ],
            ],
            User::ROLE_PLANIFICATION => [
                [
                    'module'     => 'Structuration PAS/PAO/PTA',
                    'operations' => ['Creer', 'Modifier', 'Cloturer', 'Consulter'],
                    'portee'     => 'Globale',
                ],
                [
                    'module'     => 'Objectifs stratégiques',
                    'operations' => ['Definir axes/objectifs', 'Configurer indicateurs de suivi'],
                    'portee'     => 'Globale',
                ],
                [
                    'module'     => 'Suivi et reporting',
                    'operations' => ['Consolider avancement', 'Produire rapports'],
                    'portee'     => 'Globale',
                ],
            ],
            User::ROLE_DIRECTION => [
                [
                    'module'     => 'PAO de la direction',
                    'operations' => ['Creer', 'Modifier', 'Suivre'],
                    'portee'     => 'Direction rattachee',
                ],
                [
                    'module'     => 'PTA et execution',
                    'operations' => ['Superviser services', 'Suivre actions'],
                    'portee'     => 'Direction rattachee',
                ],
            ],
            User::ROLE_SERVICE => [
                [
                    'module'     => 'PTA du service',
                    'operations' => ['Exécuter taches', 'Mettre à jour statuts'],
                    'portee'     => 'Direction et service rattaches',
                ],
                [
                    'module'     => 'Actions',
                    'operations' => ['Renseigner execution', 'Saisir suivi hebdomadaire', 'Téléverser justificatifs', 'Signaler alertes'],
                    'portee'     => 'Direction et service rattaches',
                ],
            ],
            User::ROLE_AGENT => [
                [
                    'module'     => 'Suivi hebdomadaire des actions',
                    'operations' => ['Renseigner suivi hebdomadaire', 'Mettre à jour progression', 'Signaler difficultés', 'Téléverser justificatifs hebdomadaires'],
                    'portee'     => 'Direction et service rattaches',
                ],
            ],
            User::ROLE_AUDITEUR => [
                [
                    'module'     => 'Pilotage',
                    'operations' => ['Consulter PAS/PAO/PTA', 'Consulter reporting'],
                    'portee'     => 'Globale',
                ],
                [
                    'module'     => 'Audit',
                    'operations' => ['Lecture seule des informations'],
                    'portee'     => 'Globale',
                ],
            ],
            default => [],
        };

        return [
            'role'       => $user->role,
            'role_label' => $user->roleLabel(),
            'scope'      => $user->profileScopeLabel(),
            'items'      => [
                ...$items,
                [
                    'module'     => 'Messagerie interne',
                    'operations' => ['Consulter annuaire', 'Envoyer messages directs'],
                    'portee'     => $user->profileScopeLabel(),
                ],
            ],
        ];
    }
}
