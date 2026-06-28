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
        Schema::create('pta_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('ai_import_batches')->cascadeOnDelete();
            $table->string('source_column');
            $table->string('target_field');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->boolean('is_confirmed')->default(false);
            $table->timestamps();

            $table->unique(['batch_id', 'source_column', 'target_field'], 'pta_mapping_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pta_import_mappings');
    }
};
