<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_audit', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module', 50);
            $table->string('entite_type', 100);
            $table->unsignedBigInteger('entite_id');
            $table->string('action', 50);
            $table->json('ancienne_valeur')->nullable();
            $table->json('nouvelle_valeur')->nullable();
            $table->string('adresse_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['entite_type', 'entite_id'], 'journal_audit_entite_index');
            $table->index(['module', 'action'], 'journal_audit_module_action_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_audit');
    }
};

