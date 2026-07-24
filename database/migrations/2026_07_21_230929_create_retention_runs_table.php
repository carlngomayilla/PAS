<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('retention_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 20);
            $table->string('mode', 20);
            $table->string('status', 20);
            $table->string('source', 20)->default('web');
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('batch_key', 80)->nullable();
            $table->json('candidates')->nullable();
            $table->json('processed')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['scope', 'status', 'started_at'], 'retention_runs_scope_status_started_index');
            $table->index(['initiated_by', 'started_at'], 'retention_runs_actor_started_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE retention_runs ADD CONSTRAINT retention_runs_scope_check CHECK (scope IN ('data', 'planning'))");
            DB::statement("ALTER TABLE retention_runs ADD CONSTRAINT retention_runs_mode_check CHECK (mode IN ('dry_run', 'execute'))");
            DB::statement("ALTER TABLE retention_runs ADD CONSTRAINT retention_runs_status_check CHECK (status IN ('running', 'completed', 'failed'))");
            DB::statement("ALTER TABLE retention_runs ADD CONSTRAINT retention_runs_source_check CHECK (source IN ('web', 'console', 'scheduler'))");
            DB::statement("ALTER TABLE retention_runs ADD CONSTRAINT retention_runs_lifecycle_check CHECK ((status = 'running' AND completed_at IS NULL) OR (status IN ('completed', 'failed') AND completed_at IS NOT NULL))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retention_runs');
    }
};
