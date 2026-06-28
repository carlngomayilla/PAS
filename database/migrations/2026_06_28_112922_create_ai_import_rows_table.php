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
        Schema::create('ai_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('ai_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_payload')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->json('validation_errors')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamps();

            $table->unique(['batch_id', 'row_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_import_rows');
    }
};
