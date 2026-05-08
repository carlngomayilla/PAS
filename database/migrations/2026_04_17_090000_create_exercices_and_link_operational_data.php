<?php

use App\Models\Exercice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exercices')) {
            Schema::create('exercices', function (Blueprint $table): void {
                $table->id();
                $table->unsignedSmallInteger('annee')->unique();
                $table->string('libelle');
                $table->date('date_debut');
                $table->date('date_fin');
                $table->string('statut', 20)->default(Exercice::STATUT_OUVERT);
                $table->boolean('is_active')->default(false)->index();
                $table->timestamps();
            });
        }

        foreach (['pas', 'paos', 'ptas', 'actions', 'kpis'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'exercice_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->foreignId('exercice_id')->nullable()->after('id')->constrained('exercices')->nullOnDelete();
                });
            }
        }

        $years = collect();
        if (Schema::hasTable('paos')) {
            $years = $years->merge(DB::table('paos')->whereNotNull('annee')->pluck('annee')->map(fn ($year) => (int) $year));
        }
        if (Schema::hasTable('pas')) {
            DB::table('pas')->select(['periode_debut', 'periode_fin'])->orderBy('id')->get()->each(function ($row) use (&$years): void {
                $start = (int) $row->periode_debut;
                $end = (int) $row->periode_fin;
                if ($start > 0 && $end >= $start) {
                    $years = $years->merge(range($start, $end));
                }
            });
        }
        if (Schema::hasTable('actions')) {
            DB::table('actions')->whereNotNull('date_debut')->pluck('date_debut')->each(function ($date) use (&$years): void {
                $years->push((int) Carbon::parse((string) $date)->year);
            });
        }

        $current = (int) now()->year;
        $years = $years->merge([$current - 2, $current - 1, $current, $current + 1])->filter()->unique()->sort()->values();
        foreach ($years as $year) {
            DB::table('exercices')->updateOrInsert(
                ['annee' => (int) $year],
                [
                    'libelle' => 'Exercice '.(int) $year,
                    'date_debut' => Carbon::create((int) $year, 1, 1)->toDateString(),
                    'date_fin' => Carbon::create((int) $year, 12, 31)->toDateString(),
                    'statut' => (int) $year < $current ? Exercice::STATUT_ARCHIVE : Exercice::STATUT_OUVERT,
                    'is_active' => (int) $year === $current,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $this->backfillExerciceIds();
    }

    public function down(): void
    {
        foreach (array_reverse(['pas', 'paos', 'ptas', 'actions', 'kpis']) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'exercice_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->dropConstrainedForeignId('exercice_id');
                });
            }
        }

        Schema::dropIfExists('exercices');
    }

    private function backfillExerciceIds(): void
    {
        if (Schema::hasTable('pas')) {
            DB::table('pas')->orderBy('id')->get(['id', 'periode_debut'])->each(function ($row): void {
                $exerciseId = DB::table('exercices')->where('annee', (int) $row->periode_debut)->value('id');
                if ($exerciseId !== null) {
                    DB::table('pas')->where('id', $row->id)->update(['exercice_id' => $exerciseId]);
                }
            });
        }

        if (Schema::hasTable('paos')) {
            DB::table('paos')->orderBy('id')->get(['id', 'annee'])->each(function ($row): void {
                $exerciseId = DB::table('exercices')->where('annee', (int) $row->annee)->value('id');
                if ($exerciseId !== null) {
                    DB::table('paos')->where('id', $row->id)->update(['exercice_id' => $exerciseId]);
                }
            });
        }

        if (Schema::hasTable('ptas')) {
            DB::table('ptas')
                ->join('paos', 'paos.id', '=', 'ptas.pao_id')
                ->whereNotNull('paos.exercice_id')
                ->select(['ptas.id', 'paos.exercice_id'])
                ->orderBy('ptas.id')
                ->get()
                ->each(fn ($row) => DB::table('ptas')->where('id', $row->id)->update(['exercice_id' => $row->exercice_id]));
        }

        if (Schema::hasTable('actions')) {
            DB::table('actions')
                ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
                ->whereNotNull('ptas.exercice_id')
                ->select(['actions.id', 'ptas.exercice_id'])
                ->orderBy('actions.id')
                ->get()
                ->each(fn ($row) => DB::table('actions')->where('id', $row->id)->update(['exercice_id' => $row->exercice_id]));
        }

        if (Schema::hasTable('kpis')) {
            DB::table('kpis')
                ->join('actions', 'actions.id', '=', 'kpis.action_id')
                ->whereNotNull('actions.exercice_id')
                ->select(['kpis.id', 'actions.exercice_id'])
                ->orderBy('kpis.id')
                ->get()
                ->each(fn ($row) => DB::table('kpis')->where('id', $row->id)->update(['exercice_id' => $row->exercice_id]));
        }
    }
};