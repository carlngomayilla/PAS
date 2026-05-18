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
    }
}
