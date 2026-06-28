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
        Schema::create('ai_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 80)->index();
            $table->string('rating', 40)->nullable()->index();
            $table->longText('ai_output')->nullable();
            $table->longText('human_correction')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['module', 'rating']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_feedbacks');
    }
};
