<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pas_axes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pas_id')->constrained('pas')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->unsignedInteger('ordre')->default(1);
            $table->timestamps();

            $table->unique(['pas_id', 'code'], 'pas_axes_pas_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_axes');
    }
};

