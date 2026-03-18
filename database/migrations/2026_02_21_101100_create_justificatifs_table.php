<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('justificatifs', function (Blueprint $table): void {
            $table->id();
            $table->morphs('justifiable');
            $table->string('nom_original');
            $table->string('chemin_stockage');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('taille_octets')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('ajoute_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('justificatifs');
    }
};

