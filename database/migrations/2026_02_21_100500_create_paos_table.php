<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pas_id')->constrained('pas')->restrictOnDelete();
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->unsignedSmallInteger('annee');
            $table->string('titre');
            $table->text('objectif_operationnel')->nullable();
            $table->text('resultats_attendus')->nullable();
            $table->text('indicateurs_associes')->nullable();
            $table->enum('statut', ['brouillon', 'soumis', 'valide', 'verrouille'])->default('brouillon');
            $table->timestamp('valide_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pas_id', 'annee', 'direction_id'], 'paos_pas_annee_direction_unique');
            $table->unique(['id', 'direction_id'], 'paos_id_direction_unique');
            $table->index(['direction_id', 'annee'], 'paos_direction_annee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paos');
    }
};

