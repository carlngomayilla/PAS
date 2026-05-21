<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SyncOrgUsersPreservingPasswordsSeeder extends AnbgOrganizationSeeder
{
    /**
     * @var array<int, array{email:string, name:string, matricule:?string, temporary_password:string}>
     */
    private array $generatedCredentialsForNewUsers = [];

    public function run(): void
    {
        $now = now();
        $passwordPolicy = app(PasswordPolicyService::class);

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
                $this->servicePayload($service, $now)
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

        foreach ($this->users() as $index => $rawUser) {
            $user = $this->normalizeUserOrganization($rawUser);
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
                'agent_matricule' => $this->resolveMatricule($user, $index + 1),
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

            // A08 — Nouveau compte : mdp aleatoire + password_changed_at NULL
            // (force renouvellement au 1er login). Les credentials sont affiches
            // en fin de run pour distribution.
            // En tests on conserve le mdp fixture `Pass@12345` pour que les tests
            // qui se loggent sur les comptes seedes continuent a marcher.
            $matricule = $this->resolveMatricule($user, $index + 1);

            if (app()->environment('testing')) {
                $temporaryPassword = 'Pass@12345';
                $passwordChangedAt = $now;
            } else {
                $temporaryPassword = $passwordPolicy->generateInitialPassword();
                $passwordChangedAt = null;

                $this->generatedCredentialsForNewUsers[] = [
                    'email' => $email,
                    'name' => (string) $user['name'],
                    'matricule' => $matricule,
                    'temporary_password' => $temporaryPassword,
                ];
            }

            DB::table('users')->insert([
                'name' => $user['name'],
                'email' => $email,
                'password' => Hash::make($temporaryPassword),
                'role' => $user['role'],
                'is_agent' => $user['role'] === User::ROLE_AGENT,
                'agent_matricule' => $matricule,
                'agent_fonction' => $user['fonction'],
                'agent_telephone' => null,
                'direction_id' => $directionId,
                'service_id' => $serviceId,
                'email_verified_at' => $now,
                'password_changed_at' => $passwordChangedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->deleteLegacyOrganizationEntries($now);

        $this->reportNewUserCredentials();
    }

    protected function reportNewUserCredentials(): void
    {
        if ($this->generatedCredentialsForNewUsers === []) {
            return;
        }

        $command = $this->command ?? null;
        if ($command === null) {
            return;
        }

        $command->newLine();
        $command->warn('A08 — '.count($this->generatedCredentialsForNewUsers).' nouveau(x) compte(s) cree(s) avec un mot de passe temporaire :');
        $command->warn('Les comptes deja existants ont conserve leur mot de passe.');
        $command->warn('A transmettre par un canal sur, puis a renouveler au 1er login.');
        $command->newLine();

        $command->table(
            ['Email', 'Nom', 'Matricule', 'Mot de passe temporaire'],
            array_map(
                static fn (array $row): array => [
                    $row['email'],
                    $row['name'],
                    $row['matricule'] ?? '-',
                    $row['temporary_password'],
                ],
                $this->generatedCredentialsForNewUsers
            )
        );
    }
}
