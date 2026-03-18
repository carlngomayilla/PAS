<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->enum('type_cible', ['quantitative', 'qualitative'])
                ->default('quantitative')
                ->after('description');
            $table->string('unite_cible', 100)->nullable()->after('type_cible');
            $table->decimal('quantite_cible', 15, 4)->nullable()->after('unite_cible');
            $table->text('resultat_attendu')->nullable()->after('quantite_cible');
            $table->text('criteres_validation')->nullable()->after('resultat_attendu');
            $table->text('livrable_attendu')->nullable()->after('criteres_validation');

            $table->boolean('ressource_main_oeuvre')->default(false)->after('financement_requis');
            $table->boolean('ressource_equipement')->default(false)->after('ressource_main_oeuvre');
            $table->boolean('ressource_partenariat')->default(false)->after('ressource_equipement');
            $table->boolean('ressource_autres')->default(false)->after('ressource_partenariat');
            $table->text('ressource_autres_details')->nullable()->after('ressource_autres');

            $table->string('statut_dynamique', 30)->default('non_demarre')->after('statut');
            $table->decimal('progression_reelle', 7, 2)->default(0)->after('statut_dynamique');
            $table->decimal('progression_theorique', 7, 2)->default(0)->after('progression_reelle');
            $table->decimal('seuil_alerte_progression', 7, 2)->default(10)->after('progression_theorique');

            $table->date('date_fin_reelle')->nullable()->after('date_fin');
            $table->text('rapport_final')->nullable()->after('date_fin_reelle');
            $table->boolean('validation_hierarchique')->default(false)->after('rapport_final');
            $table->boolean('validation_sans_correction')->nullable()->after('validation_hierarchique');
            $table->text('mesures_preventives')->nullable()->after('risques');

            $table->index(['statut_dynamique', 'date_fin'], 'actions_statut_dyn_date_fin_index');
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->dropIndex('actions_statut_dyn_date_fin_index');

            $table->dropColumn([
                'type_cible',
                'unite_cible',
                'quantite_cible',
                'resultat_attendu',
                'criteres_validation',
                'livrable_attendu',
                'ressource_main_oeuvre',
                'ressource_equipement',
                'ressource_partenariat',
                'ressource_autres',
                'ressource_autres_details',
                'statut_dynamique',
                'progression_reelle',
                'progression_theorique',
                'seuil_alerte_progression',
                'date_fin_reelle',
                'rapport_final',
                'validation_hierarchique',
                'validation_sans_correction',
                'mesures_preventives',
            ]);
        });
    }
};

