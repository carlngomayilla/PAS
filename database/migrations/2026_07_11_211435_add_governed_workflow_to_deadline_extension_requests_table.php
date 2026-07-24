<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deadline_extension_requests', function (Blueprint $table): void {
            $table->string('chef_avis', 40)->nullable()->after('status');
            $table->text('chef_comment')->nullable()->after('chef_avis');
            $table->foreignId('chef_reviewed_by')->nullable()->after('chef_comment')->constrained('users')->nullOnDelete();
            $table->timestamp('chef_reviewed_at')->nullable()->after('chef_reviewed_by');

            $table->string('final_decision', 40)->nullable()->after('sciq_reviewed_at');
            $table->text('final_comment')->nullable()->after('final_decision');
            $table->foreignId('final_decided_by')->nullable()->after('final_comment')->constrained('users')->nullOnDelete();
            $table->timestamp('final_decided_at')->nullable()->after('final_decided_by');
            $table->string('final_approver_role', 80)->nullable()->after('final_decided_at');
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

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::table('deadline_extension_requests')
                ->whereIn('status', ['transmise_controle', 'transmise_validation_finale'])
                ->update(['status' => 'soumise']);

            DB::statement('ALTER TABLE deadline_extension_requests DROP CONSTRAINT IF EXISTS deadline_ext_requests_status_check');
            DB::statement(<<<'SQL'
                ALTER TABLE deadline_extension_requests
                ADD CONSTRAINT deadline_ext_requests_status_check
                CHECK (status IN (
                    'soumise',
                    'en_analyse',
                    'complement_demande',
                    'transmise_dg',
                    'approuvee',
                    'rejetee',
                    'mise_a_jour_appliquee'
                ))
                SQL);
        }

        Schema::table('deadline_extension_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('chef_reviewed_by');
            $table->dropConstrainedForeignId('final_decided_by');
            $table->dropColumn([
                'chef_avis',
                'chef_comment',
                'chef_reviewed_at',
                'final_decision',
                'final_comment',
                'final_decided_at',
                'final_approver_role',
            ]);
        });
    }
};
