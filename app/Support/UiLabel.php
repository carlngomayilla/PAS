<?php

namespace App\Support;

final class UiLabel
{
    public static function object(string $key): string
    {
        return match ($key) {
            'entity' => 'Entite',
            'associated_entity' => 'Entite associee',
            'justifiable_entity' => 'Entite justifiable',
            'action' => 'Action',
            'objectif' => 'Objectif',
            'kpi' => 'Indicateur',
            'kpi_mesure' => 'Mesure indicateur',
            'justificatif' => 'Justificatif',
            'budget' => 'Budget',
            'alerte' => 'Alerte',
            'reporting' => 'Reporting',
            'pas' => 'PAS',
            'pas_axe' => 'Axe strategique PAS',
            'pas_objectif' => 'Objectif strategique PAS',
            'pao' => 'PAO',
            'pao_axe' => 'Axe strategique PAO',
            'pao_objectif_strategique' => 'Objectif strategique PAO',
            'pao_objectif_operationnel' => 'Objectif operationnel',
            'pta' => 'PTA',
            default => ucfirst(str_replace('_', ' ', trim($key))),
        };
    }

    public static function metric(string $key): string
    {
        return match ($key) {
            'delai' => 'Indicateur delai',
            'performance' => 'Indicateur performance',
            'conformite' => 'Indicateur conformite',
            'qualite' => 'Indicateur qualite',
            'risque' => 'Indicateur risque',
            'global' => 'Indicateur global',
            'moyen' => 'Indicateur moyen',
            default => trim(self::object('kpi') . ' ' . str_replace('_', ' ', $key)),
        };
    }

    public static function indicatorInputMode(bool|int|string|null $requiresInput): string
    {
        if ($requiresInput === null || $requiresInput === '') {
            return 'A renseigner';
        }

        $normalized = filter_var($requiresInput, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $isManual = $normalized ?? (bool) $requiresInput;

        return $isManual ? 'A renseigner' : 'Sans saisie';
    }

    public static function actionStatus(string|null $status): string
    {
        return match ((string) $status) {
            'non_demarre' => 'Non demarre',
            'en_cours' => 'En cours',
            'a_risque' => 'A risque',
            'en_retard' => 'En retard',
            'bloque' => 'Bloque',
            'termine' => 'Acheve',
            'acheve' => 'Acheve',
            'acheve_dans_delai' => 'Acheve',
            'acheve_hors_delai' => 'Acheve hors delai',
            'suspendu' => 'Suspendu',
            'annule' => 'Annule',
            'en_avance' => 'En avance',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }

    public static function validationStatus(string|null $status): string
    {
        return match ((string) $status) {
            'non_soumise' => 'Non soumise',
            'soumise_chef' => 'Soumise service',
            'rejetee_chef' => 'Rejetee service',
            'validee_chef' => 'Validee service',
            'rejetee_direction' => 'Rejetee direction',
            'validee_direction' => 'Validee',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }

    public static function workflowStatus(string|null $status): string
    {
        return match ((string) $status) {
            'brouillon' => 'Brouillon',
            'soumis' => 'Soumis',
            'valide' => 'Valide',
            'verrouille' => 'Verrouille',
            'valide_ou_verrouille' => 'Valide ou verrouille',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }

    public static function delegationStatus(string|null $status): string
    {
        return match ((string) $status) {
            'active' => 'Active',
            'cancelled' => 'Annulee',
            'expired' => 'Expiree',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }
}
