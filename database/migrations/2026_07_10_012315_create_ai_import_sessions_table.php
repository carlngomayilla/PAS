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
        Schema::create('ai_import_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name');
            $table->string('original_file_path');
            $table->string('generated_excel_path')->nullable();
            $table->string('file_type', 30)->index();
            $table->string('document_type', 30)->nullable()->index();
            $table->string('status', 40)->default('uploaded')->index();
            $table->unsignedInteger('total_rows_detected')->default(0);
            $table->unsignedInteger('total_rows_validated')->default(0);
            $table->unsignedInteger('total_errors')->default(0);
            $table->string('model_used')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('total_cost_usd', 12, 6)->default(0);
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_import_sessions');
    }
};
