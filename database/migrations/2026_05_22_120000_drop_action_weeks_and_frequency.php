<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression du suivi hebdomadaire des actions.
 *
 *  - Suppression de la table `action_weeks` et de toutes les FK qui y
 *    pointent (justificatifs.action_week_id notamment).
 *  - Suppression de la colonne `actions.frequence_execution` (qui ne
 *    servait qu'a generer les semaines).
 *  - Les justificatifs sont conserves, on nullify simplement leur lien
 *    vers la semaine avant de retirer la FK.
 *
 *  /!\ Migration destructrice. L'historique des saisies hebdomadaires est
 *  definitivement perdu. Conserve les justificatifs eux-memes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Detacher d'abord la FK justificatifs.action_week_id si elle existe.
        if (Schema::hasColumn('justificatifs', 'action_week_id')) {
            Schema::table('justificatifs', function (Blueprint $table): void {
                try {
                    $table->dropConstrainedForeignId('action_week_id');
                } catch (\Throwable) {
                    $table->dropColumn('action_week_id');
                }
            });
        }

        // 2. Detacher la FK action_logs.action_week_id si elle existe.
        if (Schema::hasColumn('action_logs', 'action_week_id')) {
            Schema::table('action_logs', function (Blueprint $table): void {
                try {
                    $table->dropConstrainedForeignId('action_week_id');
                } catch (\Throwable) {
                    $table->dropColumn('action_week_id');
                }
            });
        }

        // 3. Drop la table action_weeks elle-meme.
        Schema::dropIfExists('action_weeks');

        // 4. Retirer la colonne actions.frequence_execution (devenue inutile).
        if (Schema::hasColumn('actions', 'frequence_execution')) {
            Schema::table('actions', function (Blueprint $table): void {
                $table->dropColumn('frequence_execution');
            });
        }
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->string('frequence_execution', 32)->nullable()->default('hebdomadaire');
        });

        Schema::create('action_weeks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
            $table->unsignedInteger('numero_semaine');
            $table->date('date_debut');
            $table->date('date_fin');
            $table->boolean('est_renseignee')->default(false);
            $table->text('commentaire')->nullable();
            $table->text('difficultes')->nullable();
            $table->text('mesures_correctives')->nullable();
            $table->text('taches_realisees')->nullable();
            $table->decimal('quantite_realisee', 15, 4)->nullable();
            $table->decimal('avancement_estime', 5, 2)->nullable();
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('saisi_le')->nullable();
            $table->timestamps();
            $table->unique(['action_id', 'numero_semaine'], 'action_weeks_action_numero_unique');
        });

        Schema::table('justificatifs', function (Blueprint $table): void {
            $table->foreignId('action_week_id')->nullable()->after('action_id')->constrained('action_weeks')->nullOnDelete();
        });

        Schema::table('action_logs', function (Blueprint $table): void {
            $table->foreignId('action_week_id')->nullable()->after('action_id')->constrained('action_weeks')->nullOnDelete();
        });
    }
};
