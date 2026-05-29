<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_assignment_histories')) {
            return;
        }

        Schema::create('user_assignment_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_role', 100)->nullable();
            $table->string('new_role', 100)->nullable();
            $table->string('previous_custom_role_code', 100)->nullable();
            $table->string('new_custom_role_code', 100)->nullable();
            $table->foreignId('previous_direction_id')->nullable()->constrained('directions')->nullOnDelete();
            $table->foreignId('new_direction_id')->nullable()->constrained('directions')->nullOnDelete();
            $table->foreignId('previous_service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('new_service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('previous_unite_dg_id')->nullable()->constrained('unites_dg')->nullOnDelete();
            $table->foreignId('new_unite_dg_id')->nullable()->constrained('unites_dg')->nullOnDelete();
            $table->foreignId('transfer_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('open_assignments_before')->nullable();
            $table->json('transfer_summary')->nullable();
            $table->text('reason');
            $table->timestamp('changed_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'changed_at'], 'user_assignment_histories_user_changed_index');
            $table->index(['changed_by', 'changed_at'], 'user_assignment_histories_actor_changed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_assignment_histories');
    }
};
