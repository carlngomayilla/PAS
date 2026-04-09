<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_template_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('export_template_id')->constrained('export_templates')->cascadeOnDelete();
            $table->string('module', 50);
            $table->string('report_type', 80);
            $table->string('format', 20);
            $table->string('target_profile', 30)->nullable();
            $table->string('reading_level', 30)->nullable();
            $table->foreignId('direction_id')->nullable()->constrained('directions')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['module', 'report_type', 'format'], 'export_template_assignments_module_report_format_index');
            $table->index(['target_profile', 'reading_level'], 'export_template_assignments_profile_level_index');
            $table->index(['direction_id', 'service_id'], 'export_template_assignments_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_template_assignments');
    }
};
