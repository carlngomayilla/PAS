<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pas', function (Blueprint $table): void {
            $table->id();
            $table->string('titre');
            $table->unsignedSmallInteger('periode_debut');
            $table->unsignedSmallInteger('periode_fin');
            $table->enum('statut', ['brouillon', 'soumis', 'valide', 'verrouille'])->default('brouillon');
            $table->timestamp('valide_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas');
    }
};

