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
            default => trim(self::object('kpi').' '.str_replace('_', ' ', $key)),
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

    public static function actionStatus(?string $status): string
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

    public static function validationStatus(?string $status): string
    {
        return match ((string) $status) {
            'non_soumise' => 'Non soumise',
            'soumise_chef' => 'Soumise service',
            'rejetee_chef' => 'Rejetée service',
            'correction_demandee' => 'Correction demandée',
            'validee_chef' => 'Visa chef enregistré',
            'soumise_controle' => 'Soumise au contrôle',
            'correction_controle' => 'Correction demandée par le contrôle',
            'validee_controle' => 'Validée par le contrôle',
            'rejetee_direction' => 'Rejetée direction',
            'validee_direction' => 'Validée (ancienne direction)',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }

    public static function workflowStatus(?string $status): string
    {
        return match ((string) $status) {
            'actif' => 'Actif',
            'en_cours' => 'En cours',
            'controle_sciq' => 'Contrôle SCIQ',
            'cloture' => 'Clôturé',
            'archive' => 'Archivé',
            'brouillon' => 'Brouillon',
            'soumis' => 'Soumis',
            'valide' => 'Validé',
            'verrouille' => 'Verrouillé',
            'fin' => 'Fin',
            'valide_ou_verrouille' => 'Ancien statut validé ou verrouillé',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }

    public static function delegationStatus(?string $status): string
    {
        return match ((string) $status) {
            'active' => 'Active',
            'cancelled' => 'Annulée',
            'expired' => 'Expirée',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }

    /**
     * Pourcentage sans decimales inutiles : « 0% » et non « 0,00% ».
     * Les decimales n'apparaissent qu'a partir de 0,01.
     */
    public static function percent(?float $value, string $decimalSeparator = ','): string
    {
        if ($value === null) {
            return '-';
        }

        $rounded = round($value, 2);

        if (abs($rounded - round($rounded)) < 0.005) {
            return number_format($rounded, 0, $decimalSeparator, ' ').'%';
        }

        $formatted = number_format($rounded, 2, $decimalSeparator, ' ');

        return rtrim(rtrim($formatted, '0'), $decimalSeparator).'%';
    }

    /**
     * Libelle lisible d'un type d'evenement / d'alerte.
     *
     * Evite d'exposer les cles techniques (`progression_sous_seuil`,
     * `alerte_combinee_critique`, `action_validee_controle`...) dans le journal
     * d'alertes, les notifications et les exports.
     */
    public static function eventType(?string $type): string
    {
        return match ((string) $type) {
            // Alertes de suivi
            'progression_sous_seuil' => 'Progression inférieure au seuil attendu',
            'alerte_combinee_critique' => 'Retard critique',
            'action_overdue', 'retard_constate' => 'Action en retard',
            'action_a_surveiller' => 'Action à surveiller',
            'action_a_parametrer', 'action_pending_setup' => 'Action à paramétrer',
            'echeance_proche' => 'Échéance proche',
            'action_alert', 'action_alert_escalation' => 'Alerte sur l’action',

            // Cycle de vie de l'action
            'action_initialisee' => 'Action initialisée',
            'action_assigned' => 'Action assignée',
            'action_non_demarre' => 'Action non démarrée',
            'action_suspendue' => 'Action suspendue',
            'action_annulee' => 'Action annulée',
            'semaine_renseignee' => 'Suivi hebdomadaire renseigné',
            'sous_action_mise_a_jour' => 'Sous-action mise à jour',
            'sous_action_effectuee' => 'Sous-action réalisée',
            'execution_quantitative' => 'Exécution quantitative enregistrée',

            // Circuit de validation
            'action_soumise_validation', 'action_submitted_to_chef' => 'Action soumise au chef de service',
            'action_validee_chef', 'action_reviewed_by_chef' => 'Action visée par le chef de service',
            'action_rejetee_chef' => 'Action rejetée par le chef de service',
            'action_correction_demandee' => 'Correction demandée',
            'action_transmise_controle' => 'Action transmise au contrôle',
            'action_validee_controle' => 'Action validée par le contrôle',
            'action_rejetee_controle' => 'Action rejetée par le contrôle',
            'action_transmise_planification' => 'Action transmise à la planification',
            'action_validee_planification' => 'Action validée par la planification (clôture)',
            'action_rejetee_planification' => 'Action renvoyée par la planification',
            'action_finalized_by_chef' => 'Action finalisée par le chef de service',

            // Financement
            'action_financing_requested' => 'Financement demandé',
            'action_financing_reviewed_by_daf' => 'Financement examiné par la DAF',
            'action_financing_reviewed_by_dg' => 'Financement décidé par la Direction générale',

            'action_comment_added' => 'Commentaire ajouté',

            default => ucfirst(str_replace('_', ' ', (string) $type)),
        };
    }

    /**
     * Libelle lisible d'un role destinataire (evite d'exposer `chef_unite_sciq`,
     * `dg`, `direction`... tels quels dans l'interface).
     */
    public static function roleAudience(?string $role): string
    {
        return match ((string) $role) {
            'dg' => 'Direction générale',
            'direction' => 'Direction concernée',
            'service' => 'Chef de service',
            'agent' => 'Agent / RMO',
            'planification' => 'Planification',
            'chef_planification' => 'Chef de la planification',
            'sciq' => 'SCIQ',
            'chef_unite_sciq' => 'Chef d’unité SCIQ',
            'sciq_suivi_global' => 'SCIQ — suivi global',
            'cabinet' => 'Cabinet',
            'admin' => 'Administrateur',
            'super_admin' => 'Super administrateur',
            'auditeur' => 'Auditeur',
            default => ucfirst(str_replace('_', ' ', (string) $role)),
        };
    }

    /**
     * Libelle lisible d'un niveau d'alerte.
     */
    public static function alertLevel(?string $level): string
    {
        return match ((string) $level) {
            'info' => 'Information',
            'warning' => 'Vigilance',
            'critical', 'critique' => 'Critique',
            'urgence' => 'Urgence',
            // Niveaux de risque saisis sur les actions.
            'faible' => 'Faible',
            'modere', 'modéré' => 'Modéré',
            'eleve', 'élevé' => 'Élevé',
            'majeur' => 'Majeur',
            default => ucfirst(str_replace('_', ' ', (string) $level)),
        };
    }
}
