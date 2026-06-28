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
        Schema::create('ai_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('file_type', 30);
            $table->string('status', 40)->default('uploaded')->index();
            $table->unsignedSmallInteger('detected_year')->nullable()->index();
            $table->string('detected_direction')->nullable();
            $table->string('detected_service')->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('generated_excel_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_import_batches');
    }
};
