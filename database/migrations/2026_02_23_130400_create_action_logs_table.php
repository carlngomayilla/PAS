<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
            $table->foreignId('action_week_id')->nullable()->constrained('action_weeks')->nullOnDelete();
            $table->string('niveau', 20)->default('info');
            $table->string('type_evenement', 50);
            $table->text('message');
            $table->json('details')->nullable();
            $table->string('cible_role', 30)->nullable();
            $table->foreignId('utilisateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('lu')->default(false);
            $table->timestamps();

            $table->index(['action_id', 'niveau', 'created_at'], 'action_logs_action_niveau_date_index');
            $table->index(['cible_role', 'lu'], 'action_logs_cible_lu_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }
};

