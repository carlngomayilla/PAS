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
        $hasActionWeekId = Schema::hasColumn('justificatifs', 'action_week_id');
        $hasCategorie = Schema::hasColumn('justificatifs', 'categorie');

        Schema::table('justificatifs', function (Blueprint $table) use ($hasActionWeekId, $hasCategorie): void {
            if (! $hasActionWeekId) {
                $table->foreignId('action_week_id')
                    ->nullable()
                    ->after('justifiable_id')
                    ->constrained('action_weeks')
                    ->nullOnDelete();
            }

            if (! $hasCategorie) {
                $table->string('categorie', 30)
                    ->nullable()
                    ->after('description');
            }
        });

        if (! Schema::hasTable('action_justificatifs')) {
            return;
        }

        DB::table('action_justificatifs')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = [
                        'justifiable_type' => Action::class,
                        'justifiable_id' => (int) $row->action_id,
                        'action_week_id' => $row->action_week_id !== null ? (int) $row->action_week_id : null,
                        'categorie' => (string) $row->categorie,
                        'nom_original' => (string) $row->nom_original,
                        'chemin_stockage' => (string) $row->chemin_stockage,
                        'mime_type' => $row->mime_type,
                        'taille_octets' => $row->taille_octets !== null ? (int) $row->taille_octets : null,
                        'description' => $row->description,
                        'ajoute_par' => $row->ajoute_par !== null ? (int) $row->ajoute_par : null,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => $row->updated_at ?? now(),
                    ];
                }

                if ($payload !== []) {
                    DB::table('justificatifs')->insert($payload);
                }
            }, 'id');
    }

    public function down(): void
    {
        $hasActionWeekId = Schema::hasColumn('justificatifs', 'action_week_id');
        $hasCategorie = Schema::hasColumn('justificatifs', 'categorie');

        Schema::table('justificatifs', function (Blueprint $table) use ($hasActionWeekId, $hasCategorie): void {
            if ($hasActionWeekId) {
                $table->dropConstrainedForeignId('action_week_id');
            }

            if ($hasCategorie) {
                $table->dropColumn('categorie');
            }
        });
    }
};
