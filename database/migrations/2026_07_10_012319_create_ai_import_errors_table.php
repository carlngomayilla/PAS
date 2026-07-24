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
        Schema::create('ai_import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_import_session_id')->constrained('ai_import_sessions')->cascadeOnDelete();
            $table->foreignId('ai_import_row_id')->nullable()->constrained('ai_import_rows')->nullOnDelete();
            $table->string('gravity', 30)->index();
            $table->string('field')->nullable()->index();
            $table->text('message');
            $table->text('suggestion')->nullable();
            $table->timestamps();

            $table->index(['ai_import_session_id', 'gravity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_import_errors');
    }
};
