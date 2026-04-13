<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SyncOrgUsersPreservingPasswordsSeeder extends AnbgOrganizationSeeder
{
    public function run(): void
    {
        $now = now();
        $defaultPassword = Hash::make('Pass@12345');

        foreach ($this->directions() as $direction) {
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
            ->whereIn('code', array_column($this->directions(), 'code'))
            ->pluck('id', 'code')
            ->mapWithKeys(static fn ($id, $code): array => [(string) $code => (int) $id])
            ->all();

        foreach ($this->services() as $service) {
            $directionId = $directionIds[$service['direction_code']] ?? null;
            if (! is_int($directionId) || $directionId <= 0) {
                continue;
            }

            DB::table('services')->updateOrInsert(
                [
                    'direction_id' => $directionId,
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

        $serviceIds = DB::table('services')
            ->join('directions', 'directions.id', '=', 'services.direction_id')
            ->whereIn('directions.code', array_keys($directionIds))
            ->get(['directions.code as direction_code', 'services.code', 'services.id'])
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->direction_code.'.'.(string) $row->code => (int) $row->id,
            ])
            ->all();

        foreach ($this->users() as $index => $user) {
            $email = strtolower((string) $user['email']);
            $directionId = $directionIds[$user['direction_code']] ?? null;
            $serviceId = null;

            if ($user['service_code'] !== null) {
                $serviceId = $serviceIds[$user['direction_code'].'.'.$user['service_code']] ?? null;
            }

            $existingUser = DB::table('users')
                ->where('email', $email)
                ->first([
                    'id',
                    'created_at',
                    'email_verified_at',
                    'password',
                    'password_changed_at',
                ]);

            $payload = [
                'name' => $user['name'],
                'role' => $user['role'],
                'is_agent' => $user['role'] === User::ROLE_AGENT,
                'agent_matricule' => $this->buildMatricule($user['role'], $index + 1),
                'agent_fonction' => $user['fonction'],
                'agent_telephone' => null,
                'direction_id' => $directionId,
                'service_id' => $serviceId,
                'updated_at' => $now,
            ];

            if ($existingUser !== null) {
                $payload['email_verified_at'] = $existingUser->email_verified_at ?? $now;

                DB::table('users')
                    ->where('id', $existingUser->id)
                    ->update($payload);

                continue;
            }

            DB::table('users')->insert([
                'name' => $user['name'],
                'email' => $email,
                'password' => $defaultPassword,
                'role' => $user['role'],
                'is_agent' => $user['role'] === User::ROLE_AGENT,
                'agent_matricule' => $this->buildMatricule($user['role'], $index + 1),
                'agent_fonction' => $user['fonction'],
                'agent_telephone' => null,
                'direction_id' => $directionId,
                'service_id' => $serviceId,
                'email_verified_at' => $now,
                'password_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
