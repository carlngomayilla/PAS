<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_archives', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('source_table', 80);
            $table->string('scope_label')->nullable();
            $table->string('batch_key', 80)->nullable();
            $table->json('payload');
            $table->timestamp('archived_at');
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['source_table', 'entity_type', 'entity_id'], 'data_archives_entity_lookup_index');
            $table->index(['archived_at', 'source_table'], 'data_archives_archived_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_archives');
    }
};
