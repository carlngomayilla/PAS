<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('action_signature_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 40);
            $table->string('actor_name');
            $table->string('actor_role', 100)->nullable();
            $table->string('actor_matricule', 100)->nullable();
            $table->longText('signature_snapshot');
            $table->string('signature_sha256', 64);
            $table->timestamp('source_signature_updated_at')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->index(['action_id', 'stage', 'signed_at'], 'action_signature_receipts_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('action_signature_receipts');
    }
};
