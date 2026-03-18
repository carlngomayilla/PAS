<?php

namespace Database\Seeders;

use App\Models\Direction;
use Illuminate\Database\Seeder;

class DirectionSeeder extends Seeder
{
    public function run(): void
    {
        $directions = [
            ['code' => 'DAF', 'libelle' => 'Direction Administrative et Financiere'],
            ['code' => 'DSI', 'libelle' => 'Direction des Systemes d Information'],
            ['code' => 'DPP', 'libelle' => 'Direction Planification et Performance'],
        ];

        foreach ($directions as $direction) {
            Direction::query()->updateOrCreate(
                ['code' => $direction['code']],
                [
                    'libelle' => $direction['libelle'],
                    'actif' => true,
                ]
            );
        }
    }
}

