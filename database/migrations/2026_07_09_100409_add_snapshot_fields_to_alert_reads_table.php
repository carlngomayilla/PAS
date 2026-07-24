<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_reads', function (Blueprint $table): void {
            $table->string('niveau', 40)->nullable();
            $table->string('titre')->nullable();
            $table->text('message')->nullable();
            $table->text('target_url')->nullable();
            $table->json('metadata')->nullable();

            $table->index(['user_id', 'niveau', 'read_at'], 'alert_reads_user_level_read_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('alert_reads', function (Blueprint $table): void {
            $table->dropIndex('alert_reads_user_level_read_at_index');
            $table->dropColumn([
                'niveau',
                'titre',
                'message',
                'target_url',
                'metadata',
            ]);
        });
    }
};
