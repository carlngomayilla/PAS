<?php

namespace Database\Seeders;

use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $password = Hash::make('Pass@12345');

        $daf = Direction::query()->where('code', 'DAF')->firstOrFail();
        $dsi = Direction::query()->where('code', 'DSI')->firstOrFail();
        $dpp = Direction::query()->where('code', 'DPP')->firstOrFail();

        $sfin = Service::query()->where('direction_id', $daf->id)->where('code', 'SFIN')->firstOrFail();
        $srh = Service::query()->where('direction_id', $daf->id)->where('code', 'SRH')->firstOrFail();
        $sdev = Service::query()->where('direction_id', $dsi->id)->where('code', 'SDEV')->firstOrFail();
        $sinf = Service::query()->where('direction_id', $dsi->id)->where('code', 'SINF')->firstOrFail();
        $spla = Service::query()->where('direction_id', $dpp->id)->where('code', 'SPLA')->firstOrFail();
        $ssev = Service::query()->where('direction_id', $dpp->id)->where('code', 'SSEV')->firstOrFail();

        $users = [
            [
                'name' => 'Administrateur ANBG',
                'email' => 'admin@anbg.test',
                'role' => User::ROLE_ADMIN,
                'direction_id' => null,
                'service_id' => null,
            ],
            [
                'name' => 'Directeur General',
                'email' => 'dg@anbg.test',
                'role' => User::ROLE_DG,
                'direction_id' => null,
                'service_id' => null,
            ],
            [
                'name' => 'Cellule Planification',
                'email' => 'planification@anbg.test',
                'role' => User::ROLE_PLANIFICATION,
                'direction_id' => $dpp->id,
                'service_id' => null,
            ],
            [
                'name' => 'Cabinet DG',
                'email' => 'cabinet@anbg.test',
                'role' => User::ROLE_CABINET,
                'direction_id' => null,
                'service_id' => null,
            ],
            [
                'name' => 'Directeur DAF',
                'email' => 'daf.direction@anbg.test',
                'role' => User::ROLE_DIRECTION,
                'direction_id' => $daf->id,
                'service_id' => null,
            ],
            [
                'name' => 'Directeur DSI',
                'email' => 'dsi.direction@anbg.test',
                'role' => User::ROLE_DIRECTION,
                'direction_id' => $dsi->id,
                'service_id' => null,
            ],
            [
                'name' => 'Directeur DPP',
                'email' => 'dpp.direction@anbg.test',
                'role' => User::ROLE_DIRECTION,
                'direction_id' => $dpp->id,
                'service_id' => null,
            ],
            [
                'name' => 'Service Finances',
                'email' => 'finance.service@anbg.test',
                'role' => User::ROLE_SERVICE,
                'is_agent' => true,
                'direction_id' => $daf->id,
                'service_id' => $sfin->id,
                'agent_matricule' => 'AG-SFIN-001',
                'agent_fonction' => 'Agent execution budgetaire',
                'agent_telephone' => '+241 00 00 01',
            ],
            [
                'name' => 'Service RH',
                'email' => 'rh.service@anbg.test',
                'role' => User::ROLE_SERVICE,
                'is_agent' => true,
                'direction_id' => $daf->id,
                'service_id' => $srh->id,
                'agent_matricule' => 'AG-SRH-001',
                'agent_fonction' => 'Agent suivi RH',
                'agent_telephone' => '+241 00 00 02',
            ],
            [
                'name' => 'Service Developpement',
                'email' => 'dev.service@anbg.test',
                'role' => User::ROLE_SERVICE,
                'is_agent' => true,
                'direction_id' => $dsi->id,
                'service_id' => $sdev->id,
                'agent_matricule' => 'AG-SDEV-001',
                'agent_fonction' => 'Agent execution applicative',
                'agent_telephone' => '+241 00 00 03',
            ],
            [
                'name' => 'Service Infrastructure',
                'email' => 'infra.service@anbg.test',
                'role' => User::ROLE_SERVICE,
                'is_agent' => true,
                'direction_id' => $dsi->id,
                'service_id' => $sinf->id,
                'agent_matricule' => 'AG-SINF-001',
                'agent_fonction' => 'Agent exploitation infrastructure',
                'agent_telephone' => '+241 00 00 04',
            ],
            [
                'name' => 'Service Planification',
                'email' => 'planif.service@anbg.test',
                'role' => User::ROLE_SERVICE,
                'is_agent' => true,
                'direction_id' => $dpp->id,
                'service_id' => $spla->id,
                'agent_matricule' => 'AG-SPLA-001',
                'agent_fonction' => 'Agent planification operationnelle',
                'agent_telephone' => '+241 00 00 05',
            ],
            [
                'name' => 'Service Suivi Evaluation',
                'email' => 'suivi.service@anbg.test',
                'role' => User::ROLE_SERVICE,
                'is_agent' => true,
                'direction_id' => $dpp->id,
                'service_id' => $ssev->id,
                'agent_matricule' => 'AG-SSEV-001',
                'agent_fonction' => 'Agent suivi et evaluation',
                'agent_telephone' => '+241 00 00 06',
            ],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => $password,
                    'role' => $user['role'],
                    'is_agent' => (bool) ($user['is_agent'] ?? ((string) $user['role'] === User::ROLE_AGENT)),
                    'agent_matricule' => $user['agent_matricule'] ?? null,
                    'agent_fonction' => $user['agent_fonction'] ?? null,
                    'agent_telephone' => $user['agent_telephone'] ?? null,
                    'direction_id' => $user['direction_id'],
                    'service_id' => $user['service_id'],
                    'email_verified_at' => now(),
                    'password_changed_at' => $now,
                ]
            );
        }
    }
}
