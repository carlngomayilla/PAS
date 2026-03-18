<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pta_id')->constrained('ptas')->cascadeOnDelete();
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->date('date_echeance')->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('statut', ['non_demarre', 'en_cours', 'suspendu', 'termine', 'annule'])->default('non_demarre');
            $table->text('risques')->nullable();
            $table->boolean('financement_requis')->default(false);
            $table->text('description_financement')->nullable();
            $table->string('source_financement')->nullable();
            $table->decimal('montant_estime', 15, 2)->nullable();
            $table->timestamps();

            $table->index(['pta_id', 'statut'], 'actions_pta_statut_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};

