<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delegant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegue_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role_scope', ['direction', 'service']);
            $table->foreignId('direction_id')->nullable()->constrained('directions')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->json('permissions')->nullable();
            $table->text('motif');
            $table->timestamp('date_debut');
            $table->timestamp('date_fin');
            $table->enum('statut', ['active', 'cancelled', 'expired'])->default('active');
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('annule_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('annule_le')->nullable();
            $table->text('motif_annulation')->nullable();
            $table->timestamps();

            $table->index(['delegue_id', 'statut', 'date_debut', 'date_fin'], 'delegations_delegate_scope_index');
            $table->index(['direction_id', 'service_id', 'statut'], 'delegations_target_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
