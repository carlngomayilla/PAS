<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CleanupLegacyDirectionServiceSeeder extends Seeder
{
    public function run(): void
    {
        $this->deactivateLegacyEntries();

        return;

        $keepDirectionCodes = ['DG', 'DGA', 'SCIQ', 'UCAS', 'DS', 'DSIC', 'DAF'];
        $keepServiceCodes = [
            'DIRGEN',
            'CAB',
            'DIRECTION',
            'SECDGA',
            'CTRLINT',
            'UCAS',
            'ACCUEIL',
            'ENB',
            'EB',
            'PLANIF',
            'SIRS',
            'CRP',
            'GDS',
            'AJARH',
            'SFC',
            'AMG',
        ];

        $removeDirectionIds = DB::table('directions')
            ->whereNotIn('code', $keepDirectionCodes)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $removeServiceIds = DB::table('services')
            ->join('directions', 'directions.id', '=', 'services.direction_id')
            ->where(function ($query) use ($keepDirectionCodes, $keepServiceCodes): void {
                $query->whereNotIn('directions.code', $keepDirectionCodes)
                    ->orWhereNotIn('services.code', $keepServiceCodes);
            })
            ->pluck('services.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        DB::transaction(function () use ($removeDirectionIds, $removeServiceIds): void {
            $ptaIds = collect();

            if ($removeServiceIds !== []) {
                $ptaIds = $ptaIds->merge(
                    DB::table('ptas')
                        ->whereIn('service_id', $removeServiceIds)
                        ->pluck('id')
                        ->all()
                );

                DB::table('users')
                    ->whereIn('service_id', $removeServiceIds)
                    ->update([
                        'service_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            if ($removeDirectionIds !== []) {
                $ptaIds = $ptaIds->merge(
                    DB::table('ptas')
                        ->whereIn('direction_id', $removeDirectionIds)
                        ->pluck('id')
                        ->all()
                );

                DB::table('users')
                    ->whereIn('direction_id', $removeDirectionIds)
                    ->update([
                        'direction_id' => null,
                        'service_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            $ptaIds = $ptaIds
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($ptaIds !== []) {
                DB::table('ptas')->whereIn('id', $ptaIds)->delete();
            }

            if ($removeDirectionIds !== []) {
                DB::table('paos')->whereIn('direction_id', $removeDirectionIds)->delete();
            }

            if ($removeServiceIds !== []) {
                DB::table('services')->whereIn('id', $removeServiceIds)->delete();
            }

            if ($removeDirectionIds !== []) {
                DB::table('directions')->whereIn('id', $removeDirectionIds)->delete();
            }
        });
    }

    private function deactivateLegacyEntries(): void
    {
        $keepDirectionCodes = ['DG', 'DSIC', 'DAF', 'DS'];
        $keepServiceCodesByDirection = [
            'DG' => ['UCAS', 'SCIQ', 'COLLAB'],
            'DSIC' => ['SIRS', 'CRP', 'GDS'],
            'DAF' => ['AJARH', 'AMG', 'SFC'],
            'DS' => ['EB', 'ENB', 'PLANIF'],
        ];

        DB::table('directions')
            ->whereNotIn('code', $keepDirectionCodes)
            ->update(['actif' => false, 'updated_at' => now()]);

        $directionIds = DB::table('directions')
            ->whereIn('code', $keepDirectionCodes)
            ->pluck('id', 'code')
            ->all();

        foreach ($keepServiceCodesByDirection as $directionCode => $serviceCodes) {
            $directionId = $directionIds[$directionCode] ?? null;
            if ($directionId === null) {
                continue;
            }

            DB::table('services')
                ->where('direction_id', (int) $directionId)
                ->whereNotIn('code', $serviceCodes)
                ->update(['actif' => false, 'updated_at' => now()]);
        }
    }
}
