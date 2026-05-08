<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_weeks', function (Blueprint $table): void {
            if (! Schema::hasColumn('action_weeks', 'libelle_sous_action')) {
                $table->string('libelle_sous_action')->nullable()->after('numero_semaine');
            }

            if (! Schema::hasColumn('action_weeks', 'resultat_attendu')) {
                $table->text('resultat_attendu')->nullable()->after('taches_realisees');
            }

            if (! Schema::hasColumn('action_weeks', 'est_creee_par_agent')) {
                $table->boolean('est_creee_par_agent')->default(false)->after('est_renseignee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('action_weeks', function (Blueprint $table): void {
            if (Schema::hasColumn('action_weeks', 'libelle_sous_action')) {
                $table->dropColumn('libelle_sous_action');
            }

            if (Schema::hasColumn('action_weeks', 'resultat_attendu')) {
                $table->dropColumn('resultat_attendu');
            }

            if (Schema::hasColumn('action_weeks', 'est_creee_par_agent')) {
                $table->dropColumn('est_creee_par_agent');
            }
        });
    }
};
