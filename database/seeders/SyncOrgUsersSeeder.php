<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SyncOrgUsersSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $directions = [
            ['code' => 'DG', 'libelle' => 'Direction Generale'],
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
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $directionIds = DB::table('directions')
            ->whereIn('code', array_column($directions, 'code'))
            ->pluck('id', 'code')
            ->all();

        $services = [
            ['direction_code' => 'DS', 'code' => 'DS-SEB', 'libelle' => 'Service Etudiants Boursiers'],
            ['direction_code' => 'DS', 'code' => 'DS-SENB', 'libelle' => 'Service Etudiants Non Boursiers'],
            ['direction_code' => 'DS', 'code' => 'DS-PCZ', 'libelle' => 'Pool Charges de Zones'],
            ['direction_code' => 'DS', 'code' => 'DS-PGCZ', 'libelle' => 'Pool Gestionnaires / Charges de Zones'],
            ['direction_code' => 'DS', 'code' => 'DS-RP', 'libelle' => 'Responsables de Poles'],
            ['direction_code' => 'DS', 'code' => 'DS-SP', 'libelle' => 'Service Planification'],
            ['direction_code' => 'DS', 'code' => 'DS-PGDS', 'libelle' => 'Pool Gestion Documentaire et Statistiques'],
            ['direction_code' => 'DS', 'code' => 'DS-GP', 'libelle' => 'Gestion des Partenariats'],

            ['direction_code' => 'DSIC', 'code' => 'DSIC-SCRP', 'libelle' => 'Service Communication et Relations Publiques'],
            ['direction_code' => 'DSIC', 'code' => 'DSIC-SSIRS', 'libelle' => 'Service Systemes d Information Reseaux et Securite'],
            ['direction_code' => 'DSIC', 'code' => 'DSIC-SGDS', 'libelle' => 'Service Gestion Documentaire et Statistiques'],

            ['direction_code' => 'DAF', 'code' => 'DAF-SAJRH', 'libelle' => 'Service des Affaires Juridiques Administratives et RH'],
            ['direction_code' => 'DAF', 'code' => 'DAF-SFC', 'libelle' => 'Service Financier et Comptable'],
            ['direction_code' => 'DAF', 'code' => 'DAF-SAMG', 'libelle' => 'Service Approvisionnement et Moyens Generaux'],
            ['direction_code' => 'DAF', 'code' => 'DAF-BV', 'libelle' => 'Bureau Voyage'],
            ['direction_code' => 'DAF', 'code' => 'DAF-PAL', 'libelle' => 'Pool Agents de Liaison'],

            ['direction_code' => 'DGA', 'code' => 'DGA-SDGA', 'libelle' => 'Secretariat Particulier'],
            ['direction_code' => 'DGA', 'code' => 'DGA-CCRP', 'libelle' => 'Pool Charges de Communication et Relations Publiques'],

            ['direction_code' => 'DG', 'code' => 'DG-SCIQ', 'libelle' => 'Service Controle Interne et Qualite'],
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
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $serviceRows = DB::table('services')
            ->join('directions', 'directions.id', '=', 'services.direction_id')
            ->whereIn('directions.code', array_keys($directionIds))
            ->get(['services.id', 'services.code', 'directions.code as direction_code']);

        $serviceByCode = [];
        foreach ($serviceRows as $row) {
            $serviceByCode[(string) $row->code] = [
                'id' => (int) $row->id,
                'direction_code' => (string) $row->direction_code,
            ];
        }

        $users = [
            ['name' => 'Chef de service Etudiants Boursiers', 'matricule' => 'A1-01', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DS', 'service_code' => 'DS-SEB'],
            ['name' => 'Chef de service Etudiants Non Boursiers', 'matricule' => 'A1-02', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DS', 'service_code' => 'DS-SENB'],
            ['name' => 'Directeur de la Scolarite', 'matricule' => 'A1-03', 'role' => User::ROLE_DIRECTION, 'direction_code' => 'DS', 'service_code' => null],
            ['name' => 'Pool Charges de Zones', 'matricule' => 'A1-04', 'role' => User::ROLE_AGENT, 'direction_code' => 'DS', 'service_code' => 'DS-PCZ'],
            ['name' => 'Pool Gestionnaires / Charges de Zones 1', 'matricule' => 'C1-14', 'role' => User::ROLE_AGENT, 'direction_code' => 'DS', 'service_code' => 'DS-PGCZ'],
            ['name' => 'Pool Gestionnaires / Charges de Zones 2', 'matricule' => 'C1-15', 'role' => User::ROLE_AGENT, 'direction_code' => 'DS', 'service_code' => 'DS-PGCZ'],
            ['name' => 'Pool Gestionnaires / Charges de Zones 3', 'matricule' => 'C1-16', 'role' => User::ROLE_AGENT, 'direction_code' => 'DS', 'service_code' => 'DS-PGCZ'],
            ['name' => 'Pool Gestionnaires / Charges de Zones 4', 'matricule' => 'C1-17', 'role' => User::ROLE_AGENT, 'direction_code' => 'DS', 'service_code' => 'DS-PGCZ'],
            ['name' => 'Responsables de Poles', 'matricule' => 'C1-13', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DS', 'service_code' => 'DS-RP'],
            ['name' => 'Chef de service Planification', 'matricule' => 'C1-18', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DS', 'service_code' => 'DS-SP'],
            ['name' => 'Pool Gestion Documentaire et Statistiques', 'matricule' => 'C1-12', 'role' => User::ROLE_AGENT, 'direction_code' => 'DS', 'service_code' => 'DS-PGDS'],
            ['name' => 'Gestionnaire des Partenariats', 'matricule' => 'C1-19', 'role' => User::ROLE_AGENT, 'direction_code' => 'DS', 'service_code' => 'DS-GP'],

            ['name' => 'Gestionnaire Communication et Relations Publiques', 'matricule' => 'B1-05', 'role' => User::ROLE_AGENT, 'direction_code' => 'DSIC', 'service_code' => 'DSIC-SCRP'],
            ['name' => 'Chef de service Communication et Relations Publiques', 'matricule' => 'B1-10', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DSIC', 'service_code' => 'DSIC-SCRP'],
            ['name' => 'Directeur des Systemes d Information et de la Communication', 'matricule' => 'B1-07', 'role' => User::ROLE_DIRECTION, 'direction_code' => 'DSIC', 'service_code' => null],
            ['name' => 'Chef de service Systeme d Information Reseaux et Securite', 'matricule' => 'B1-11', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DSIC', 'service_code' => 'DSIC-SSIRS'],
            ['name' => 'Pool Systeme d Information Reseaux et Securite', 'matricule' => 'B1-08', 'role' => User::ROLE_AGENT, 'direction_code' => 'DSIC', 'service_code' => 'DSIC-SSIRS'],
            ['name' => 'Charge du Parc Informatique', 'matricule' => 'B1-06', 'role' => User::ROLE_AGENT, 'direction_code' => 'DSIC', 'service_code' => 'DSIC-SSIRS'],
            ['name' => 'Chef de service Gestion Documentaire et Statistiques', 'matricule' => 'B1-09', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DSIC', 'service_code' => 'DSIC-SGDS'],

            ['name' => 'Directeur Administratif et Financier', 'matricule' => 'B2-07', 'role' => User::ROLE_DIRECTION, 'direction_code' => 'DAF', 'service_code' => null],
            ['name' => 'Chef de service Affaires Juridiques Administratives et RH', 'matricule' => 'B2-05', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DAF', 'service_code' => 'DAF-SAJRH'],
            ['name' => 'Gestionnaires du Personnel', 'matricule' => 'C2-11', 'role' => User::ROLE_AGENT, 'direction_code' => 'DAF', 'service_code' => 'DAF-SAJRH'],
            ['name' => 'Chef de service Financier et Comptable', 'matricule' => 'B2-06', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DAF', 'service_code' => 'DAF-SFC'],
            ['name' => 'Pool Gestionnaires de Bourses 1', 'matricule' => 'B2-04', 'role' => User::ROLE_AGENT, 'direction_code' => 'DAF', 'service_code' => 'DAF-SFC'],
            ['name' => 'Pool Gestionnaires de Bourses 2', 'matricule' => 'C2-09', 'role' => User::ROLE_AGENT, 'direction_code' => 'DAF', 'service_code' => 'DAF-SFC'],
            ['name' => 'Chef de service Approvisionnement et Moyens Generaux', 'matricule' => 'C2-13', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DAF', 'service_code' => 'DAF-SAMG'],
            ['name' => 'Pool Approvisionnement et Moyens Generaux', 'matricule' => 'C2-10', 'role' => User::ROLE_AGENT, 'direction_code' => 'DAF', 'service_code' => 'DAF-SAMG'],
            ['name' => 'Bureau Voyage', 'matricule' => 'C2-08', 'role' => User::ROLE_AGENT, 'direction_code' => 'DAF', 'service_code' => 'DAF-BV'],
            ['name' => 'Pool Agents de Liaison', 'matricule' => 'C2-12', 'role' => User::ROLE_AGENT, 'direction_code' => 'DAF', 'service_code' => 'DAF-PAL'],

            ['name' => 'Directeur General Adjoint', 'matricule' => 'A2-02', 'role' => User::ROLE_DIRECTION, 'direction_code' => 'DGA', 'service_code' => null],
            ['name' => 'Secretariat Particulier DGA', 'matricule' => 'A2-01', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DGA', 'service_code' => 'DGA-SDGA'],
            ['name' => 'Pool Charges de Communication et Relations Publiques', 'matricule' => 'A2-03', 'role' => User::ROLE_AGENT, 'direction_code' => 'DGA', 'service_code' => 'DGA-CCRP'],

            ['name' => 'Chef de service Controle Interne et Qualite', 'matricule' => 'B3-03', 'role' => User::ROLE_SERVICE, 'direction_code' => 'DG', 'service_code' => 'DG-SCIQ'],
            ['name' => 'Pool Controle Interne et Qualite', 'matricule' => 'B3-07', 'role' => User::ROLE_AGENT, 'direction_code' => 'DG', 'service_code' => 'DG-SCIQ'],
            ['name' => 'Charge d Etudes Controleur Interne', 'matricule' => 'B3-08', 'role' => User::ROLE_AGENT, 'direction_code' => 'DG', 'service_code' => 'DG-SCIQ'],
            ['name' => 'Charges d Etudes', 'matricule' => 'B3-04', 'role' => User::ROLE_AGENT, 'direction_code' => 'DG', 'service_code' => null],
            ['name' => 'Conseiller Technique du Directeur General', 'matricule' => 'B3-05', 'role' => User::ROLE_AGENT, 'direction_code' => 'DG', 'service_code' => null],
            ['name' => 'Conseiller Technique Charge des Affaires Academiques', 'matricule' => 'B3-06', 'role' => User::ROLE_AGENT, 'direction_code' => 'DG', 'service_code' => null],
        ];

        $targetEmails = [];
        foreach ($users as $user) {
            $email = strtolower($user['matricule']) . '@anbg.test';
            $targetEmails[] = $email;

            $directionCode = $user['direction_code'];
            $directionId = isset($directionIds[$directionCode]) ? (int) $directionIds[$directionCode] : null;

            $serviceId = null;
            if ($user['service_code'] !== null && isset($serviceByCode[$user['service_code']])) {
                $service = $serviceByCode[$user['service_code']];
                if ((string) $service['direction_code'] === (string) $directionCode) {
                    $serviceId = (int) $service['id'];
                }
            }

            DB::table('users')->updateOrInsert(
                ['email' => $email],
                [
                    'name' => $user['name'],
                    'password' => Hash::make('Pass@12345'),
                    'role' => $user['role'],
                    'is_agent' => $user['role'] === User::ROLE_AGENT,
                    'agent_matricule' => $user['matricule'],
                    'agent_fonction' => $user['name'],
                    'agent_telephone' => null,
                    'direction_id' => $directionId,
                    'service_id' => $serviceId,
                    'email_verified_at' => $now,
                    'password_changed_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        // Preserve existing users outside this sync list to avoid accidental account loss.
    }
}
