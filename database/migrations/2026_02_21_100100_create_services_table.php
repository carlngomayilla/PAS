<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('libelle');
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['direction_id', 'code'], 'services_direction_code_unique');
            $table->unique(['id', 'direction_id'], 'services_id_direction_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};

