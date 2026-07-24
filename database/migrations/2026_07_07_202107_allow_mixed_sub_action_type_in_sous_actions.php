<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->canUpdateConstraint()) {
            return;
        }

        DB::statement('ALTER TABLE sous_actions DROP CONSTRAINT IF EXISTS sous_actions_sub_action_type_check');
        DB::statement(
            "ALTER TABLE sous_actions ADD CONSTRAINT sous_actions_sub_action_type_check CHECK ((sub_action_type IS NULL) OR ((sub_action_type)::text = ANY ((ARRAY['quantitative'::character varying, 'non_quantitative'::character varying, 'mixte'::character varying])::text[])))"
        );
    }

    public function down(): void
    {
        if (! $this->canUpdateConstraint()) {
            return;
        }

        DB::table('sous_actions')
            ->where('sub_action_type', 'mixte')
            ->update(['sub_action_type' => 'non_quantitative']);

        DB::statement('ALTER TABLE sous_actions DROP CONSTRAINT IF EXISTS sous_actions_sub_action_type_check');
        DB::statement(
            "ALTER TABLE sous_actions ADD CONSTRAINT sous_actions_sub_action_type_check CHECK ((sub_action_type IS NULL) OR ((sub_action_type)::text = ANY ((ARRAY['quantitative'::character varying, 'non_quantitative'::character varying])::text[])))"
        );
    }

    private function canUpdateConstraint(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql'
            && Schema::hasTable('sous_actions')
            && Schema::hasColumn('sous_actions', 'sub_action_type');
    }
};
