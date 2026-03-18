<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pao_objectifs_operationnels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pao_objectif_strategique_id')
                ->constrained('pao_objectifs_strategiques')
                ->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('libelle');

            // Champs metier demandes
            $table->text('description_action_detaillee');
            $table->foreignId('responsable_id')->constrained('users')->restrictOnDelete();
            $table->decimal('cible_pourcentage', 5, 2)->default(0);
            $table->date('date_debut');
            $table->date('date_fin');
            $table->enum('statut_realisation', ['non_demarre', 'en_cours', 'en_retard', 'bloque', 'termine', 'annule'])
                ->default('non_demarre');
            $table->text('ressources_requises')->nullable();
            $table->string('indicateur_performance');
            $table->text('risques_potentiels')->nullable();

            // Champs utiles complementaires
            $table->date('echeance')->nullable();
            $table->enum('priorite', ['basse', 'moyenne', 'haute', 'critique'])->default('moyenne');
            $table->unsignedTinyInteger('progression_pourcentage')->default(0);
            $table->date('date_realisation')->nullable();
            $table->text('livrable_attendu')->nullable();
            $table->text('contraintes')->nullable();
            $table->text('dependances')->nullable();
            $table->text('observations')->nullable();
            $table->unsignedInteger('ordre')->default(1);
            $table->timestamps();

            $table->unique(
                ['pao_objectif_strategique_id', 'code'],
                'pao_obj_op_obj_strat_code_unique'
            );
            $table->index(
                ['responsable_id', 'statut_realisation'],
                'pao_obj_op_responsable_statut_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pao_objectifs_operationnels');
    }
};
