<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('attachment_original_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime_type')->nullable()->after('attachment_original_name');
            $table->unsignedBigInteger('attachment_size_bytes')->nullable()->after('attachment_mime_type');
            $table->boolean('attachment_is_encrypted')->default(false)->after('attachment_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn([
                'attachment_original_name',
                'attachment_mime_type',
                'attachment_size_bytes',
                'attachment_is_encrypted',
            ]);
        });
    }
};
