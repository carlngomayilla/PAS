<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'envoi email via Brevo (canal email complémentaire).
 *
 * Règle métier v1.1 :
 *   - Toute notification importante envoyée par email via Brevo doit être journalisée.
 *   - Un échec Brevo ne bloque JAMAIS l'action métier (workflow préservé).
 *   - Statuts : queued | sent | failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brevo_email_log')) {
            return;
        }

        Schema::create('brevo_email_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('recipient_email');
            $table->string('subject', 255);
            $table->string('related_module', 64)->nullable();
            $table->string('related_entity_type', 64)->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->string('cta_url', 1024)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('queued'); // queued | sent | failed
            $table->string('brevo_message_id', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'status'], 'brevo_email_log_event_status_idx');
            $table->index(['user_id', 'created_at'], 'brevo_email_log_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brevo_email_log');
    }
};
