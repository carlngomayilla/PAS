<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_weeks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
            $table->unsignedInteger('numero_semaine');
            $table->date('date_debut');
            $table->date('date_fin');

            $table->boolean('est_renseignee')->default(false);
            $table->decimal('quantite_realisee', 15, 4)->nullable();
            $table->decimal('quantite_cumulee', 15, 4)->default(0);
            $table->text('taches_realisees')->nullable();
            $table->decimal('avancement_estime', 7, 2)->nullable();
            $table->text('commentaire')->nullable();
            $table->text('difficultes')->nullable();
            $table->text('mesures_correctives')->nullable();

            $table->decimal('progression_reelle', 7, 2)->default(0);
            $table->decimal('progression_theorique', 7, 2)->default(0);
            $table->decimal('ecart_progression', 7, 2)->default(0);

            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('saisi_le')->nullable();
            $table->timestamps();

            $table->unique(['action_id', 'numero_semaine'], 'action_weeks_action_numero_unique');
            $table->index(['action_id', 'est_renseignee'], 'action_weeks_action_renseignee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_weeks');
    }
};

