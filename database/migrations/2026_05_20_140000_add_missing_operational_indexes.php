<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A05 — Ajoute les index simples manquants sur des colonnes intensivement
 * filtrees a l execution :
 *   - justificatifs.categorie     : filtres "financement", "execution", ...
 *   - actions.date_echeance       : alertes echeance proche / actions en retard
 *   - kpis.est_a_renseigner       : tableaux KPI a saisir
 *
 * Les index FK / morphs et les composites existants (statut_dynamique+date_fin,
 * financement_statut, entite_type+entite_id, justifiable_*) sont deja crees par
 * leurs migrations d origine et NE sont PAS redupliques ici.
 *
 * Idempotence : on encapsule chaque addIndex dans un try/catch silencieux pour
 * tolerer un environnement deja partiellement indexe a la main.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->safeAddIndex(
            table: 'justificatifs',
            columns: 'categorie',
            indexName: 'justificatifs_categorie_index'
        );

        $this->safeAddIndex(
            table: 'actions',
            columns: 'date_echeance',
            indexName: 'actions_date_echeance_index'
        );

        $this->safeAddIndex(
            table: 'kpis',
            columns: 'est_a_renseigner',
            indexName: 'kpis_est_a_renseigner_index'
        );
    }

    public function down(): void
    {
        $this->safeDropIndex('kpis', 'kpis_est_a_renseigner_index');
        $this->safeDropIndex('actions', 'actions_date_echeance_index');
        $this->safeDropIndex('justificatifs', 'justificatifs_categorie_index');
    }

    /**
     * @param  string|array<int, string>  $columns
     */
    private function safeAddIndex(string $table, string|array $columns, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $columnList = (array) $columns;
        foreach ($columnList as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columnList, $indexName): void {
                $blueprint->index($columnList, $indexName);
            });
        } catch (\Throwable) {
            // Index probablement deja present : on ignore silencieusement.
        }
    }

    private function safeDropIndex(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->dropIndex($indexName);
            });
        } catch (\Throwable) {
            // Index inexistant ou deja supprime : non bloquant.
        }
    }
};
