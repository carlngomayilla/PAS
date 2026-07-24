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
                if (! Schema::hasColumn('actions', 'type_indicateur')) {
                    $table->string('type_indicateur')->nullable()->after('type_action');
                }

                if (! Schema::hasColumn('actions', 'cible')) {
                    $table->text('cible')->nullable()->after('intitule_cible');
                }

                if (! Schema::hasColumn('actions', 'quantite_a_realiser')) {
                    $table->decimal('quantite_a_realiser', 15, 4)->nullable()->after('cible');
                }

                if (! Schema::hasColumn('actions', 'statut_echeance')) {
                    $table->string('statut_echeance', 50)->nullable()->after('date_echeance');
                }

                if (! Schema::hasColumn('actions', 'statut_retard')) {
                    $table->string('statut_retard', 50)->nullable()->after('statut_echeance');
                }
            });

            $this->backfillActions();
        }

        if (Schema::hasTable('sous_actions')) {
            Schema::table('sous_actions', function (Blueprint $table): void {
                if (! Schema::hasColumn('sous_actions', 'type_indicateur')) {
                    $table->string('type_indicateur')->nullable()->after('sub_action_type');
                }

                if (! Schema::hasColumn('sous_actions', 'cible')) {
                    $table->text('cible')->nullable()->after('resultat_attendu');
                }

                if (! Schema::hasColumn('sous_actions', 'quantite_a_realiser')) {
                    $table->decimal('quantite_a_realiser', 15, 4)->nullable()->after('cible');
                }

                if (! Schema::hasColumn('sous_actions', 'livrable_attendu')) {
                    $table->text('livrable_attendu')->nullable()->after('quantite_a_realiser');
                }

                if (! Schema::hasColumn('sous_actions', 'statut_echeance')) {
                    $table->string('statut_echeance', 50)->nullable()->after('date_fin');
                }

                if (! Schema::hasColumn('sous_actions', 'statut_retard')) {
                    $table->string('statut_retard', 50)->nullable()->after('statut_echeance');
                }
            });

            $this->backfillSousActions();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sous_actions')) {
            Schema::table('sous_actions', function (Blueprint $table): void {
                $columns = [
                    'type_indicateur',
                    'cible',
                    'quantite_a_realiser',
                    'livrable_attendu',
                    'statut_echeance',
                    'statut_retard',
                ];

                $existing = array_values(array_filter(
                    $columns,
                    static fn (string $column): bool => Schema::hasColumn('sous_actions', $column)
                ));

                if ($existing !== []) {
                    $table->dropColumn($existing);
                }
            });
        }

        if (Schema::hasTable('actions')) {
            Schema::table('actions', function (Blueprint $table): void {
                $columns = [
                    'type_indicateur',
                    'cible',
                    'quantite_a_realiser',
                    'statut_echeance',
                    'statut_retard',
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
    }

    private function backfillActions(): void
    {
        DB::table('actions')
            ->whereNull('type_indicateur')
            ->whereIn('type_action', ['quantitative', 'quantitatif'])
            ->update(['type_indicateur' => 'quantitatif']);

        DB::table('actions')
            ->whereNull('type_indicateur')
            ->whereIn('type_action', ['mixte', 'composee'])
            ->update(['type_indicateur' => 'mixte']);

        DB::table('actions')
            ->whereNull('type_indicateur')
            ->update(['type_indicateur' => 'non_quantitatif']);

        DB::table('actions')
            ->whereNull('quantite_a_realiser')
            ->update(['quantite_a_realiser' => DB::raw('quantite_cible')]);

        DB::table('actions')
            ->whereNull('cible')
            ->update([
                'cible' => DB::raw("COALESCE(NULLIF(intitule_cible, ''), NULLIF(resultat_attendu, ''), NULLIF(livrable_attendu, ''), libelle)"),
            ]);
    }

    private function backfillSousActions(): void
    {
        DB::table('sous_actions')
            ->whereNull('type_indicateur')
            ->whereIn('sub_action_type', ['quantitative', 'quantitatif'])
            ->update(['type_indicateur' => 'quantitatif']);

        DB::table('sous_actions')
            ->whereNull('type_indicateur')
            ->whereIn('sub_action_type', ['mixte'])
            ->update(['type_indicateur' => 'mixte']);

        DB::table('sous_actions')
            ->whereNull('type_indicateur')
            ->update(['type_indicateur' => 'non_quantitatif']);

        DB::table('sous_actions')
            ->whereNull('quantite_a_realiser')
            ->update(['quantite_a_realiser' => DB::raw('cible_prevue')]);

        DB::table('sous_actions')
            ->whereNull('livrable_attendu')
            ->update(['livrable_attendu' => DB::raw('resultat_attendu')]);

        DB::table('sous_actions')
            ->whereNull('cible')
            ->update([
                'cible' => DB::raw("COALESCE(NULLIF(resultat_attendu, ''), NULLIF(description, ''), libelle)"),
            ]);
    }
};
