<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pao_axes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pao_id')->constrained('paos')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->unsignedInteger('ordre')->default(1);
            $table->timestamps();

            $table->unique(['pao_id', 'code'], 'pao_axes_pao_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pao_axes');
    }
};

