<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

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

        $syncedEmails = [];

        foreach ($this->users() as $index => $rawUser) {
            $user = $this->normalizeUserOrganization($rawUser);
            $email = strtolower((string) $user['email']);
            $syncedEmails[] = $email;
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

            if (Schema::hasColumn('users', 'custom_role_code')) {
                $payload['custom_role_code'] = null;
            }

            if (Schema::hasColumn('users', 'is_active')) {
                $payload['is_active'] = true;
            }

            if (Schema::hasColumn('users', 'deleted_at')) {
                $payload['deleted_at'] = null;
            }

            if (Schema::hasColumn('users', 'unite_dg_id')) {
                $payload['unite_dg_id'] = null;
            }

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

            DB::table('users')->insert(array_merge($payload, [
                'email' => $email,
                'password' => Hash::make($temporaryPassword),
                'email_verified_at' => $now,
                'password_changed_at' => $passwordChangedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        if (Schema::hasColumn('users', 'is_active') && $syncedEmails !== []) {
            DB::table('users')
                ->where(function ($query): void {
                    $query->where('email', 'like', '%@anbg.ga')
                        ->orWhere('email', 'like', '%@anbg.test');
                })
                ->whereNotIn('email', $syncedEmails)
                ->update([
                    'is_active' => false,
                    'updated_at' => $now,
                ]);

            $this->deleteInactiveOrganizationUsers($now);
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

    private function deleteInactiveOrganizationUsers(mixed $now): void
    {
        if (! Schema::hasColumn('users', 'is_active')) {
            return;
        }

        $query = DB::table('users')
            ->where('is_active', false)
            ->where(function ($query): void {
                $query->where('email', 'like', '%@anbg.ga')
                    ->orWhere('email', 'like', '%@anbg.test');
            });

        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $deleted = 0;
        $skipped = 0;

        $query->orderBy('id')
            ->get(['id', 'service_id', 'direction_id', 'email'])
            ->each(function ($inactiveUser) use ($now, &$deleted, &$skipped): void {
                $replacementUserId = $this->replacementActiveUserId($inactiveUser);

                if ($replacementUserId === null) {
                    $skipped++;

                    return;
                }

                $this->transferUserActions((int) $inactiveUser->id, $replacementUserId, $now);
                $this->deleteInactiveUser((int) $inactiveUser->id, $now);
                $deleted++;
            });

        if ($deleted > 0) {
            $this->command?->info("Comptes inactifs supprimes: {$deleted}. Actions transferees vers un compte actif du meme service.");
        }

        if ($skipped > 0) {
            $this->command?->warn("Comptes inactifs conserves faute de compte actif dans le meme service: {$skipped}.");
        }
    }

    private function replacementActiveUserId(object $inactiveUser): ?int
    {
        if ($inactiveUser->service_id === null) {
            return null;
        }

        $query = DB::table('users')
            ->where('id', '!=', (int) $inactiveUser->id)
            ->where('service_id', (int) $inactiveUser->service_id)
            ->where('is_active', true);

        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $replacementId = $query
            ->orderByRaw(
                "CASE WHEN role = ? THEN 0 WHEN role = ? THEN 1 ELSE 2 END",
                [User::ROLE_AGENT, User::ROLE_SERVICE]
            )
            ->orderBy('id')
            ->value('id');

        return $replacementId !== null ? (int) $replacementId : null;
    }

    private function transferUserActions(int $fromUserId, int $toUserId, mixed $now): void
    {
        if (Schema::hasTable('actions')) {
            DB::table('actions')
                ->where('responsable_id', $fromUserId)
                ->update([
                    'responsable_id' => $toUserId,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('action_responsables')) {
            DB::table('action_responsables')
                ->where('user_id', $fromUserId)
                ->orderBy('id')
                ->get(['id', 'action_id', 'is_primary'])
                ->each(function ($assignment) use ($toUserId, $now): void {
                    $existing = DB::table('action_responsables')
                        ->where('action_id', (int) $assignment->action_id)
                        ->where('user_id', $toUserId)
                        ->first(['id', 'is_primary']);

                    if ($existing !== null) {
                        if ((bool) $assignment->is_primary && ! (bool) $existing->is_primary) {
                            DB::table('action_responsables')
                                ->where('id', (int) $existing->id)
                                ->update([
                                    'is_primary' => true,
                                    'updated_at' => $now,
                                ]);
                        }

                        DB::table('action_responsables')
                            ->where('id', (int) $assignment->id)
                            ->delete();

                        return;
                    }

                    DB::table('action_responsables')
                        ->where('id', (int) $assignment->id)
                        ->update([
                            'user_id' => $toUserId,
                            'updated_at' => $now,
                        ]);
                });
        }

        if (Schema::hasTable('sous_actions') && Schema::hasColumn('sous_actions', 'agent_id')) {
            DB::table('sous_actions')
                ->where('agent_id', $fromUserId)
                ->update([
                    'agent_id' => $toUserId,
                    'updated_at' => $now,
                ]);
        }
    }

    private function deleteInactiveUser(int $userId, mixed $now): void
    {
        if (Schema::hasColumn('users', 'deleted_at')) {
            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'is_active' => false,
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('users')->where('id', $userId)->delete();
    }
}
