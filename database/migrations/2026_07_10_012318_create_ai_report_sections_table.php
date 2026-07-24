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
        Schema::create('ai_report_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_report_id')->constrained('ai_reports')->cascadeOnDelete();
            $table->string('section_title');
            $table->unsignedInteger('section_order');
            $table->longText('content')->nullable();
            $table->json('indicators_json')->nullable();
            $table->timestamps();

            $table->unique(['ai_report_id', 'section_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_report_sections');
    }
};
