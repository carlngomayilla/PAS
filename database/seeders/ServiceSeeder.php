<?php

namespace Database\Seeders;

use App\Models\Direction;
use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            'DAF' => [
                ['code' => 'SFIN', 'libelle' => 'Service Finances'],
                ['code' => 'SRH', 'libelle' => 'Service Ressources Humaines'],
            ],
            'DSI' => [
                ['code' => 'SDEV', 'libelle' => 'Service Developpement'],
                ['code' => 'SINF', 'libelle' => 'Service Infrastructure'],
            ],
            'DPP' => [
                ['code' => 'SPLA', 'libelle' => 'Service Planification'],
                ['code' => 'SSEV', 'libelle' => 'Service Suivi et Evaluation'],
            ],
        ];

        foreach ($definitions as $directionCode => $services) {
            $direction = Direction::query()->where('code', $directionCode)->firstOrFail();

            foreach ($services as $service) {
                Service::query()->updateOrCreate(
                    [
                        'direction_id' => $direction->id,
                        'code' => $service['code'],
                    ],
                    [
                        'libelle' => $service['libelle'],
                        'actif' => true,
                    ]
                );
            }
        }
    }
}

