<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX export_templates_active_default_scope_unique
            ON export_templates (
                module,
                report_type,
                format,
                (COALESCE(target_profile, '')),
                (COALESCE(reading_level, ''))
            )
            WHERE status = 'published' AND is_active = true AND is_default = true
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX export_template_assignments_exact_scope_unique
            ON export_template_assignments (
                export_template_id,
                module,
                report_type,
                format,
                (COALESCE(target_profile, '')),
                (COALESCE(reading_level, '')),
                (COALESCE(direction_id, 0)),
                (COALESCE(service_id, 0))
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX export_template_assignments_active_default_scope_unique
            ON export_template_assignments (
                module,
                report_type,
                format,
                (COALESCE(target_profile, '')),
                (COALESCE(reading_level, '')),
                (COALESCE(direction_id, 0)),
                (COALESCE(service_id, 0))
            )
            WHERE is_active = true AND is_default = true
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS export_template_assignments_active_default_scope_unique');
        DB::statement('DROP INDEX IF EXISTS export_template_assignments_exact_scope_unique');
        DB::statement('DROP INDEX IF EXISTS export_templates_active_default_scope_unique');
    }
};
