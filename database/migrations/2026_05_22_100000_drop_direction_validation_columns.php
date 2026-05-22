<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purge l'etape de validation direction du modele de donnees.
 *
 *  1. Backfill : `validee_direction` -> `validee_chef`,
 *                `rejetee_direction` -> `rejetee_chef`.
 *  2. Suppression des colonnes `direction_valide_par`, `direction_valide_le`,
 *     `direction_evaluation_note`, `direction_evaluation_commentaire`.
 *  3. Reduction de l'enum `statut_validation` aux valeurs encore en usage.
 *
 *  /!\ Migration destructrice. L'historique de la note et du commentaire de
 *  validation direction est definitivement perdu. C'est volontaire :
 *  l'utilisateur a explicitement demande la suppression complete de l'etape.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Backfill du statut_validation pour les actions deja validees /
        //    rejetees par la direction. On ramene tout au niveau chef.
        DB::table('actions')
            ->where('statut_validation', 'validee_direction')
            ->update(['statut_validation' => 'validee_chef']);

        DB::table('actions')
            ->where('statut_validation', 'rejetee_direction')
            ->update(['statut_validation' => 'rejetee_chef']);

        // 2. Suppression des colonnes direction_*. La FK direction_valide_par
        //    doit etre detachee avant le drop de la colonne.
        Schema::table('actions', function (Blueprint $table): void {
            // Sur PostgreSQL, dropConstrainedForeignId nettoie aussi la contrainte.
            // Sur SQLite (dev), c'est un no-op equivalent.
            if (Schema::hasColumn('actions', 'direction_valide_par')) {
                try {
                    $table->dropConstrainedForeignId('direction_valide_par');
                } catch (\Throwable) {
                    $table->dropColumn('direction_valide_par');
                }
            }
            $table->dropColumn(array_values(array_filter([
                Schema::hasColumn('actions', 'direction_valide_le') ? 'direction_valide_le' : null,
                Schema::hasColumn('actions', 'direction_evaluation_note') ? 'direction_evaluation_note' : null,
                Schema::hasColumn('actions', 'direction_evaluation_commentaire') ? 'direction_evaluation_commentaire' : null,
            ])));
        });

        // 3. Reduction de l'enum statut_validation.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // On lit la contrainte CHECK existante et on la remplace par une
            // version sans les valeurs direction.
            DB::statement(<<<'SQL'
                ALTER TABLE actions
                DROP CONSTRAINT IF EXISTS actions_statut_validation_check
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE actions
                ADD CONSTRAINT actions_statut_validation_check
                CHECK (statut_validation IN (
                    'non_soumise',
                    'soumise_chef',
                    'rejetee_chef',
                    'correction_demandee',
                    'validee_chef'
                ))
            SQL);
        }
        // SQLite n'utilise pas de contrainte CHECK ENUM stricte : aucune action.
        // MySQL : si ENUM stricte ajoutee plus tard, prevoir un ALTER MODIFY.
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->foreignId('direction_valide_par')
                ->nullable()
                ->after('evaluation_commentaire')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('direction_valide_le')->nullable()->after('direction_valide_par');
            $table->decimal('direction_evaluation_note', 5, 2)->nullable()->after('direction_valide_le');
            $table->text('direction_evaluation_commentaire')->nullable()->after('direction_evaluation_note');
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE actions
                DROP CONSTRAINT IF EXISTS actions_statut_validation_check
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE actions
                ADD CONSTRAINT actions_statut_validation_check
                CHECK (statut_validation IN (
                    'non_soumise',
                    'soumise_chef',
                    'rejetee_chef',
                    'correction_demandee',
                    'validee_chef',
                    'rejetee_direction',
                    'validee_direction'
                ))
            SQL);
        }
    }
};
