<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meeting_reports') || ! Schema::hasColumn('meeting_reports', 'is_encrypted')) {
            return;
        }

        DB::table('meeting_reports')
            ->whereRaw('LOWER(file_path) LIKE ?', ['%.enc'])
            ->update(['is_encrypted' => true]);
    }

    public function down(): void
    {
        // La classification de chiffrement est une donnée de sécurité : elle ne doit pas être effacée au rollback.
    }
};
