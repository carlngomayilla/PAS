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
        Schema::table('journal_audit', function (Blueprint $table) {
            $table->index(['created_at', 'id'], 'journal_audit_created_id_index');
            $table->index(['user_id', 'created_at'], 'journal_audit_user_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_audit', function (Blueprint $table) {
            $table->dropIndex('journal_audit_created_id_index');
            $table->dropIndex('journal_audit_user_created_index');
        });
    }
};
