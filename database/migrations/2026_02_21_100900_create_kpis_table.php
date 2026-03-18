<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
            $table->string('libelle');
            $table->string('unite', 30)->nullable();
            $table->decimal('cible', 15, 4)->nullable();
            $table->decimal('seuil_alerte', 15, 4)->nullable();
            $table->enum('periodicite', ['mensuel', 'trimestriel', 'semestriel', 'annuel', 'ponctuel'])->default('mensuel');
            $table->timestamps();

            $table->index(['action_id', 'periodicite'], 'kpis_action_periodicite_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpis');
    }
};

