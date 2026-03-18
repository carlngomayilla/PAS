<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_mesures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kpi_id')->constrained('kpis')->cascadeOnDelete();
            $table->string('periode', 20);
            $table->decimal('valeur', 15, 4);
            $table->text('commentaire')->nullable();
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kpi_id', 'periode'], 'kpi_mesures_kpi_periode_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_mesures');
    }
};

