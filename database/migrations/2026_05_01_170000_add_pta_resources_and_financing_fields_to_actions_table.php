<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('actions', 'ressources_necessaires')) {
                $table->json('ressources_necessaires')->nullable()->after('ressources_financieres');
            }

            if (! Schema::hasColumn('actions', 'ressources_details')) {
                $table->text('ressources_details')->nullable()->after('ressources_necessaires');
            }

            if (! Schema::hasColumn('actions', 'nature_financement')) {
                $table->string('nature_financement')->nullable()->after('montant_estime');
            }

            if (! Schema::hasColumn('actions', 'justificatif_financement_path')) {
                $table->string('justificatif_financement_path')->nullable()->after('nature_financement');
            }

            if (! Schema::hasColumn('actions', 'commentaire_financement')) {
                $table->text('commentaire_financement')->nullable()->after('justificatif_financement_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $columns = [
                'ressources_necessaires',
                'ressources_details',
                'nature_financement',
                'justificatif_financement_path',
                'commentaire_financement',
            ];

            $existing = array_values(array_filter(
                $columns,
                static fn (string $column): bool => Schema::hasColumn('actions', $column)
            ));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
