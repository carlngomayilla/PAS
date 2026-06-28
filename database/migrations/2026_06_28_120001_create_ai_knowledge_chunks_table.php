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
        Schema::create('ai_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('ai_knowledge_documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->json('embedding')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_chunks');
    }
};
