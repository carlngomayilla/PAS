<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec v2 PAS ANBG (28/05/2026) — suppression du KPI conformite et de la note du chef.
 *
 * 1) Ajoute la colonne actions.motif_validation_chef (text, nullable) qui prend le
 *    relais du motif de rejet/correction porte historiquement par evaluation_commentaire.
 *    Spec v2 : "Decisions chef = Valide / Rejete ou a corriger (motif obligatoire si rejet)".
 *    Le motif reste donc essentiel ; seules la note quantitative et le KPI conformite
 *    disparaissent.
 *
 * 2) Copie evaluation_commentaire -> motif_validation_chef.
 *
 * 3) Archive les valeurs non nulles dans journal_audit avant suppression (modules
 *    archive_kpi_conformite / archive_chef_quality_note, action = archive_before_drop).
 *
 * 4) Drop les colonnes suivantes :
 *    - action_kpis.kpi_conformite
 *    - actions.taux_conformite
 *    - actions.evaluation_note
 *    - actions.evaluation_commentaire
 *    - actions.taux_valide_chef
 *    - actions.conformite_chef
 *    - actions.observation_qualite_chef
 *
 * Reversible : la down() restaure le schema (sans les donnees, perdues a dessein lors
 * de la suppression).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        Schema::table('actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('actions', 'motif_validation_chef')) {
                // text nullable, place a la fin pour ne pas casser les outils qui lisent l ordre des colonnes.
                $table->text('motif_validation_chef')->nullable();
            }
        });

        if (Schema::hasColumn('actions', 'evaluation_commentaire')) {
            DB::table('actions')
                ->whereNotNull('evaluation_commentaire')
                ->update([
                    'motif_validation_chef' => DB::raw('evaluation_commentaire'),
                ]);
        }

        if (Schema::hasTable('action_kpis') && Schema::hasColumn('action_kpis', 'kpi_conformite')) {
            $this->archiveActionKpiConformite($now);
        }

        if (Schema::hasTable('actions')) {
            $this->archiveActionChefQualityNote($now);
        }

        if (Schema::hasTable('action_kpis') && Schema::hasColumn('action_kpis', 'kpi_conformite')) {
            Schema::table('action_kpis', function (Blueprint $table): void {
                $table->dropColumn('kpi_conformite');
            });
        }

        Schema::table('actions', function (Blueprint $table): void {
            foreach ([
                'taux_conformite',
                'evaluation_note',
                'evaluation_commentaire',
                'taux_valide_chef',
                'conformite_chef',
                'observation_qualite_chef',
            ] as $column) {
                if (Schema::hasColumn('actions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('actions', 'evaluation_note')) {
                $table->decimal('evaluation_note', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('actions', 'evaluation_commentaire')) {
                $table->text('evaluation_commentaire')->nullable();
            }
            if (! Schema::hasColumn('actions', 'taux_conformite')) {
                $table->decimal('taux_conformite', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('actions', 'taux_valide_chef')) {
                $table->decimal('taux_valide_chef', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('actions', 'conformite_chef')) {
                $table->string('conformite_chef', 40)->nullable();
            }
            if (! Schema::hasColumn('actions', 'observation_qualite_chef')) {
                $table->text('observation_qualite_chef')->nullable();
            }
        });

        // Recopie symetrique pour limiter la perte de donnees en cas de rollback.
        if (Schema::hasColumn('actions', 'motif_validation_chef') && Schema::hasColumn('actions', 'evaluation_commentaire')) {
            DB::table('actions')
                ->whereNotNull('motif_validation_chef')
                ->update([
                    'evaluation_commentaire' => DB::raw('motif_validation_chef'),
                ]);
        }

        if (Schema::hasColumn('actions', 'motif_validation_chef')) {
            Schema::table('actions', function (Blueprint $table): void {
                $table->dropColumn('motif_validation_chef');
            });
        }

        if (Schema::hasTable('action_kpis') && ! Schema::hasColumn('action_kpis', 'kpi_conformite')) {
            Schema::table('action_kpis', function (Blueprint $table): void {
                $table->decimal('kpi_conformite', 7, 2)->default(85);
            });
        }
    }

    private function archiveActionKpiConformite(Carbon $now): void
    {
        $rows = DB::table('action_kpis')
            ->whereNotNull('kpi_conformite')
            ->where('kpi_conformite', '<>', 0)
            ->select('id', 'action_id', 'kpi_conformite')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $payload = $rows->map(fn ($row): array => [
            'user_id' => null,
            'module' => 'archive_kpi_conformite',
            'entite_type' => \App\Models\ActionKpi::class,
            'entite_id' => (int) $row->id,
            'action' => 'archive_before_drop',
            'ancienne_valeur' => json_encode([
                'action_id' => (int) $row->action_id,
                'kpi_conformite' => (float) $row->kpi_conformite,
            ], JSON_THROW_ON_ERROR),
            'nouvelle_valeur' => null,
            'adresse_ip' => null,
            'user_agent' => 'migration:drop_chef_quality_note_and_conformite_kpi',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('journal_audit')->insert($chunk);
        }
    }

    private function archiveActionChefQualityNote(Carbon $now): void
    {
        $columns = array_values(array_filter([
            'evaluation_note',
            'evaluation_commentaire',
            'taux_conformite',
            'taux_valide_chef',
            'conformite_chef',
            'observation_qualite_chef',
        ], static fn (string $column): bool => Schema::hasColumn('actions', $column)));

        if ($columns === []) {
            return;
        }

        $select = array_merge(['id'], $columns);

        $whereClauses = collect($columns)
            ->map(static fn (string $column): string => $column.' IS NOT NULL')
            ->implode(' OR ');

        $rows = DB::table('actions')
            ->select($select)
            ->whereRaw('('.$whereClauses.')')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $payload = $rows->map(function ($row) use ($columns, $now): array {
            $snapshot = [];
            foreach ($columns as $column) {
                $value = $row->{$column} ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $snapshot[$column] = $value;
            }

            return [
                'user_id' => null,
                'module' => 'archive_chef_quality_note',
                'entite_type' => \App\Models\Action::class,
                'entite_id' => (int) $row->id,
                'action' => 'archive_before_drop',
                'ancienne_valeur' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'nouvelle_valeur' => null,
                'adresse_ip' => null,
                'user_agent' => 'migration:drop_chef_quality_note_and_conformite_kpi',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('journal_audit')->insert($chunk);
        }
    }
};
