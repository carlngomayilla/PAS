<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('objectifs_operationnels')) {
            Schema::create('objectifs_operationnels', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('pao_id')->constrained('paos')->cascadeOnDelete();
                $table->foreignId('pas_id')->constrained('pas')->restrictOnDelete();
                $table->foreignId('pas_axe_id')->constrained('pas_axes')->restrictOnDelete();
                $table->foreignId('pas_objectif_id')->constrained('pas_objectifs')->restrictOnDelete();
                $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
                $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
                $table->text('libelle');
                $table->text('description')->nullable();
                $table->date('echeance');
                $table->text('indicateurs')->nullable();
                $table->string('statut', 50)->default('brouillon');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['pao_id', 'service_id'], 'obj_ops_pao_service_index');
                $table->index(['direction_id', 'service_id'], 'obj_ops_direction_service_index');
                $table->index(['pas_objectif_id', 'service_id'], 'obj_ops_pas_objectif_service_index');
            });
        }

        if (Schema::hasTable('ptas') && $this->indexExists('ptas', 'ptas_pao_unique')) {
            Schema::table('ptas', function (Blueprint $table): void {
                $table->dropUnique('ptas_pao_unique');
            });
        }

        if (Schema::hasTable('ptas') && ! $this->indexExists('ptas', 'ptas_pao_id_index')) {
            Schema::table('ptas', function (Blueprint $table): void {
                $table->index('pao_id', 'ptas_pao_id_index');
            });
        }

        if (Schema::hasTable('ptas') && ! Schema::hasColumn('ptas', 'objectif_operationnel_id')) {
            Schema::table('ptas', function (Blueprint $table): void {
                $table->foreignId('objectif_operationnel_id')
                    ->nullable()
                    ->after('pao_id')
                    ->constrained('objectifs_operationnels')
                    ->nullOnDelete();
                $table->index(['objectif_operationnel_id'], 'ptas_objectif_operationnel_id_index');
            });
        }

        if (Schema::hasTable('actions') && ! Schema::hasColumn('actions', 'objectif_operationnel_id')) {
            Schema::table('actions', function (Blueprint $table): void {
                $table->foreignId('objectif_operationnel_id')
                    ->nullable()
                    ->after('pao_id')
                    ->constrained('objectifs_operationnels')
                    ->nullOnDelete();
                $table->index(['objectif_operationnel_id'], 'actions_objectif_operationnel_id_index');
            });
        }

        if (! Schema::hasTable('sous_actions')) {
            Schema::create('sous_actions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
                $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
                $table->string('libelle');
                $table->text('description')->nullable();
                $table->text('resultat_attendu')->nullable();
                $table->text('commentaire')->nullable();
                $table->date('date_debut');
                $table->date('date_fin');
                $table->dateTime('date_realisation')->nullable();
                $table->string('statut', 50)->default('a_faire');
                $table->boolean('est_effectuee')->default(false);
                $table->decimal('taux_execution', 5, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['action_id', 'agent_id'], 'sous_actions_action_agent_index');
                $table->index(['action_id', 'est_effectuee'], 'sous_actions_action_done_index');
            });
        }

        if (Schema::hasTable('justificatifs') && ! Schema::hasColumn('justificatifs', 'sous_action_id')) {
            Schema::table('justificatifs', function (Blueprint $table): void {
                $table->foreignId('sous_action_id')
                    ->nullable()
                    ->after('action_week_id')
                    ->constrained('sous_actions')
                    ->nullOnDelete();
                $table->index(['sous_action_id'], 'justificatifs_sous_action_id_index');
            });
        }

        $this->backfillObjectifsOperationnels();
    }

    public function down(): void
    {
        if (Schema::hasTable('justificatifs') && Schema::hasColumn('justificatifs', 'sous_action_id')) {
            Schema::table('justificatifs', function (Blueprint $table): void {
                $table->dropForeign(['sous_action_id']);
                $table->dropIndex('justificatifs_sous_action_id_index');
                $table->dropColumn('sous_action_id');
            });
        }

        Schema::dropIfExists('sous_actions');

        if (Schema::hasTable('actions') && Schema::hasColumn('actions', 'objectif_operationnel_id')) {
            Schema::table('actions', function (Blueprint $table): void {
                $table->dropForeign(['objectif_operationnel_id']);
                $table->dropIndex('actions_objectif_operationnel_id_index');
                $table->dropColumn('objectif_operationnel_id');
            });
        }

        if (Schema::hasTable('ptas') && Schema::hasColumn('ptas', 'objectif_operationnel_id')) {
            Schema::table('ptas', function (Blueprint $table): void {
                $table->dropForeign(['objectif_operationnel_id']);
                $table->dropIndex('ptas_objectif_operationnel_id_index');
                $table->dropColumn('objectif_operationnel_id');
            });
        }

        Schema::dropIfExists('objectifs_operationnels');
    }

    private function backfillObjectifsOperationnels(): void
    {
        if (! Schema::hasTable('objectifs_operationnels')) {
            return;
        }

        $now = now();
        $paos = DB::table('paos')
            ->leftJoin('pas_objectifs', 'pas_objectifs.id', '=', 'paos.pas_objectif_id')
            ->select([
                'paos.id',
                'paos.pas_id',
                'paos.pas_objectif_id',
                'pas_objectifs.pas_axe_id',
                'paos.direction_id',
                'paos.service_id',
                'paos.annee',
                'paos.titre',
                'paos.objectif_operationnel',
                'paos.resultats_attendus',
                'paos.echeance',
                'paos.indicateurs_associes',
                'paos.statut',
                'paos.created_at',
            ])
            ->orderBy('paos.id')
            ->get();

        foreach ($paos as $pao) {
            if ($pao->pas_objectif_id === null || $pao->pas_axe_id === null || $pao->service_id === null) {
                continue;
            }

            $libelle = trim((string) ($pao->objectif_operationnel ?: $pao->titre));
            if ($libelle === '') {
                continue;
            }

            $existingId = DB::table('objectifs_operationnels')
                ->where('pao_id', (int) $pao->id)
                ->where('service_id', (int) $pao->service_id)
                ->where('libelle', $libelle)
                ->value('id');

            if ($existingId === null) {
                $existingId = DB::table('objectifs_operationnels')->insertGetId([
                    'pao_id' => (int) $pao->id,
                    'pas_id' => (int) $pao->pas_id,
                    'pas_axe_id' => (int) $pao->pas_axe_id,
                    'pas_objectif_id' => (int) $pao->pas_objectif_id,
                    'direction_id' => (int) $pao->direction_id,
                    'service_id' => (int) $pao->service_id,
                    'libelle' => $libelle,
                    'description' => $pao->resultats_attendus,
                    'echeance' => $pao->echeance ?: ((int) ($pao->annee ?? date('Y'))).'-12-31',
                    'indicateurs' => $pao->indicateurs_associes,
                    'statut' => (string) ($pao->statut ?: 'brouillon'),
                    'created_at' => $pao->created_at ?? $now,
                    'updated_at' => $now,
                ]);
            }

            if (Schema::hasColumn('ptas', 'objectif_operationnel_id')) {
                DB::table('ptas')
                    ->where('pao_id', (int) $pao->id)
                    ->where('service_id', (int) $pao->service_id)
                    ->whereNull('objectif_operationnel_id')
                    ->update([
                        'objectif_operationnel_id' => (int) $existingId,
                        'updated_at' => $now,
                    ]);
            }

            if (Schema::hasColumn('actions', 'objectif_operationnel_id')) {
                DB::table('actions')
                    ->where('pao_id', (int) $pao->id)
                    ->whereNull('objectif_operationnel_id')
                    ->update([
                        'objectif_operationnel_id' => (int) $existingId,
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            return DB::select('SHOW INDEX FROM '.$table.' WHERE Key_name = ?', [$index]) !== [];
        }

        if ($driver === 'pgsql') {
            return DB::select(
                'select 1 from pg_indexes where schemaname = current_schema() and tablename = ? and indexname = ? limit 1',
                [$table, $index]
            ) !== [];
        }

        return false;
    }
};
