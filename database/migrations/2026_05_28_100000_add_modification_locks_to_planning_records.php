<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pas', 'ptas', 'actions'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'modification_locked_at')) {
                    $blueprint->timestamp('modification_locked_at')->nullable()->after('updated_at');
                }
                if (! Schema::hasColumn($table, 'modification_locked_by')) {
                    $blueprint->foreignId('modification_locked_by')->nullable()->after('modification_locked_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn($table, 'modification_unlocked_at')) {
                    $blueprint->timestamp('modification_unlocked_at')->nullable()->after('modification_locked_by');
                }
                if (! Schema::hasColumn($table, 'modification_unlocked_by')) {
                    $blueprint->foreignId('modification_unlocked_by')->nullable()->after('modification_unlocked_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn($table, 'modification_unlock_expires_at')) {
                    $blueprint->timestamp('modification_unlock_expires_at')->nullable()->after('modification_unlocked_by');
                }
                if (! Schema::hasColumn($table, 'modification_unlock_reason')) {
                    $blueprint->text('modification_unlock_reason')->nullable()->after('modification_unlock_expires_at');
                }
            });

            DB::table($table)
                ->whereNull('modification_locked_at')
                ->update([
                    'modification_locked_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'),
                ]);
        }
    }

    public function down(): void
    {
        foreach (['actions', 'ptas', 'pas'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                foreach (['modification_locked_by', 'modification_unlocked_by'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropConstrainedForeignId($column);
                    }
                }

                foreach ([
                    'modification_unlock_reason',
                    'modification_unlock_expires_at',
                    'modification_unlocked_at',
                    'modification_locked_at',
                ] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
};
