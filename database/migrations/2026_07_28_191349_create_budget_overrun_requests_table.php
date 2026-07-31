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
        Schema::create('budget_overrun_requests', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 20)->index();
            $table->unsignedBigInteger('scope_id')->index();
            $table->decimal('base_budget', 15, 2);
            $table->decimal('requested_extra', 15, 2);
            $table->string('status', 30)->index();
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('daf_director_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('daf_director_reviewed_at')->nullable();
            $table->text('daf_director_note')->nullable();
            $table->foreignId('dg_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dg_decided_at')->nullable();
            $table->text('dg_note')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'status'], 'budget_overrun_scope_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_overrun_requests');
    }
};
