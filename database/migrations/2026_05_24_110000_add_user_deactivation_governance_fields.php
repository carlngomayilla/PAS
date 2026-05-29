<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable()->index();
            }

            if (! Schema::hasColumn('users', 'deactivated_by')) {
                $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'deactivation_reason')) {
                $table->text('deactivation_reason')->nullable();
            }

            if (! Schema::hasColumn('users', 'tasks_transferred_to')) {
                $table->foreignId('tasks_transferred_to')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'tasks_transferred_to')) {
                $table->dropConstrainedForeignId('tasks_transferred_to');
            }

            if (Schema::hasColumn('users', 'deactivated_by')) {
                $table->dropConstrainedForeignId('deactivated_by');
            }

            foreach (['deactivated_at', 'deactivation_reason'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
