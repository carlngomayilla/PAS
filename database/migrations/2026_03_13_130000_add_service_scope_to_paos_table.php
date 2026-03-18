<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paos', function (Blueprint $table): void {
            if (! Schema::hasColumn('paos', 'service_id')) {
                $table->foreignId('service_id')
                    ->nullable()
                    ->after('direction_id')
                    ->constrained('services')
                    ->nullOnDelete();
            }
        });

        Schema::table('paos', function (Blueprint $table): void {
            $table->dropUnique('paos_objectif_annee_direction_unique');
        });

        $this->normalizeExistingPaosPerService();

        Schema::table('paos', function (Blueprint $table): void {
            $table->unique(
                ['pas_objectif_id', 'annee', 'direction_id', 'service_id'],
                'paos_objectif_annee_direction_service_unique'
            );
            $table->index(['direction_id', 'service_id'], 'paos_direction_service_index');
        });
    }

    public function down(): void
    {
        Schema::table('paos', function (Blueprint $table): void {
            $table->dropIndex('paos_direction_service_index');
            $table->dropUnique('paos_objectif_annee_direction_service_unique');
            $table->unique(['pas_objectif_id', 'annee', 'direction_id'], 'paos_objectif_annee_direction_unique');
            $table->dropConstrainedForeignId('service_id');
        });
    }

    private function normalizeExistingPaosPerService(): void
    {
        $now = now();
        $paos = DB::table('paos')
            ->orderBy('id')
            ->get();

        foreach ($paos as $pao) {
            $serviceIds = DB::table('ptas')
                ->where('pao_id', (int) $pao->id)
                ->orderBy('id')
                ->pluck('service_id')
                ->filter(static fn ($serviceId): bool => $serviceId !== null)
                ->map(static fn ($serviceId): int => (int) $serviceId)
                ->unique()
                ->values()
                ->all();

            if ($serviceIds === []) {
                $fallbackServiceId = DB::table('services')
                    ->where('direction_id', (int) $pao->direction_id)
                    ->orderBy('id')
                    ->value('id');

                if ($fallbackServiceId === null) {
                    continue;
                }

                $serviceIds = [(int) $fallbackServiceId];
            }

            $primaryServiceId = array_shift($serviceIds);

            DB::table('paos')
                ->where('id', (int) $pao->id)
                ->update([
                    'service_id' => $primaryServiceId,
                    'updated_at' => $now,
                ]);

            foreach ($serviceIds as $serviceId) {
                $newPaoId = (int) DB::table('paos')->insertGetId([
                    'pas_id' => (int) $pao->pas_id,
                    'pas_objectif_id' => $pao->pas_objectif_id !== null ? (int) $pao->pas_objectif_id : null,
                    'direction_id' => (int) $pao->direction_id,
                    'service_id' => (int) $serviceId,
                    'annee' => (int) $pao->annee,
                    'titre' => (string) $pao->titre,
                    'objectif_operationnel' => $pao->objectif_operationnel,
                    'resultats_attendus' => $pao->resultats_attendus,
                    'indicateurs_associes' => $pao->indicateurs_associes,
                    'statut' => (string) $pao->statut,
                    'valide_le' => $pao->valide_le,
                    'valide_par' => $pao->valide_par !== null ? (int) $pao->valide_par : null,
                    'echeance' => $pao->echeance,
                    'created_at' => $pao->created_at ?? $now,
                    'updated_at' => $now,
                ]);

                DB::table('ptas')
                    ->where('pao_id', (int) $pao->id)
                    ->where('service_id', (int) $serviceId)
                    ->update([
                        'pao_id' => $newPaoId,
                        'updated_at' => $now,
                    ]);
            }
        }
    }
};
