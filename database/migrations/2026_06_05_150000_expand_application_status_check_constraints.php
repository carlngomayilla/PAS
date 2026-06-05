<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statuts applicatifs autorises par les workflows PAS ANBG.
     *
     * PostgreSQL cree des CHECK constraints sur les colonnes enum/string. Une
     * liste trop courte bloque la prod des qu une ligne porte un statut legitime
     * du workflow (ex: non_demarre, a_corriger, acheve_hors_delai). Cette
     * migration remplace les contraintes de statuts par des listes completes et
     * normalise seulement les valeurs inconnues vers un fallback sur.
     */
    public function up(): void
    {
        if (! $this->isPgsql()) {
            return;
        }

        foreach ($this->checks() as $check) {
            $this->replaceCheck(
                $check['table'],
                $check['column'],
                $check['constraint'],
                $check['allowed'],
                $check['fallback'] ?? null,
                $check['default'] ?? null,
            );
        }
    }

    public function down(): void
    {
        if (! $this->isPgsql()) {
            return;
        }

        foreach ($this->checks() as $check) {
            $this->dropCheck($check['table'], $check['constraint']);
        }
    }

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * @return list<array{table:string,column:string,constraint:string,allowed:list<string>,fallback?:string,default?:string}>
     */
    private function checks(): array
    {
        $planningPas = ['brouillon', 'soumis', 'actif', 'en_cours', 'valide', 'cloture', 'archive', 'verrouille'];
        $planningPao = ['brouillon', 'soumis', 'actif', 'en_cours', 'valide', 'cloture', 'archive', 'verrouille', 'fin'];
        $planningPta = ['brouillon', 'soumis', 'actif', 'en_cours', 'valide', 'cloture', 'archive', 'verrouille', 'fin'];

        $actionLifecycle = [
            'brouillon',
            'soumis',
            'valide',
            'verrouille',
            'non_demarre',
            'en_cours',
            'realisee',
            'en_attente_validation',
            'en_attente_validation_chef',
            'validee_chef',
            'en_attente_directeur',
            'validee_direction',
            'a_risque',
            'en_avance',
            'en_retard',
            'bloquee',
            'suspendu',
            'annule',
            'annulee',
            'termine',
            'terminee',
            'acheve',
            'achevee',
            'acheve_dans_delai',
            'acheve_hors_delai',
            'a_corriger',
            'rejetee_a_corriger',
            'cloturee',
            'cloture',
            'archive',
        ];

        $actionDynamic = [
            'non_demarre',
            'en_cours',
            'a_risque',
            'en_avance',
            'en_retard',
            'suspendu',
            'annule',
            'acheve_dans_delai',
            'acheve_hors_delai',
            'a_corriger',
            'cloturee',
            'termine',
            'acheve',
        ];

        $actionValidation = [
            'non_soumise',
            'soumise',
            'soumise_chef',
            'rejetee',
            'rejetee_chef',
            'correction_demandee',
            'validee',
            'validee_chef',
            'rejetee_direction',
            'validee_direction',
        ];

        $financing = [
            'non_requis',
            'pre_signale_daf',
            'en_attente_validation_chef',
            'soumis_daf',
            'complement_demande',
            'valide_daf',
            'rejete_daf',
            'transmis_dg',
            'valide_dg',
            'rejete_dg',
            'en_attente_daf',
            'en_cours_analyse',
            'approuve',
            'rejete',
            'finance',
            'non_finance',
            'a_traiter_daf',
            'accorde_dg',
            'refuse_dg',
        ];

        $performance = [
            'non_evaluee',
            'non_demarre',
            'critique',
            'en_alerte',
            'sous_seuil',
            'acceptable',
            'cible_atteinte',
            'cible_depassee',
            'satisfaisante',
            'excellente',
            'rejetee',
            'en_attente_validation',
            'validee',
        ];

        $quantitativeExecution = [
            'non_demarre',
            'faible_avancement',
            'en_progression',
            'presque_atteinte',
            'cible_atteinte',
            'cible_depassee',
        ];

        $subActionStatus = [
            'a_faire',
            'non_demarre',
            'en_cours',
            'effectuee',
            'terminee',
            'termine',
            'soumise',
            'en_attente_validation',
            'en_attente_validation_chef',
            'validee',
            'validee_chef',
            'rejetee',
            'rejetee_a_corriger',
            'a_corriger',
            'cloturee',
        ];

        $exportStatus = ['draft', 'published', 'archived'];
        $requestStatusFr = ['soumise', 'transmise', 'en_analyse', 'complement_demande', 'transmise_dg', 'approuvee', 'rejetee', 'mise_a_jour_appliquee'];

        return [
            ['table' => 'pas', 'column' => 'statut', 'constraint' => 'pas_statut_check', 'allowed' => $planningPas, 'fallback' => 'actif', 'default' => 'actif'],
            ['table' => 'paos', 'column' => 'statut', 'constraint' => 'paos_statut_check', 'allowed' => $planningPao, 'fallback' => 'en_cours', 'default' => 'en_cours'],
            ['table' => 'ptas', 'column' => 'statut', 'constraint' => 'ptas_statut_check', 'allowed' => $planningPta, 'fallback' => 'en_cours', 'default' => 'en_cours'],

            ['table' => 'actions', 'column' => 'statut', 'constraint' => 'actions_statut_check', 'allowed' => $actionLifecycle, 'fallback' => 'non_demarre', 'default' => 'non_demarre'],
            ['table' => 'actions', 'column' => 'statut_dynamique', 'constraint' => 'actions_statut_dynamique_check', 'allowed' => $actionDynamic, 'fallback' => 'non_demarre', 'default' => 'non_demarre'],
            ['table' => 'actions', 'column' => 'statut_validation', 'constraint' => 'actions_statut_validation_check', 'allowed' => $actionValidation, 'fallback' => 'non_soumise', 'default' => 'non_soumise'],
            ['table' => 'actions', 'column' => 'statut_parametrage', 'constraint' => 'actions_statut_parametrage_check', 'allowed' => ['a_parametrer', 'parametre'], 'fallback' => 'parametre', 'default' => 'parametre'],
            ['table' => 'actions', 'column' => 'financement_statut', 'constraint' => 'actions_financement_statut_check', 'allowed' => $financing, 'fallback' => 'non_requis', 'default' => 'non_requis'],
            ['table' => 'actions', 'column' => 'statut_performance', 'constraint' => 'actions_statut_performance_check', 'allowed' => $performance, 'fallback' => 'non_evaluee', 'default' => 'non_evaluee'],
            ['table' => 'actions', 'column' => 'statut_execution_quantitative', 'constraint' => 'actions_statut_execution_quantitative_check', 'allowed' => $quantitativeExecution, 'fallback' => 'non_demarre', 'default' => 'non_demarre'],

            ['table' => 'action_kpis', 'column' => 'statut_calcule', 'constraint' => 'action_kpis_statut_calcule_check', 'allowed' => $actionDynamic, 'fallback' => 'non_demarre', 'default' => 'non_demarre'],

            ['table' => 'sous_actions', 'column' => 'statut', 'constraint' => 'sous_actions_statut_check', 'allowed' => $subActionStatus, 'fallback' => 'non_demarre', 'default' => 'non_demarre'],
            ['table' => 'sous_actions', 'column' => 'validation_status', 'constraint' => 'sous_actions_validation_status_check', 'allowed' => ['non_soumise', 'soumise', 'validee', 'rejetee'], 'fallback' => 'non_soumise', 'default' => 'non_soumise'],
            ['table' => 'sous_actions', 'column' => 'sub_action_type', 'constraint' => 'sous_actions_sub_action_type_check', 'allowed' => ['quantitative', 'non_quantitative'], 'fallback' => 'non_quantitative'],

            ['table' => 'objectifs_operationnels', 'column' => 'statut', 'constraint' => 'objectifs_operationnels_statut_check', 'allowed' => $planningPao, 'fallback' => 'en_cours', 'default' => 'brouillon'],
            ['table' => 'pao_objectifs_operationnels', 'column' => 'statut_realisation', 'constraint' => 'pao_obj_ops_statut_realisation_check', 'allowed' => ['non_demarre', 'en_cours', 'en_retard', 'bloque', 'termine', 'annule'], 'fallback' => 'non_demarre', 'default' => 'non_demarre'],

            ['table' => 'exercices', 'column' => 'statut', 'constraint' => 'exercices_statut_check', 'allowed' => ['ouvert', 'clos', 'archive'], 'fallback' => 'ouvert', 'default' => 'ouvert'],
            ['table' => 'delegations', 'column' => 'statut', 'constraint' => 'delegations_statut_check', 'allowed' => ['active', 'cancelled', 'expired'], 'fallback' => 'active', 'default' => 'active'],

            ['table' => 'planning_imports', 'column' => 'status', 'constraint' => 'planning_imports_status_check', 'allowed' => ['uploaded', 'mapping_required', 'preview_ready', 'preview_errors', 'imported', 'failed', 'cancelled'], 'fallback' => 'uploaded', 'default' => 'uploaded'],
            ['table' => 'brevo_email_log', 'column' => 'status', 'constraint' => 'brevo_email_log_status_check', 'allowed' => ['queued', 'sent', 'failed'], 'fallback' => 'queued', 'default' => 'queued'],
            ['table' => 'export_templates', 'column' => 'status', 'constraint' => 'export_templates_status_check', 'allowed' => $exportStatus, 'fallback' => 'draft', 'default' => 'draft'],
            ['table' => 'export_template_versions', 'column' => 'status', 'constraint' => 'export_template_versions_status_check', 'allowed' => $exportStatus, 'fallback' => 'draft', 'default' => 'draft'],
            ['table' => 'deletion_requests', 'column' => 'status', 'constraint' => 'deletion_requests_status_check', 'allowed' => ['pending', 'deleted', 'disabled', 'archived', 'rejected', 'complement_requested', 'corrected'], 'fallback' => 'pending', 'default' => 'pending'],
            ['table' => 'deadline_extension_requests', 'column' => 'status', 'constraint' => 'deadline_ext_requests_status_check', 'allowed' => $requestStatusFr, 'fallback' => 'soumise', 'default' => 'soumise'],
            ['table' => 'planning_unlock_requests', 'column' => 'status', 'constraint' => 'planning_unlock_requests_status_check', 'allowed' => $requestStatusFr, 'fallback' => 'soumise', 'default' => 'soumise'],
        ];
    }

    /**
     * @param list<string> $allowed
     */
    private function replaceCheck(string $table, string $column, string $constraint, array $allowed, ?string $fallback = null, ?string $default = null): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $quotedTable = $this->quoteIdentifier($table);
        $quotedColumn = $this->quoteIdentifier($column);
        $quotedConstraint = $this->quoteIdentifier($constraint);

        DB::statement("ALTER TABLE {$quotedTable} DROP CONSTRAINT IF EXISTS {$quotedConstraint}");

        if ($fallback !== null) {
            DB::table($table)
                ->whereNotNull($column)
                ->whereNotIn($column, $allowed)
                ->update([$column => $fallback]);
        }

        if ($default !== null) {
            DB::statement("ALTER TABLE {$quotedTable} ALTER COLUMN {$quotedColumn} SET DEFAULT ".$this->quoteLiteral($default));
        }

        DB::statement(
            "ALTER TABLE {$quotedTable} ADD CONSTRAINT {$quotedConstraint} "
            ."CHECK ({$quotedColumn} IS NULL OR {$quotedColumn} IN (".$this->literalList($allowed).'))'
        );
    }

    private function dropCheck(string $table, string $constraint): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::statement(
            'ALTER TABLE '.$this->quoteIdentifier($table)
            .' DROP CONSTRAINT IF EXISTS '.$this->quoteIdentifier($constraint)
        );
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @param list<string> $values
     */
    private function literalList(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => $this->quoteLiteral($value), $values));
    }
};
