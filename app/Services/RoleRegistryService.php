<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RoleRegistryService
{
    private const VERSION_LIMIT = 20;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $resolved = null;

    private ?bool $tableAvailable = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function systemRoles(): array
    {
        return [
            User::ROLE_SUPER_ADMIN => ['label' => 'Super Admin', 'base_role' => User::ROLE_SUPER_ADMIN, 'system' => true],
            User::ROLE_ADMIN => ['label' => 'Administrateur', 'base_role' => User::ROLE_ADMIN, 'system' => true],
            User::ROLE_DG => ['label' => 'DG', 'base_role' => User::ROLE_DG, 'system' => true],
            User::ROLE_PLANIFICATION => ['label' => 'Planification', 'base_role' => User::ROLE_PLANIFICATION, 'system' => true],
            User::ROLE_DIRECTION => ['label' => 'Direction', 'base_role' => User::ROLE_DIRECTION, 'system' => true],
            User::ROLE_SERVICE => ['label' => 'Service', 'base_role' => User::ROLE_SERVICE, 'system' => true],
            User::ROLE_AGENT => ['label' => 'Agent', 'base_role' => User::ROLE_AGENT, 'system' => true],
            User::ROLE_CABINET => ['label' => 'Cabinet', 'base_role' => User::ROLE_CABINET, 'system' => true],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function customRoles(): array
    {
        return collect($this->allRoles())
            ->reject(fn (array $role): bool => (bool) ($role['system'] ?? false))
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allRoles(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $roles = $this->systemRoles();

        if ($this->hasSettingsTable()) {
            $stored = PlatformSetting::query()
                ->where('group', 'role_registry')
                ->where('key', 'custom_roles')
                ->value('value');

            $decoded = json_decode((string) $stored, true);
            if (is_array($decoded)) {
                foreach ($this->sanitizeCustomRoles($decoded) as $code => $definition) {
                    $roles[$code] = $definition;
                }
            }
        }

        return $this->resolved = $roles;
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return collect($this->allRoles())
            ->mapWithKeys(fn (array $definition, string $code): array => [
                $code => (string) ($definition['label'] ?? Str::headline($code)),
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->allRoles());
    }

    public function isCustomRole(string $roleCode): bool
    {
        $role = $this->allRoles()[$roleCode] ?? null;

        return is_array($role) && ! (bool) ($role['system'] ?? false);
    }

    public function baseRole(string $roleCode): string
    {
        $role = $this->allRoles()[$roleCode] ?? null;
        if (is_array($role) && is_string($role['base_role'] ?? null)) {
            return (string) $role['base_role'];
        }

        return array_key_exists($roleCode, $this->systemRoles())
            ? $roleCode
            : User::ROLE_AGENT;
    }

    public function label(string $roleCode): string
    {
        return $this->labels()[$roleCode] ?? Str::headline($roleCode);
    }

    /**
     * @param  array<int, array<string, mixed>|mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    public function updateCustomRoles(array $payload, ?User $actor = null): array
    {
        $roles = $this->sanitizeCustomRoles($payload);

        $this->persistCustomRoles($roles, $actor);

        return $roles;
    }

    /**
     * @return array{code:string, roles: array<string, array<string, mixed>>}
     */
    public function duplicateRole(
        string $sourceRole,
        string $targetCode,
        string $targetLabel,
        ?string $description = null,
        ?User $actor = null
    ): array {
        $sourceRole = trim($sourceRole);
        $source = $this->allRoles()[$sourceRole] ?? null;
        if (! is_array($source)) {
            abort(422, 'Role source introuvable.');
        }

        $normalizedCode = $this->normalizeRoleCode($targetCode);
        if ($normalizedCode === '') {
            abort(422, 'Code de role invalide.');
        }

        if (array_key_exists($normalizedCode, $this->allRoles())) {
            abort(422, 'Ce code de role existe deja.');
        }

        $roles = $this->customRoles();
        $roles[$normalizedCode] = [
            'label' => Str::limit(trim($targetLabel) !== '' ? trim($targetLabel) : ((string) ($source['label'] ?? Str::headline($normalizedCode))).' copie', 80, ''),
            'base_role' => (string) ($source['base_role'] ?? User::ROLE_AGENT),
            'description' => Str::limit(trim((string) ($description ?? ($source['description'] ?? ''))), 255, ''),
            'active' => true,
            'system' => false,
        ];

        $this->persistCustomRoles($roles, $actor);

        return [
            'code' => $normalizedCode,
            'roles' => $roles,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(): array
    {
        if (! $this->hasSettingsTable()) {
            return [];
        }

        $stored = PlatformSetting::query()
            ->where('group', 'role_registry')
            ->where('key', 'history')
            ->value('value');

        $decoded = json_decode((string) $stored, true);

        return is_array($decoded) ? $this->sanitizeVersionHistory($decoded) : [];
    }

    /**
     * @param  array<string, array<int, string>>  $customPermissions
     * @return list<array<string, mixed>>
     */
    public function recordVersionSnapshot(
        array $customPermissions = [],
        ?User $actor = null,
        string $action = 'update',
        ?string $note = null
    ): array {
        $history = $this->versions();
        array_unshift($history, [
            'id' => (string) Str::uuid(),
            'created_at' => now()->toIso8601String(),
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->name,
            'action' => trim($action) !== '' ? trim($action) : 'update',
            'note' => Str::limit(trim((string) ($note ?? '')), 255, ''),
            'roles' => array_values($this->serializeRoles($this->customRoles())),
            'permissions' => $this->sanitizeSnapshotPermissions($customPermissions),
        ]);

        $history = array_slice($this->sanitizeVersionHistory($history), 0, self::VERSION_LIMIT);

        PlatformSetting::query()->updateOrCreate(
            ['group' => 'role_registry', 'key' => 'history'],
            [
                'value' => json_encode($history, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_by' => $actor?->id,
            ]
        );

        return $history;
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreVersion(string $versionId, ?User $actor = null): array
    {
        $versionId = trim($versionId);
        $version = collect($this->versions())->first(fn (array $entry): bool => (string) ($entry['id'] ?? '') === $versionId);

        if (! is_array($version)) {
            abort(404, 'Version de role introuvable.');
        }

        $roles = $this->sanitizeCustomRoles($version['roles'] ?? []);
        $this->persistCustomRoles($roles, $actor);

        return $version;
    }

    public function flush(): void
    {
        $this->resolved = null;
        $this->tableAvailable = null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $roles
     */
    private function persistCustomRoles(array $roles, ?User $actor = null): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['group' => 'role_registry', 'key' => 'custom_roles'],
            [
                'value' => json_encode(array_values($this->serializeRoles($roles)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_by' => $actor?->id,
            ]
        );

        $this->flush();
    }

    /**
     * @param  array<int, array<string, mixed>|mixed>  $roles
     * @return array<string, array<string, mixed>>
     */
    private function sanitizeCustomRoles(array $roles): array
    {
        $allowedBases = array_keys($this->systemRoles());

        return collect($roles)
            ->filter(fn ($definition): bool => is_array($definition))
            ->map(function (array $definition) use ($allowedBases): ?array {
                $active = filter_var($definition['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $code = $this->normalizeRoleCode((string) ($definition['code'] ?? ''));
                $label = trim((string) ($definition['label'] ?? ''));
                $baseRole = trim((string) ($definition['base_role'] ?? ''));

                if (! $active && $code === '' && $label === '') {
                    return null;
                }

                if ($code === '' || $label === '' || ! in_array($baseRole, $allowedBases, true)) {
                    return null;
                }

                if (array_key_exists($code, $this->systemRoles())) {
                    return null;
                }

                return [
                    'code' => Str::limit($code, 64, ''),
                    'label' => Str::limit($label, 80, ''),
                    'base_role' => $baseRole,
                    'description' => Str::limit(trim((string) ($definition['description'] ?? '')), 255, ''),
                    'active' => $active,
                    'system' => false,
                ];
            })
            ->filter(fn (?array $definition): bool => $definition !== null && (bool) ($definition['active'] ?? false))
            ->unique(fn (array $definition): string => (string) $definition['code'])
            ->mapWithKeys(fn (array $definition): array => [
                (string) $definition['code'] => Arr::except($definition, ['code']),
            ])
            ->all();
    }

    private function normalizeRoleCode(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9._-]+/', '_')
            ->trim('_-.')
            ->value();
    }

    /**
     * @param  array<string, array<string, mixed>>  $roles
     * @return array<string, array<string, mixed>>
     */
    private function serializeRoles(array $roles): array
    {
        return collect($roles)
            ->mapWithKeys(fn (array $definition, string $code): array => [
                $code => [
                    'code' => $code,
                    'label' => (string) ($definition['label'] ?? Str::headline($code)),
                    'base_role' => (string) ($definition['base_role'] ?? User::ROLE_AGENT),
                    'description' => (string) ($definition['description'] ?? ''),
                    'active' => (bool) ($definition['active'] ?? true),
                    'system' => false,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $permissions
     * @return array<string, array<int, string>>
     */
    private function sanitizeSnapshotPermissions(array $permissions): array
    {
        return collect($permissions)
            ->filter(fn ($rows, $code): bool => is_string($code) && trim($code) !== '' && is_array($rows))
            ->map(function (array $rows): array {
                return collect($rows)
                    ->map(fn ($permission): string => trim((string) $permission))
                    ->filter(fn (string $permission): bool => $permission !== '')
                    ->unique()
                    ->values()
                    ->all();
            })
            ->all();
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return list<array<string, mixed>>
     */
    private function sanitizeVersionHistory(array $entries): array
    {
        return collect($entries)
            ->filter(fn ($entry): bool => is_array($entry))
            ->map(function (array $entry): ?array {
                $id = trim((string) ($entry['id'] ?? ''));
                if ($id === '') {
                    return null;
                }

                return [
                    'id' => $id,
                    'created_at' => trim((string) ($entry['created_at'] ?? now()->toIso8601String())),
                    'actor_id' => isset($entry['actor_id']) ? (int) $entry['actor_id'] : null,
                    'actor_label' => trim((string) ($entry['actor_label'] ?? '')),
                    'action' => trim((string) ($entry['action'] ?? 'update')),
                    'note' => Str::limit(trim((string) ($entry['note'] ?? '')), 255, ''),
                    'roles' => array_values($this->serializeRoles($this->sanitizeCustomRoles($entry['roles'] ?? []))),
                    'permissions' => $this->sanitizeSnapshotPermissions(
                        is_array($entry['permissions'] ?? null) ? $entry['permissions'] : []
                    ),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
    private function hasSettingsTable(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            return $this->tableAvailable = Schema::hasTable('platform_settings');
        } catch (\Throwable) {
            return $this->tableAvailable = false;
        }
    }
}



