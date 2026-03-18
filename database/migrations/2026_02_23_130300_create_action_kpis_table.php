<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_kpis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->unique()->constrained('actions')->cascadeOnDelete();
            $table->decimal('kpi_delai', 7, 2)->default(0);
            $table->decimal('kpi_performance', 7, 2)->default(0);
            $table->decimal('kpi_conformite', 7, 2)->default(100);
            $table->decimal('kpi_global', 7, 2)->default(0);
            $table->decimal('progression_reelle', 7, 2)->default(0);
            $table->decimal('progression_theorique', 7, 2)->default(0);
            $table->string('statut_calcule', 30)->default('non_demarre');
            $table->timestamp('derniere_evaluation_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_kpis');
    }
};

