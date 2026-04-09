<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class RolePermissionSettings
{
    public function __construct(
        private readonly RoleRegistryService $roleRegistry
    ) {
    }

    /**
     * @var array<string, array<int, string>>|null
     */
    private ?array $resolved = null;

    private ?bool $tableAvailable = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function permissions(): array
    {
        return [
            'scope.global.read' => ['group' => 'Portee', 'label' => 'Lecture globale', 'description' => 'Lecture transverse du portefeuille.', 'sensitive' => true],
            'scope.global.write' => ['group' => 'Portee', 'label' => 'Ecriture globale', 'description' => 'Ecriture transverse sur le portefeuille.', 'sensitive' => true],
            'planning.read' => ['group' => 'Planification', 'label' => 'Lire la planification', 'description' => 'Voir PAS, PAO, PTA, reporting et alertes.', 'sensitive' => false],
            'planning.write.global' => ['group' => 'Planification', 'label' => 'Ecrire globalement', 'description' => 'Modifier la planification sans limite directionnelle.', 'sensitive' => true],
            'planning.write.direction' => ['group' => 'Planification', 'label' => 'Ecrire en direction', 'description' => 'Modifier la planification de sa direction.', 'sensitive' => false],
            'planning.write.service' => ['group' => 'Planification', 'label' => 'Ecrire en service', 'description' => 'Modifier la planification de son service.', 'sensitive' => false],
            'planning.strategic.manage' => ['group' => 'Planification', 'label' => 'Piloter le strategique', 'description' => 'Gerer les elements strategiques et validations avancees.', 'sensitive' => true],
            'reporting.read' => ['group' => 'Pilotage', 'label' => 'Voir le reporting', 'description' => 'Acceder au hub reporting et aux exports.', 'sensitive' => false],
            'alerts.read' => ['group' => 'Pilotage', 'label' => 'Voir les alertes', 'description' => 'Acceder au centre d alertes.', 'sensitive' => false],
            'referentiel.read' => ['group' => 'Referentiel', 'label' => 'Lire le referentiel', 'description' => 'Consulter directions, services et utilisateurs.', 'sensitive' => false],
            'referentiel.write' => ['group' => 'Referentiel', 'label' => 'Modifier le referentiel', 'description' => 'Creer ou modifier directions et services.', 'sensitive' => true],
            'users.manage' => ['group' => 'Referentiel', 'label' => 'Administrer les utilisateurs', 'description' => 'Creer, modifier ou supprimer des comptes.', 'sensitive' => true],
            'users.manage_roles' => ['group' => 'Referentiel', 'label' => 'Administrer les roles', 'description' => 'Affecter et changer les roles utilisateur.', 'sensitive' => true],
            'delegations.manage' => ['group' => 'Gouvernance', 'label' => 'Gerer les delegations', 'description' => 'Creer et annuler des delegations.', 'sensitive' => true],
            'retention.read' => ['group' => 'Gouvernance', 'label' => 'Voir la retention', 'description' => 'Consulter les vues de retention et archivage.', 'sensitive' => false],
            'retention.manage' => ['group' => 'Gouvernance', 'label' => 'Piloter la retention', 'description' => 'Executer les actions de retention.', 'sensitive' => true],
            'api_docs.read' => ['group' => 'Gouvernance', 'label' => 'Voir l API', 'description' => 'Acceder a la documentation API.', 'sensitive' => false],
            'audit.read' => ['group' => 'Audit', 'label' => 'Voir l audit', 'description' => 'Consulter les journaux d audit.', 'sensitive' => true],
            'messagerie.read' => ['group' => 'Communication', 'label' => 'Utiliser la messagerie', 'description' => 'Acceder a la messagerie et a l organigramme.', 'sensitive' => false],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function roles(): array
    {
        return $this->roleRegistry->labels();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $settings = $this->defaults();

        if ($this->hasSettingsTable()) {
            $stored = PlatformSetting::query()
                ->where('group', 'role_permissions')
                ->pluck('value', 'key')
                ->all();

            foreach ($this->roles() as $role => $label) {
                if ($role === User::ROLE_SUPER_ADMIN) {
                    $settings[$role] = array_keys($this->permissions());
                    continue;
                }

                $storageKey = 'role_permissions_'.$role;
                if (! array_key_exists($storageKey, $stored)) {
                    continue;
                }

                $decoded = json_decode((string) $stored[$storageKey], true);
                if (! is_array($decoded)) {
                    continue;
                }

                $settings[$role] = $this->sanitizePermissionList($decoded);
            }
        }

        return $this->resolved = $settings;
    }

    /**
     * @return array<int, string>
     */
    public function forRole(string $role): array
    {
        $all = $this->all();

        return $all[$role] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function forUser(User $user): array
    {
        $role = $user->effectiveRoleCode();

        if (! array_key_exists($role, $this->roles())) {
            $role = (string) $user->role;
        }

        return $this->forRole($role);
    }

    public function has(User $user, string $permission): bool
    {
        return in_array($permission, $this->forUser($user), true);
    }

    /**
     * @param  array<string, array<int, string>|mixed>  $payload
     * @return array<string, array<int, string>>
     */
    public function update(array $payload, ?User $actor = null): array
    {
        foreach ($this->roles() as $role => $label) {
            $permissions = $role === User::ROLE_SUPER_ADMIN
                ? array_keys($this->permissions())
                : $this->sanitizePermissionList($payload[$role] ?? []);

            PlatformSetting::query()->updateOrCreate(
                ['group' => 'role_permissions', 'key' => 'role_permissions_'.$role],
                [
                    'value' => json_encode($permissions, JSON_UNESCAPED_SLASHES),
                    'updated_by' => $actor?->id,
                ]
            );
        }

        $this->flush();

        return $this->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function defaults(): array
    {
        $defaults = $this->systemDefaults();

        foreach ($this->roleRegistry->customRoles() as $roleCode => $definition) {
            $baseRole = (string) ($definition['base_role'] ?? User::ROLE_AGENT);
            $defaults[$roleCode] = $defaults[$baseRole] ?? [];
        }

        return $defaults;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function systemDefaults(): array
    {
        return [
            User::ROLE_SUPER_ADMIN => array_keys($this->permissions()),
            User::ROLE_ADMIN => [
                'scope.global.read',
                'scope.global.write',
                'planning.read',
                'planning.write.global',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'referentiel.write',
                'users.manage',
                'users.manage_roles',
                'delegations.manage',
                'retention.read',
                'retention.manage',
                'api_docs.read',
                'audit.read',
                'messagerie.read',
            ],
            User::ROLE_DG => [
                'scope.global.read',
                'scope.global.write',
                'planning.read',
                'planning.write.global',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'delegations.manage',
                'retention.read',
                'audit.read',
                'api_docs.read',
                'messagerie.read',
            ],
            User::ROLE_PLANIFICATION => [
                'scope.global.read',
                'scope.global.write',
                'planning.read',
                'planning.write.global',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'referentiel.write',
                'users.manage',
                'users.manage_roles',
                'delegations.manage',
                'retention.read',
                'retention.manage',
                'audit.read',
                'api_docs.read',
                'messagerie.read',
            ],
            User::ROLE_DIRECTION => [
                'planning.read',
                'planning.write.direction',
                'reporting.read',
                'alerts.read',
                'messagerie.read',
            ],
            User::ROLE_SERVICE => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'messagerie.read',
            ],
            User::ROLE_AGENT => [
                'messagerie.read',
            ],
            User::ROLE_CABINET => [
                'scope.global.read',
                'planning.read',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'retention.read',
                'api_docs.read',
                'audit.read',
                'messagerie.read',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function groupedPermissions(): array
    {
        return collect($this->permissions())
            ->map(function (array $definition, string $permission): array {
                return ['code' => $permission] + $definition;
            })
            ->groupBy('group')
            ->map(fn (Collection $rows, string $group): array => [
                'group' => $group,
                'permissions' => $rows->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function flush(): void
    {
        $this->resolved = null;
        $this->tableAvailable = null;
    }

    /**
     * @param  array<int, mixed>|mixed  $permissions
     * @return array<int, string>
     */
    private function sanitizePermissionList(mixed $permissions): array
    {
        $allowed = array_keys($this->permissions());

        return collect(is_array($permissions) ? $permissions : [])
            ->map(fn ($permission): string => trim((string) $permission))
            ->filter(fn (string $permission): bool => in_array($permission, $allowed, true))
            ->unique()
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



