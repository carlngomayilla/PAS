<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('export_template_id')->constrained('export_templates')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 20)->default('draft');
            $table->text('note')->nullable();
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['export_template_id', 'version_number'], 'export_template_versions_unique_version');
            $table->index(['status', 'published_at'], 'export_template_versions_status_published_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_template_versions');
    }
};
