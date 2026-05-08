<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('action_responsables')) {
            Schema::create('action_responsables', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->unique(['action_id', 'user_id'], 'action_responsables_action_user_unique');
                $table->index(['user_id', 'action_id'], 'action_responsables_user_action_index');
            });
        }

        if (Schema::hasColumn('actions', 'responsable_id')) {
            DB::table('actions')
                ->whereNotNull('responsable_id')
                ->orderBy('id')
                ->get(['id', 'responsable_id', 'created_at', 'updated_at'])
                ->each(function ($action): void {
                    DB::table('action_responsables')->updateOrInsert(
                        [
                            'action_id' => (int) $action->id,
                            'user_id' => (int) $action->responsable_id,
                        ],
                        [
                            'is_primary' => true,
                            'created_at' => $action->created_at ?? now(),
                            'updated_at' => $action->updated_at ?? now(),
                        ]
                    );
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('action_responsables');
    }
};
