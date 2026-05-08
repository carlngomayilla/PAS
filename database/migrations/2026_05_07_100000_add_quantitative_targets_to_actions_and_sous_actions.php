<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('actions')) {
            Schema::table('actions', function (Blueprint $table): void {
                if (! Schema::hasColumn('actions', 'intitule_cible')) {
                    $table->string('intitule_cible')->nullable()->after('type_cible');
                }

                if (! Schema::hasColumn('actions', 'seuil_minimum')) {
                    $table->decimal('seuil_minimum', 5, 2)->default(80)->after('quantite_realisee');
                }

                if (! Schema::hasColumn('actions', 'methode_calcul')) {
                    $table->string('methode_calcul', 50)->default('sum_sous_actions')->after('seuil_minimum');
                }

                if (! Schema::hasColumn('actions', 'justificatif_obligatoire')) {
                    $table->boolean('justificatif_obligatoire')->default(false)->after('methode_calcul');
                }

                if (! Schema::hasColumn('actions', 'echeance_cible')) {
                    $table->date('echeance_cible')->nullable()->after('justificatif_obligatoire');
                }

                if (! Schema::hasColumn('actions', 'reste_a_realiser')) {
                    $table->decimal('reste_a_realiser', 15, 4)->default(0)->after('echeance_cible');
                }

                if (! Schema::hasColumn('actions', 'taux_depassement')) {
                    $table->decimal('taux_depassement', 7, 2)->default(0)->after('reste_a_realiser');
                }

                if (! Schema::hasColumn('actions', 'statut_performance')) {
                    $table->string('statut_performance', 50)->default('non_evaluee')->after('taux_depassement');
                }

                if (! Schema::hasColumn('actions', 'statut_execution_quantitative')) {
                    $table->string('statut_execution_quantitative', 50)->default('non_demarre')->after('statut_performance');
                }
            });
        }

        if (Schema::hasTable('sous_actions')) {
            Schema::table('sous_actions', function (Blueprint $table): void {
                if (! Schema::hasColumn('sous_actions', 'cible_prevue')) {
                    $table->decimal('cible_prevue', 15, 4)->nullable()->after('resultat_attendu');
                }

                if (! Schema::hasColumn('sous_actions', 'quantite_realisee')) {
                    $table->decimal('quantite_realisee', 15, 4)->default(0)->after('cible_prevue');
                }

                if (! Schema::hasColumn('sous_actions', 'unite')) {
                    $table->string('unite', 100)->nullable()->after('quantite_realisee');
                }

                if (! Schema::hasColumn('sous_actions', 'resultat_obtenu')) {
                    $table->text('resultat_obtenu')->nullable()->after('unite');
                }

                if (! Schema::hasColumn('sous_actions', 'taux_realisation')) {
                    $table->decimal('taux_realisation', 7, 2)->default(0)->after('resultat_obtenu');
                }

                if (! Schema::hasColumn('sous_actions', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('date_realisation');
                }
            });
        }

        if (Schema::hasTable('ptas') && ! $this->indexExists('ptas', 'ptas_service_exercice_index')) {
            Schema::table('ptas', function (Blueprint $table): void {
                $table->index(['service_id', 'exercice_id'], 'ptas_service_exercice_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ptas') && $this->indexExists('ptas', 'ptas_service_exercice_index')) {
            Schema::table('ptas', function (Blueprint $table): void {
                $table->dropIndex('ptas_service_exercice_index');
            });
        }

        if (Schema::hasTable('sous_actions')) {
            Schema::table('sous_actions', function (Blueprint $table): void {
                $columns = [
                    'cible_prevue',
                    'quantite_realisee',
                    'unite',
                    'resultat_obtenu',
                    'taux_realisation',
                    'completed_at',
                ];

                $existing = array_values(array_filter($columns, static fn (string $column): bool => Schema::hasColumn('sous_actions', $column)));
                if ($existing !== []) {
                    $table->dropColumn($existing);
                }
            });
        }

        if (Schema::hasTable('actions')) {
            Schema::table('actions', function (Blueprint $table): void {
                $columns = [
                    'intitule_cible',
                    'seuil_minimum',
                    'methode_calcul',
                    'justificatif_obligatoire',
                    'echeance_cible',
                    'reste_a_realiser',
                    'taux_depassement',
                    'statut_performance',
                    'statut_execution_quantitative',
                ];

                $existing = array_values(array_filter($columns, static fn (string $column): bool => Schema::hasColumn('actions', $column)));
                if ($existing !== []) {
                    $table->dropColumn($existing);
                }
            });
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
