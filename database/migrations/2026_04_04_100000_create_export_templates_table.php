<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('format', 20);
            $table->string('module', 50);
            $table->string('report_type', 80);
            $table->string('target_profile', 30)->nullable();
            $table->string('reading_level', 30)->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('blocks_config')->nullable();
            $table->json('layout_config')->nullable();
            $table->json('content_config')->nullable();
            $table->json('style_config')->nullable();
            $table->json('meta_config')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['module', 'report_type', 'format'], 'export_templates_module_report_format_index');
            $table->index(['status', 'is_active'], 'export_templates_status_active_index');
            $table->index(['target_profile', 'reading_level'], 'export_templates_profile_level_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_templates');
    }
};
