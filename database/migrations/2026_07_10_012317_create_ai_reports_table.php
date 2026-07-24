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
        Schema::create('ai_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_report_request_id')->constrained('ai_report_requests')->cascadeOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('report_html')->nullable();
            $table->json('report_json')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('word_path')->nullable();
            $table->string('excel_path')->nullable();
            $table->timestamp('generated_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_reports');
    }
};
