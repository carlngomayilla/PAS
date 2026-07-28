<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deletion_requests', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->after('reviewed_by')->constrained('users')->nullOnDelete();
            $table->text('approval_note')->nullable()->after('reviewer_note');
            $table->timestamp('approved_at')->nullable()->after('decided_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('deletion_requests', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
            $table->dropIndex(['approved_at']);
            $table->dropColumn(['approved_by', 'approval_note', 'approved_at']);
        });
    }
};
