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
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
            $table->string('operation_type', 20)->index();
            $table->decimal('amount', 15, 2);
            $table->date('operated_on')->index();
            $table->string('payment_method', 30)->nullable();
            $table->string('reference', 255)->nullable();
            $table->string('beneficiary', 255)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['action_id', 'operation_type', 'operated_on'], 'financial_transactions_action_type_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
