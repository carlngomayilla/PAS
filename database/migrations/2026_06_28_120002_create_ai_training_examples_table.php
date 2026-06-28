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
        Schema::create('ai_training_examples', function (Blueprint $table) {
            $table->id();
            $table->string('task', 80)->index();
            $table->longText('input_text');
            $table->json('expected_json')->nullable();
            $table->longText('expected_text')->nullable();
            $table->string('source', 80)->nullable()->index();
            $table->boolean('is_validated')->default(false)->index();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['task', 'is_validated']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_training_examples');
    }
};
