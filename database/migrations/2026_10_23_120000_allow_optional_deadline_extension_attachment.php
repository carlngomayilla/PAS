<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deadline_extension_requests', function (Blueprint $table): void {
            $table->text('justification')->nullable()->change();
            $table->string('attachment_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('deadline_extension_requests', function (Blueprint $table): void {
            $table->text('justification')->nullable(false)->change();
            $table->string('attachment_path')->nullable(false)->change();
        });
    }
};
