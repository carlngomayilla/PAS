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
        Schema::table('deadline_extension_requests', function (Blueprint $table): void {
            $table->json('requested_changes')->nullable()->after('requested_deadline');
            $table->json('original_values')->nullable()->after('requested_changes');
            $table->json('applied_values')->nullable()->after('original_values');

            $table->string('director_decision', 40)->nullable()->after('chef_reviewed_at');
            $table->text('director_comment')->nullable()->after('director_decision');
            $table->foreignId('director_reviewed_by')->nullable()->after('director_comment')->constrained('users')->nullOnDelete();
            $table->timestamp('director_reviewed_at')->nullable()->after('director_reviewed_by');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE deadline_extension_requests DROP CONSTRAINT IF EXISTS deadline_ext_requests_status_check');
            DB::statement(<<<'SQL'
                ALTER TABLE deadline_extension_requests
                ADD CONSTRAINT deadline_ext_requests_status_check
                CHECK (status IN (
                    'soumise',
                    'en_analyse',
                    'complement_demande',
                    'transmise_direction',
                    'transmise_controle',
                    'transmise_validation_finale',
                    'transmise_dg',
                    'approuvee',
                    'rejetee',
                    'mise_a_jour_appliquee'
                ))
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::table('deadline_extension_requests')
                ->where('status', 'transmise_direction')
                ->update(['status' => 'transmise_controle']);

            DB::statement('ALTER TABLE deadline_extension_requests DROP CONSTRAINT IF EXISTS deadline_ext_requests_status_check');
            DB::statement(<<<'SQL'
                ALTER TABLE deadline_extension_requests
                ADD CONSTRAINT deadline_ext_requests_status_check
                CHECK (status IN (
                    'soumise',
                    'en_analyse',
                    'complement_demande',
                    'transmise_controle',
                    'transmise_validation_finale',
                    'transmise_dg',
                    'approuvee',
                    'rejetee',
                    'mise_a_jour_appliquee'
                ))
                SQL);
        }

        Schema::table('deadline_extension_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('director_reviewed_by');
            $table->dropColumn([
                'requested_changes',
                'original_values',
                'applied_values',
                'director_decision',
                'director_comment',
                'director_reviewed_at',
            ]);
        });
    }
};
