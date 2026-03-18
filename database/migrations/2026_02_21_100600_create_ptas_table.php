<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('pao_id');
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->unsignedBigInteger('service_id');
            $table->string('titre');
            $table->text('description')->nullable();
            $table->enum('statut', ['brouillon', 'soumis', 'valide', 'verrouille'])->default('brouillon');
            $table->timestamp('valide_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pao_id', 'service_id'], 'ptas_pao_service_unique');
            $table->index(['pao_id', 'direction_id'], 'ptas_pao_direction_index');
            $table->index(['service_id', 'direction_id'], 'ptas_service_direction_index');

            $table->foreign(['pao_id', 'direction_id'], 'ptas_pao_direction_fk')
                ->references(['id', 'direction_id'])
                ->on('paos')
                ->restrictOnDelete();

            $table->foreign(['service_id', 'direction_id'], 'ptas_service_direction_fk')
                ->references(['id', 'direction_id'])
                ->on('services')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptas');
    }
};

