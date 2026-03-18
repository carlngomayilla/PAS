<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_justificatifs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
            $table->foreignId('action_week_id')->nullable()->constrained('action_weeks')->nullOnDelete();
            $table->enum('categorie', ['financement', 'hebdomadaire', 'final'])->default('hebdomadaire');
            $table->string('nom_original');
            $table->string('chemin_stockage');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('taille_octets')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('ajoute_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['action_id', 'categorie'], 'action_justif_action_categorie_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_justificatifs');
    }
};

