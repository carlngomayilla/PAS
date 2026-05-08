<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('actions', 'mode_evaluation')) {
                $table->string('mode_evaluation', 30)
                    ->nullable()
                    ->after('objectif_operationnel_id');
            }

            if (! Schema::hasColumn('actions', 'quantite_realisee')) {
                $table->decimal('quantite_realisee', 15, 4)
                    ->default(0)
                    ->after('quantite_cible');
            }

            if (! Schema::hasColumn('actions', 'priorite')) {
                $table->string('priorite', 30)
                    ->nullable()
                    ->after('date_echeance');
            }

            if (! Schema::hasColumn('actions', 'indicateurs_attendus')) {
                $table->text('indicateurs_attendus')
                    ->nullable()
                    ->after('resultat_attendu');
            }

            if (! Schema::hasColumn('actions', 'observations')) {
                $table->text('observations')
                    ->nullable()
                    ->after('indicateurs_attendus');
            }

            if (! Schema::hasColumn('actions', 'ressources_humaines')) {
                $table->text('ressources_humaines')
                    ->nullable()
                    ->after('ressource_main_oeuvre');
            }

            if (! Schema::hasColumn('actions', 'ressources_materielles')) {
                $table->text('ressources_materielles')
                    ->nullable()
                    ->after('ressource_equipement');
            }

            if (! Schema::hasColumn('actions', 'ressources_techniques')) {
                $table->text('ressources_techniques')
                    ->nullable()
                    ->after('ressource_partenariat');
            }

            if (! Schema::hasColumn('actions', 'ressources_financieres')) {
                $table->text('ressources_financieres')
                    ->nullable()
                    ->after('description_financement');
            }

            if (! Schema::hasColumn('actions', 'observation_financement')) {
                $table->text('observation_financement')
                    ->nullable()
                    ->after('source_financement');
            }

            if (! Schema::hasColumn('actions', 'risque_potentiel')) {
                $table->text('risque_potentiel')
                    ->nullable()
                    ->after('risques');
            }

            if (! Schema::hasColumn('actions', 'niveau_risque')) {
                $table->string('niveau_risque', 50)
                    ->nullable()
                    ->after('risque_potentiel');
            }

            if (! Schema::hasColumn('actions', 'impact_estime')) {
                $table->string('impact_estime', 100)
                    ->nullable()
                    ->after('niveau_risque');
            }

            if (! Schema::hasColumn('actions', 'probabilite')) {
                $table->string('probabilite', 100)
                    ->nullable()
                    ->after('impact_estime');
            }

            if (! Schema::hasColumn('actions', 'responsable_suivi_risque')) {
                $table->string('responsable_suivi_risque')
                    ->nullable()
                    ->after('mesures_correctives');
            }

            if (! Schema::hasColumn('actions', 'avancement_operationnel')) {
                $table->decimal('avancement_operationnel', 7, 2)
                    ->default(0)
                    ->after('progression_reelle');
            }

            if (! Schema::hasColumn('actions', 'taux_atteinte_cible')) {
                $table->decimal('taux_atteinte_cible', 7, 2)
                    ->default(0)
                    ->after('avancement_operationnel');
            }

            if (! Schema::hasColumn('actions', 'taux_global')) {
                $table->decimal('taux_global', 7, 2)
                    ->default(0)
                    ->after('taux_atteinte_cible');
            }
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $columns = [
                'mode_evaluation',
                'quantite_realisee',
                'priorite',
                'indicateurs_attendus',
                'observations',
                'ressources_humaines',
                'ressources_materielles',
                'ressources_techniques',
                'ressources_financieres',
                'observation_financement',
                'risque_potentiel',
                'niveau_risque',
                'impact_estime',
                'probabilite',
                'responsable_suivi_risque',
                'avancement_operationnel',
                'taux_atteinte_cible',
                'taux_global',
            ];

            $existing = array_values(array_filter($columns, static fn (string $column): bool => Schema::hasColumn('actions', $column)));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
