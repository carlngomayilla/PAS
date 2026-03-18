<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 191);
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint'], 'alert_reads_user_fingerprint_unique');
            $table->index(['user_id', 'source_type', 'source_id'], 'alert_reads_user_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_reads');
    }
};
