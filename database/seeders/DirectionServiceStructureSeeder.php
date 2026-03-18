<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DirectionServiceStructureSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $directions = [
            ['code' => 'DG', 'libelle' => 'Direction Generale'],
            ['code' => 'CDG', 'libelle' => 'Cabinet du Directeur General'],
            ['code' => 'DGA', 'libelle' => 'Direction Generale Adjointe'],
            ['code' => 'DAF', 'libelle' => 'Direction Administrative et Financiere'],
            ['code' => 'DS', 'libelle' => 'Direction de la Scolarite'],
            ['code' => 'DSIC', 'libelle' => 'Direction des Systemes d Information et de la Communication'],
        ];

        foreach ($directions as $direction) {
            DB::table('directions')->updateOrInsert(
                ['code' => $direction['code']],
                [
                    'libelle' => $direction['libelle'],
                    'actif' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $directionIds = DB::table('directions')
            ->whereIn('code', array_column($directions, 'code'))
            ->pluck('id', 'code')
            ->all();

        $services = [
            ['direction_code' => 'DG', 'code' => 'DG-SP', 'libelle' => 'Secretariat Particulier'],

            ['direction_code' => 'CDG', 'code' => 'CDG-SCIQ', 'libelle' => 'Service Controle Interne et Qualite'],

            ['direction_code' => 'DGA', 'code' => 'DGA-SDGA', 'libelle' => 'Secretariat du Directeur General Adjoint'],

            ['direction_code' => 'DAF', 'code' => 'DAF-SAJRH', 'libelle' => 'Service des Affaires Juridiques, Administratives et des Ressources Humaines'],
            ['direction_code' => 'DAF', 'code' => 'DAF-SFC', 'libelle' => 'Service Financier et Comptable'],
            ['direction_code' => 'DAF', 'code' => 'DAF-SAMG', 'libelle' => 'Service Approvisionnement et Moyens Generaux'],

            ['direction_code' => 'DS', 'code' => 'DS-SEB', 'libelle' => 'Service Etudiants Boursiers'],
            ['direction_code' => 'DS', 'code' => 'DS-SENB', 'libelle' => 'Service Etudiants Non Boursiers'],
            ['direction_code' => 'DS', 'code' => 'DS-PCZ', 'libelle' => 'Pool Charges de Zones'],
            ['direction_code' => 'DS', 'code' => 'DS-PGCZ', 'libelle' => 'Pool Gestionnaires / Charges de Zones'],
            ['direction_code' => 'DS', 'code' => 'DS-RP', 'libelle' => 'Responsables de Poles'],
            ['direction_code' => 'DS', 'code' => 'DS-SP', 'libelle' => 'Service Planification'],

            ['direction_code' => 'DSIC', 'code' => 'DSIC-SCRP', 'libelle' => 'Service Communication et Relations Publiques'],
            ['direction_code' => 'DSIC', 'code' => 'DSIC-SSIRS', 'libelle' => 'Service des Systemes d Information, Reseaux et Securite'],
            ['direction_code' => 'DSIC', 'code' => 'DSIC-SGDS', 'libelle' => 'Service Gestion Documentaire et Statistiques'],
        ];

        foreach ($services as $service) {
            $directionCode = $service['direction_code'];
            if (! isset($directionIds[$directionCode])) {
                continue;
            }

            DB::table('services')->updateOrInsert(
                [
                    'direction_id' => (int) $directionIds[$directionCode],
                    'code' => $service['code'],
                ],
                [
                    'libelle' => $service['libelle'],
                    'actif' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}

