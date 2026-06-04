<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A22 + A24 + A30 — Renforcement de l integrite referentielle et des contraintes
 * metier sur les enums "varchar" et la consistance des delegations.
 *
 * Specifique PostgreSQL : les CHECK constraints sont posees uniquement sur la
 * connexion `pgsql`. SQLite ne supporte qu un sous-ensemble des CHECK
 * d ALTER TABLE et nos tests in-memory ne valident pas ces contraintes.
 *
 * Les FK structurelles (ptas.pao_id, ptas.service_id) sont ajoutees
 * indifferemment du driver — SQLite peut les enforcer si pragma foreign_keys=on.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── A22 — FK manquantes sur ptas ──────────────────────────────────
        // Les colonnes ptas.pao_id et ptas.service_id etaient declarees en
        // unsignedBigInteger nu (cf. migration 2026_02_21_100600). On ajoute
        // les FKs avec restrictOnDelete pour bloquer la suppression d un PAO
        // ou d un Service qui porte encore des PTAs.
        $this->ensureForeignKey('ptas', 'pao_id', 'paos', 'restrict');
        $this->ensureForeignKey('ptas', 'service_id', 'services', 'restrict');

        if (! $this->isPgsql()) {
            return;
        }

        // ─── A24 — CHECK constraints PG sur les enums "varchar" ──────────
        // NB : ces contraintes sont desormais creees directement par les
        // migrations de creation de tables. addCheckIfMissing() detecte leur
        // presence et ne les rajoute pas (cf. correctif transaction PG ci-dessous).
        $this->addCheckIfMissing(
            'pas',
            'pas_statut_check',
            "statut IN ('brouillon','soumis','valide','verrouille')"
        );

        $this->addCheckIfMissing(
            'paos',
            'paos_statut_check',
            "statut IN ('brouillon','soumis','valide','verrouille')"
        );

        $this->addCheckIfMissing(
            'ptas',
            'ptas_statut_check',
            "statut IN ('brouillon','soumis','valide','verrouille')"
        );

        if (Schema::hasColumn('actions', 'contexte_action')) {
            $this->addCheckIfMissing(
                'actions',
                'actions_contexte_action_check',
                "contexte_action IN ('pilotage','operationnel')"
            );
        }

        if (Schema::hasColumn('actions', 'statut_validation')) {
            $this->addCheckIfMissing(
                'actions',
                'actions_statut_validation_check',
                "statut_validation IS NULL OR statut_validation IN ('non_soumise','soumise_chef','rejetee_chef','validee_chef','rejetee_direction','validee_direction')"
            );
        }

        if (Schema::hasColumn('delegations', 'role_scope')) {
            $this->addCheckIfMissing(
                'delegations',
                'delegations_role_scope_check',
                "role_scope IN ('direction','service')"
            );

            // ─── A30 — Consistance role_scope vs direction_id / service_id ──
            // role_scope = 'service'   → service_id NOT NULL AND direction_id NOT NULL
            // role_scope = 'direction' → direction_id NOT NULL
            $this->addCheckIfMissing(
                'delegations',
                'delegations_scope_consistency_check',
                "(role_scope = 'service' AND service_id IS NOT NULL AND direction_id IS NOT NULL) "
                ."OR (role_scope = 'direction' AND direction_id IS NOT NULL)"
            );
        }
    }

    public function down(): void
    {
        if ($this->isPgsql()) {
            $this->dropCheckIfExists('delegations', 'delegations_scope_consistency_check');
            $this->dropCheckIfExists('delegations', 'delegations_role_scope_check');
            $this->dropCheckIfExists('actions', 'actions_statut_validation_check');
            $this->dropCheckIfExists('actions', 'actions_contexte_action_check');
            $this->dropCheckIfExists('ptas', 'ptas_statut_check');
            $this->dropCheckIfExists('paos', 'paos_statut_check');
            $this->dropCheckIfExists('pas', 'pas_statut_check');
        }

        $this->dropForeignKeyIfExists('ptas', 'service_id');
        $this->dropForeignKeyIfExists('ptas', 'pao_id');
    }

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function ensureForeignKey(string $table, string $column, string $references, string $onDelete): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        // Sur PostgreSQL, un ADD CONSTRAINT en echec avorte toute la transaction
        // de migration (SQLSTATE 25P02). On verifie donc l existence de la FK
        // AVANT de l ajouter, au lieu de compter sur le try/catch ci-dessous.
        if ($this->isPgsql() && $this->constraintExists($table, "{$table}_{$column}_foreign")) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $onDelete): void {
                $foreign = $blueprint->foreign($column)->references('id')->on($references);
                if ($onDelete === 'restrict') {
                    $foreign->restrictOnDelete();
                } elseif ($onDelete === 'cascade') {
                    $foreign->cascadeOnDelete();
                } elseif ($onDelete === 'null') {
                    $foreign->nullOnDelete();
                }
            });
        } catch (\Throwable) {
            // FK probablement deja presente (rerun de la migration). On ignore.
        }
    }

    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable) {
            // FK absente : non bloquant.
        }
    }

    private function addCheckIfMissing(string $table, string $constraintName, string $expression): void
    {
        // Idempotent et sans empoisonnement de transaction PG : on verifie
        // l existence de la contrainte avant de l ajouter (un ADD CONSTRAINT
        // en echec avorterait toute la transaction de migration).
        if ($this->constraintExists($table, $constraintName)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraintName} CHECK ({$expression})");
    }

    private function constraintExists(string $table, string $constraintName): bool
    {
        // pg_constraint : appele uniquement sur la connexion pgsql.
        return DB::selectOne(
            'select 1 from pg_constraint where conname = ? and conrelid = ?::regclass',
            [$constraintName, $table]
        ) !== null;
    }

    private function dropCheckIfExists(string $table, string $constraintName): void
    {
        try {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraintName}");
        } catch (\Throwable) {
            // Non bloquant.
        }
    }
};
