<?php

namespace App\Support;

final class UiLabel
{
    public static function object(string $key): string
    {
        return match ($key) {
            'entity' => 'Entité',
            'associated_entity' => 'Entité associée',
            'justifiable_entity' => 'Entité justifiable',
            'action' => 'Action',
            'objectif' => 'Objectif',
            'kpi' => 'Indicateur de performance',
            'kpi_mesure' => 'Mesure indicateur',
            'justificatif' => 'Justificatif',
            'budget' => 'Budget',
            'alerte' => 'Alerte',
            'reporting' => 'Reporting',
            'pas' => 'PAS',
            'pas_axe' => 'Axe stratégique PAS',
            'pas_objectif' => 'Objectif stratégique PAS',
            'pao' => 'PAO',
            'pao_axe' => 'Axe stratégique PAO',
            'pao_objectif_strategique' => 'Objectif stratégique PAO',
            'pao_objectif_operationnel' => 'Objectif opérationnel',
            'pta' => 'PTA',
            default => ucfirst(str_replace('_', ' ', trim($key))),
        };
    }

    public static function metric(string $key): string
    {
        return match ($key) {
            'delai' => 'Délai',
            'performance' => "Performance d'exécution",
            'conformite' => 'Conformité',
            'risque' => 'Point de vigilance',
            'global' => "Performance d'execution",
            'moyen' => 'Score de suivi moyen',
            default => trim(self::object('kpi') . ' ' . str_replace('_', ' ', $key)),
        };
    }

    public static function indicatorInputMode(bool|int|string|null $requiresInput): string
    {
        if ($requiresInput === null || $requiresInput === '') {
            return 'À renseigner';
        }

        $normalized = filter_var($requiresInput, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $isManual = $normalized ?? (bool) $requiresInput;

        return $isManual ? 'À renseigner' : 'Sans saisie';
    }

    public static function actionStatus(string|null $status): string
    {
        return match ((string) $status) {
            'non_demarre' => 'Non démarré',
            'en_cours' => 'En cours',
            'a_risque' => 'A surveiller',
            'en_attente_justificatif' => 'En attente justificatif',
            'en_attente_validation' => 'En attente validation',
            'realisee' => 'Réalisée',
            'validee' => 'Validée',
            'rejetee' => 'Rejetée',
            'en_retard' => 'En retard',
            'bloque' => 'Bloqué',
            'termine' => 'Achevé',
            'acheve' => 'Achevé',
            'acheve_dans_delai' => 'Achevé',
            'acheve_hors_delai' => 'Achevé hors délai',
            'suspendu' => 'Suspendu',
            'annule' => 'Annulé',
            'en_avance' => 'En avance',
            'a_corriger' => 'À corriger',
            'cloturee' => 'Clôturée',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }

    public static function validationStatus(string|null $status): string
    {
        return match ((string) $status) {
            'non_soumise' => 'Non soumise',
            'soumise_chef' => 'Soumise service',
            'rejetee_chef' => 'Rejetée service',
            'correction_demandee' => 'Correction demandée',
            'validee_chef' => 'Validée service',
            'rejetee_direction' => 'Rejetée direction',
            'validee_direction' => 'Validée',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }

    public static function workflowStatus(string|null $status): string
    {
        return match ((string) $status) {
            'brouillon' => 'Brouillon',
            'soumis' => 'Soumis',
            'valide' => 'Validé',
            'verrouille' => 'Verrouillé',
            'fin' => 'Fin',
            'valide_ou_verrouille' => 'Validé ou verrouillé',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }

    public static function delegationStatus(string|null $status): string
    {
        return match ((string) $status) {
            'active' => 'Active',
            'cancelled' => 'Annulée',
            'expired' => 'Expirée',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }
}
