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
        Schema::create('ai_generated_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report_type')->index();
            $table->string('title');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('filters')->nullable();
            $table->json('metrics_snapshot')->nullable();
            $table->longText('ai_draft')->nullable();
            $table->longText('validated_content')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->string('exported_pdf_path')->nullable();
            $table->string('exported_docx_path')->nullable();
            $table->string('exported_xlsx_path')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_generated_reports');
    }
};
