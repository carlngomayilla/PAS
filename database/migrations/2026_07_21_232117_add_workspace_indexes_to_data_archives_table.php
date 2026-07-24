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
        Schema::table('data_archives', function (Blueprint $table): void {
            $table->index(['source_table', 'archived_at'], 'data_archives_source_archived_index');
            $table->index(['batch_key', 'archived_at'], 'data_archives_batch_archived_index');
            $table->index(['archived_by', 'archived_at'], 'data_archives_actor_archived_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_archives', function (Blueprint $table): void {
            $table->dropIndex('data_archives_source_archived_index');
            $table->dropIndex('data_archives_batch_archived_index');
            $table->dropIndex('data_archives_actor_archived_index');
        });
    }
};
