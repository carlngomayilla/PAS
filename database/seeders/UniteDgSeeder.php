<?php

namespace Database\Seeders;

use App\Models\Direction;
use App\Models\Service;
use App\Models\UniteDg;
use App\Models\User;
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

        $this->syncUsersWithCabinetUnits();
    }

    private function syncUsersWithCabinetUnits(): void
    {
        $units = UniteDg::query()->get(['id', 'direction_id', 'code'])->keyBy('code');
        $services = Service::query()
            ->whereHas('direction', fn ($query) => $query->where('code', 'DG'))
            ->pluck('id', 'code');

        $assignments = [
            UniteDg::CODE_SCIQ => [User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL, User::ROLE_CHEF_UNITE_SCIQ],
            UniteDg::CODE_DGA => [User::ROLE_DGA_SUPERVISION, User::ROLE_CHEF_UNITE_DGA],
            UniteDg::CODE_CABINET => [User::ROLE_COLLABORATEUR, User::ROLE_CABINET, User::ROLE_CABINET_SUPERVISION, User::ROLE_CHEF_UNITE_CABINET],
            UniteDg::CODE_UCAS => [User::ROLE_UCAS, User::ROLE_CHEF_UNITE_UCAS],
        ];
        $serviceCodeByUnit = [
            UniteDg::CODE_SCIQ => 'SCIQ',
            UniteDg::CODE_CABINET => 'COLLAB',
            UniteDg::CODE_UCAS => 'UCAS',
        ];

        foreach ($assignments as $unitCode => $roles) {
            $unit = $units->get($unitCode);
            if ($unit === null) {
                continue;
            }

            $payload = [
                'direction_id' => (int) $unit->direction_id,
                'unite_dg_id' => (int) $unit->id,
            ];

            $serviceCode = $serviceCodeByUnit[$unitCode] ?? null;
            if ($serviceCode !== null && isset($services[$serviceCode])) {
                $payload['service_id'] = (int) $services[$serviceCode];
            }

            User::query()
                ->whereIn('role', $roles)
                ->update($payload);

            $chefRole = match ($unitCode) {
                UniteDg::CODE_SCIQ => User::ROLE_CHEF_UNITE_SCIQ,
                UniteDg::CODE_DGA => User::ROLE_CHEF_UNITE_DGA,
                UniteDg::CODE_UCAS => User::ROLE_CHEF_UNITE_UCAS,
                default => User::ROLE_CHEF_UNITE_CABINET,
            };

            $chefId = User::query()
                ->where('role', $chefRole)
                ->where('unite_dg_id', (int) $unit->id)
                ->value('id');

            if ($chefId !== null) {
                UniteDg::query()
                    ->whereKey((int) $unit->id)
                    ->update(['chef_user_id' => (int) $chefId]);
            }
        }
    }
}
