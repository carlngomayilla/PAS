<?php

use App\Models\Action;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('actions', 'financement_statut')) {
                $table->string('financement_statut', 40)->default(Action::FINANCEMENT_NON_REQUIS)->after('montant_estime')->index();
            }
            if (! Schema::hasColumn('actions', 'financement_soumis_le')) {
                $table->timestamp('financement_soumis_le')->nullable()->after('financement_statut');
            }
            if (! Schema::hasColumn('actions', 'financement_notifie_le')) {
                $table->timestamp('financement_notifie_le')->nullable()->after('financement_soumis_le');
            }
            if (! Schema::hasColumn('actions', 'financement_daf_par')) {
                $table->foreignId('financement_daf_par')->nullable()->after('financement_notifie_le')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('actions', 'financement_daf_le')) {
                $table->timestamp('financement_daf_le')->nullable()->after('financement_daf_par');
            }
            if (! Schema::hasColumn('actions', 'financement_daf_decision')) {
                $table->string('financement_daf_decision', 20)->nullable()->after('financement_daf_le');
            }
            if (! Schema::hasColumn('actions', 'financement_daf_commentaire')) {
                $table->text('financement_daf_commentaire')->nullable()->after('financement_daf_decision');
            }
            if (! Schema::hasColumn('actions', 'financement_montant_valide')) {
                $table->decimal('financement_montant_valide', 15, 2)->nullable()->after('financement_daf_commentaire');
            }
            if (! Schema::hasColumn('actions', 'financement_reference')) {
                $table->string('financement_reference')->nullable()->after('financement_montant_valide');
            }
            if (! Schema::hasColumn('actions', 'financement_dg_par')) {
                $table->foreignId('financement_dg_par')->nullable()->after('financement_reference')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('actions', 'financement_dg_le')) {
                $table->timestamp('financement_dg_le')->nullable()->after('financement_dg_par');
            }
            if (! Schema::hasColumn('actions', 'financement_dg_decision')) {
                $table->string('financement_dg_decision', 20)->nullable()->after('financement_dg_le');
            }
            if (! Schema::hasColumn('actions', 'financement_dg_commentaire')) {
                $table->text('financement_dg_commentaire')->nullable()->after('financement_dg_decision');
            }
        });

        DB::table('actions')
            ->where('financement_requis', true)
            ->where(function ($query): void {
                $query->whereNull('financement_statut')
                    ->orWhere('financement_statut', Action::FINANCEMENT_NON_REQUIS);
            })
            ->update([
                'financement_statut' => Action::FINANCEMENT_A_TRAITER_DAF,
                'financement_soumis_le' => now(),
            ]);

        DB::table('actions')
            ->where('financement_requis', false)
            ->update(['financement_statut' => Action::FINANCEMENT_NON_REQUIS]);
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            foreach (['financement_dg_par', 'financement_daf_par'] as $column) {
                if (Schema::hasColumn('actions', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            $columns = [
                'financement_statut',
                'financement_soumis_le',
                'financement_notifie_le',
                'financement_daf_le',
                'financement_daf_decision',
                'financement_daf_commentaire',
                'financement_montant_valide',
                'financement_reference',
                'financement_dg_le',
                'financement_dg_decision',
                'financement_dg_commentaire',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('actions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};