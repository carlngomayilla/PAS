<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('actions', 'contexte_action')) {
            Schema::table('actions', function (Blueprint $table): void {
                $table->string('contexte_action', 30)
                    ->default('pilotage')
                    ->after('responsable_id');
            });
        }

        if (! Schema::hasColumn('actions', 'origine_action')) {
            Schema::table('actions', function (Blueprint $table): void {
                $table->string('origine_action', 30)
                    ->default('PTA')
                    ->after('contexte_action');
            });
        }

        Schema::table('actions', function (Blueprint $table): void {
            $table->index('contexte_action', 'actions_contexte_action_index');
            $table->index(['responsable_id', 'contexte_action'], 'actions_responsable_contexte_index');
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->dropIndex('actions_responsable_contexte_index');
            $table->dropIndex('actions_contexte_action_index');
        });

        if (Schema::hasColumn('actions', 'origine_action')) {
            Schema::table('actions', function (Blueprint $table): void {
                $table->dropColumn('origine_action');
            });
        }

        if (Schema::hasColumn('actions', 'contexte_action')) {
            Schema::table('actions', function (Blueprint $table): void {
                $table->dropColumn('contexte_action');
            });
        }
    }
};
