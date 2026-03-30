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
            User::ROLE_ADMIN => [
                [
                    'module'     => 'Gouvernance PAS/PAO/PTA',
                    'operations' => ['Creer', 'Modifier', 'Supprimer', 'Valider', 'Verrouiller', 'Consulter'],
                    'portee'     => 'Toutes les directions et services',
                ],
                [
                    'module'     => 'Execution et performance',
                    'operations' => ['Piloter actions', 'Suivre progression globale', 'Consulter alertes'],
                    'portee'     => 'Globale',
                ],
                [
                    'module'     => 'Administration',
                    'operations' => ['Gerer utilisateurs', 'Consulter journal audit'],
                    'portee'     => 'Globale',
                ],
            ],
            User::ROLE_DG => [
                [
                    'module'     => 'PAS',
                    'operations' => ['Valider', 'Verrouiller', 'Consulter'],
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
                    'operations' => ['Creer', 'Modifier', 'Soumettre', 'Consulter'],
                    'portee'     => 'Globale',
                ],
                [
                    'module'     => 'Objectifs strategiques',
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
                    'operations' => ['Creer', 'Modifier', 'Suivre', 'Soumettre'],
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
                    'operations' => ['Executer taches', 'Mettre a jour statuts'],
                    'portee'     => 'Direction et service rattaches',
                ],
                [
                    'module'     => 'Actions',
                    'operations' => ['Renseigner execution', 'Saisir suivi hebdomadaire', 'Televerser justificatifs', 'Signaler risques'],
                    'portee'     => 'Direction et service rattaches',
                ],
            ],
            User::ROLE_AGENT => [
                [
                    'module'     => 'Suivi hebdomadaire des actions',
                    'operations' => ['Renseigner suivi hebdomadaire', 'Mettre a jour progression', 'Signaler difficultes', 'Televerser justificatifs hebdomadaires'],
                    'portee'     => 'Direction et service rattaches',
                ],
            ],
            User::ROLE_CABINET => [
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
                    'operations' => ['Consulter annuaire', 'Envoyer messages directs', 'Parcourir l organigramme'],
                    'portee'     => $user->profileScopeLabel(),
                ],
            ],
        ];
    }
}
