<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pas', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas', 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('statut')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('pas', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('pas_axes', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_axes', 'periode_debut')) {
                $table->date('periode_debut')->nullable()->after('libelle');
            }

            if (! Schema::hasColumn('pas_axes', 'periode_fin')) {
                $table->date('periode_fin')->nullable()->after('periode_debut');
            }

            if (! Schema::hasColumn('pas_axes', 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('ordre')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('pas_axes', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('pas_objectifs', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_objectifs', 'valeurs_cible')) {
                $table->json('valeurs_cible')->nullable()->after('valeur_cible');
            }

            if (! Schema::hasColumn('pas_objectifs', 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('ordre')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('pas_objectifs', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        $this->backfillStrategicMetadata();
    }

    public function down(): void
    {
        Schema::table('pas_objectifs', function (Blueprint $table): void {
            if (Schema::hasColumn('pas_objectifs', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('pas_objectifs', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }

            if (Schema::hasColumn('pas_objectifs', 'valeurs_cible')) {
                $table->dropColumn('valeurs_cible');
            }
        });

        Schema::table('pas_axes', function (Blueprint $table): void {
            if (Schema::hasColumn('pas_axes', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('pas_axes', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }

            if (Schema::hasColumn('pas_axes', 'periode_fin')) {
                $table->dropColumn('periode_fin');
            }

            if (Schema::hasColumn('pas_axes', 'periode_debut')) {
                $table->dropColumn('periode_debut');
            }
        });

        Schema::table('pas', function (Blueprint $table): void {
            if (Schema::hasColumn('pas', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('pas', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
        });
    }

    private function backfillStrategicMetadata(): void
    {
        $fallbackUserId = DB::table('users')
            ->whereIn('role', ['admin', 'dg', 'planification', 'cabinet'])
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 WHEN role = 'dg' THEN 1 WHEN role = 'planification' THEN 2 ELSE 3 END")
            ->value('id');

        $pasRows = DB::table('pas')->get(['id', 'periode_debut', 'periode_fin', 'valide_par']);
        foreach ($pasRows as $pas) {
            $createdBy = $pas->valide_par !== null ? (int) $pas->valide_par : ($fallbackUserId !== null ? (int) $fallbackUserId : null);

            DB::table('pas')
                ->where('id', (int) $pas->id)
                ->update([
                    'created_by' => $createdBy,
                ]);

            $startDate = sprintf('%04d-01-01', (int) $pas->periode_debut);
            $endDate = sprintf('%04d-12-31', (int) $pas->periode_fin);

            DB::table('pas_axes')
                ->where('pas_id', (int) $pas->id)
                ->update([
                    'periode_debut' => DB::raw("COALESCE(periode_debut, '{$startDate}')"),
                    'periode_fin' => DB::raw("COALESCE(periode_fin, '{$endDate}')"),
                    'created_by' => DB::raw('COALESCE(created_by, '.($createdBy !== null ? (int) $createdBy : 'NULL').')'),
                ]);
        }

        $objectifs = DB::table('pas_objectifs')->get([
            'id',
            'indicateur_global',
            'valeur_cible',
            'created_by',
        ]);

        foreach ($objectifs as $objectif) {
            $payload = [];

            if ($objectif->created_by === null && $fallbackUserId !== null) {
                $payload['created_by'] = (int) $fallbackUserId;
            }

            $targetValues = [];
            if ($objectif->indicateur_global !== null && trim((string) $objectif->indicateur_global) !== '') {
                $targetValues['indicateur_global'] = (string) $objectif->indicateur_global;
            }
            if ($objectif->valeur_cible !== null && trim((string) $objectif->valeur_cible) !== '') {
                $targetValues['valeur_cible'] = (string) $objectif->valeur_cible;
            }

            if ($targetValues !== []) {
                $payload['valeurs_cible'] = json_encode($targetValues, JSON_UNESCAPED_UNICODE);
            }

            if ($payload !== []) {
                DB::table('pas_objectifs')
                    ->where('id', (int) $objectif->id)
                    ->update($payload);
            }
        }
    }
};
