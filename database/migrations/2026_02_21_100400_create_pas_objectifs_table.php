<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pas_objectifs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pas_axe_id')->constrained('pas_axes')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->string('indicateur_global')->nullable();
            $table->string('valeur_cible')->nullable();
            $table->timestamps();

            $table->unique(['pas_axe_id', 'code'], 'pas_objectifs_axe_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_objectifs');
    }
};

