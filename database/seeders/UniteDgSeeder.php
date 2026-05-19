<?php

namespace Database\Seeders;

use App\Models\Direction;
use App\Models\UniteDg;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UniteDgSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Crée les 4 unités de la Direction Générale : SCIQ, DGA, Cabinet, UCAS.
     * Les 3 premières ont une portée globale (vue agence) ; UCAS a une portée limitée à son périmètre.
     */
    public function run(): void
    {
        $directionDg = Direction::query()->where('code', 'DG')->first();

        if (! $directionDg) {
            $this->command?->warn('Direction "DG" introuvable — le seeder UniteDg est ignoré.');

            return;
        }

        $unites = [
            [
                'code' => UniteDg::CODE_SCIQ,
                'libelle' => 'Service de Contrôle Interne et Qualité',
                'portee_globale' => true,
            ],
            [
                'code' => UniteDg::CODE_DGA,
                'libelle' => 'Direction Générale Adjointe',
                'portee_globale' => true,
            ],
            [
                'code' => UniteDg::CODE_CABINET,
                'libelle' => 'Cabinet du Directeur Général',
                'portee_globale' => true,
            ],
            [
                'code' => UniteDg::CODE_UCAS,
                'libelle' => 'Unité de Coordination et d’Appui au Suivi',
                'portee_globale' => false,
            ],
        ];

        foreach ($unites as $unite) {
            if ($unite['code'] === UniteDg::CODE_DGA) {
                continue;
            }

            if ($unite['code'] === UniteDg::CODE_CABINET) {
                $unite['libelle'] = 'Collaborateurs';
            }

            UniteDg::query()->updateOrCreate(
                ['code' => $unite['code']],
                [
                    'direction_id' => $directionDg->id,
                    'libelle' => $unite['libelle'],
                    'portee_globale' => $unite['portee_globale'],
                    'actif' => true,
                ]
            );
        }

        UniteDg::query()
            ->where('code', UniteDg::CODE_DGA)
            ->update(['actif' => false]);

        $this->syncUsersWithCabinetUnits();
    }

    private function syncUsersWithCabinetUnits(): void
    {
        $units = UniteDg::query()->pluck('id', 'code');

        $assignments = [
            UniteDg::CODE_SCIQ => [\App\Models\User::ROLE_SCIQ, \App\Models\User::ROLE_SCIQ_SUIVI_GLOBAL, \App\Models\User::ROLE_CHEF_UNITE_SCIQ],
            UniteDg::CODE_CABINET => [\App\Models\User::ROLE_COLLABORATEUR, \App\Models\User::ROLE_CABINET, \App\Models\User::ROLE_CABINET_SUPERVISION, \App\Models\User::ROLE_CHEF_UNITE_CABINET],
            UniteDg::CODE_UCAS => [\App\Models\User::ROLE_UCAS, \App\Models\User::ROLE_CHEF_UNITE_UCAS],
        ];

        foreach ($assignments as $unitCode => $roles) {
            $unitId = $units[$unitCode] ?? null;
            if ($unitId === null) {
                continue;
            }

            \App\Models\User::query()
                ->whereIn('role', $roles)
                ->update(['unite_dg_id' => (int) $unitId]);

            $chefRole = match ($unitCode) {
                UniteDg::CODE_SCIQ => \App\Models\User::ROLE_CHEF_UNITE_SCIQ,
                UniteDg::CODE_UCAS => \App\Models\User::ROLE_CHEF_UNITE_UCAS,
                default => \App\Models\User::ROLE_CHEF_UNITE_CABINET,
            };

            $chefId = \App\Models\User::query()
                ->where('role', $chefRole)
                ->where('unite_dg_id', (int) $unitId)
                ->value('id');

            if ($chefId !== null) {
                UniteDg::query()
                    ->whereKey((int) $unitId)
                    ->update(['chef_user_id' => (int) $chefId]);
            }
        }
    }
}
