<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->text('resultat_cloture')->nullable()->after('date_fin_reelle');
            $table->text('difficultes_rencontrees')->nullable()->after('resultat_cloture');
            $table->text('mesures_correctives')->nullable()->after('difficultes_rencontrees');
            $table->text('justification_cloture')->nullable()->after('mesures_correctives');
            $table->foreignId('cloture_par')->nullable()->constrained('users')->nullOnDelete()->after('justification_cloture');
            $table->timestamp('cloture_le')->nullable()->after('cloture_par');
            $table->decimal('taux_performance', 5, 2)->nullable()->after('progression_reelle');
            $table->decimal('taux_conformite', 5, 2)->nullable()->after('taux_performance');
            $table->decimal('taux_delai', 5, 2)->nullable()->after('taux_conformite');
            $table->decimal('taux_realisation_global', 5, 2)->nullable()->after('taux_delai');
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->dropForeign(['cloture_par']);
            $table->dropColumn([
                'resultat_cloture', 'difficultes_rencontrees', 'mesures_correctives',
                'justification_cloture', 'cloture_par', 'cloture_le',
                'taux_performance', 'taux_conformite', 'taux_delai', 'taux_realisation_global',
            ]);
        });
    }
};
