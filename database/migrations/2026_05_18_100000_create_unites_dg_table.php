<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unites_dg', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direction_id')->constrained('directions')->cascadeOnDelete();
            $table->string('code', 30)->unique();
            $table->string('libelle');
            $table->foreignId('chef_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Portée fonctionnelle de l'unité : true = vue globale agence (SCIQ, DGA, Cabinet) ; false = limitée à l'unité (UCAS).
            $table->boolean('portee_globale')->default(false);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['direction_id', 'actif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unites_dg');
    }
};
