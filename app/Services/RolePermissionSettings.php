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
            'scope.global.read' => ['group' => 'Portée', 'label' => 'Lecture globale', 'description' => 'Lecture transverse du portefeuille.', 'sensitive' => true],
            'scope.global.write' => ['group' => 'Portée', 'label' => 'Écriture globale', 'description' => 'Écriture transverse sur le portefeuille.', 'sensitive' => true],
            'planning.read' => ['group' => 'Planification', 'label' => 'Lire la planification', 'description' => 'Voir PAS, PAO, PTA, reporting et alertes.', 'sensitive' => false],
            'planning.write.global' => ['group' => 'Planification', 'label' => 'Écrire globalement', 'description' => 'Modifier la planification sans limite directionnelle.', 'sensitive' => true],
            'planning.write.direction' => ['group' => 'Planification', 'label' => 'Écrire en direction', 'description' => 'Modifier la planification de sa direction.', 'sensitive' => false],
            'planning.write.service' => ['group' => 'Planification', 'label' => 'Écrire en service', 'description' => 'Modifier la planification de son service.', 'sensitive' => false],
            'planning.strategic.manage' => ['group' => 'Planification', 'label' => 'Piloter le stratégique', 'description' => 'Gérer les elements stratégiques et validations avancees.', 'sensitive' => true],
            'reporting.read' => ['group' => 'Pilotage', 'label' => 'Voir le reporting', 'description' => 'Accéder au hub reporting et aux exports.', 'sensitive' => false],
            'alerts.read' => ['group' => 'Pilotage', 'label' => 'Voir les alertes', 'description' => 'Accéder au centre d’alertes.', 'sensitive' => false],
            'referentiel.read' => ['group' => 'Référentiel', 'label' => 'Lire le référentiel', 'description' => 'Consulter directions, services et utilisateurs.', 'sensitive' => false],
            'referentiel.write' => ['group' => 'Référentiel', 'label' => 'Modifier le référentiel', 'description' => 'Créer ou modifier directions et services.', 'sensitive' => true],
            'users.manage' => ['group' => 'Référentiel', 'label' => 'Administrer les utilisateurs', 'description' => 'Créer, modifier ou supprimer des comptes.', 'sensitive' => true],
            'users.manage_roles' => ['group' => 'Référentiel', 'label' => 'Administrer les roles', 'description' => 'Affecter et changer les roles utilisateur.', 'sensitive' => true],
            'delegations.manage' => ['group' => 'Gouvernance', 'label' => 'Gérer les délégations', 'description' => 'Créer et annuler des délégations.', 'sensitive' => true],
            'retention.read' => ['group' => 'Gouvernance', 'label' => 'Voir la rétention', 'description' => 'Consulter les vues de rétention et archivage.', 'sensitive' => false],
            'retention.manage' => ['group' => 'Gouvernance', 'label' => 'Piloter la rétention', 'description' => 'Exécuter les actions de rétention.', 'sensitive' => true],
            'api_docs.read' => ['group' => 'Gouvernance', 'label' => 'Voir l’API', 'description' => 'Accéder à la documentation API.', 'sensitive' => false],
            'audit.read' => ['group' => 'Audit', 'label' => 'Voir l’audit', 'description' => 'Consulter les journaux d’audit.', 'sensitive' => true],
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

            foreach ($this->knownRoleCodes() as $role) {
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

                $settings[$role] = $this->enforceServiceOrUnitChiefBoundary(
                    $role,
                    $this->sanitizePermissionList($decoded)
                );
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
        $all = $this->all();

        if (array_key_exists($role, $all)) {
            return $all[$role];
        }

        $baseRole = $this->roleRegistry->baseRole($role);
        if (array_key_exists($baseRole, $all)) {
            return $all[$baseRole];
        }

        return [];
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
        $visibleRoleCodes = array_keys($this->roles());
        $roleCodes = array_values(array_unique(array_merge(
            $visibleRoleCodes,
            array_values(array_filter(
                array_map('strval', array_keys($payload)),
                fn (string $role): bool => in_array($role, $this->knownRoleCodes(), true)
            ))
        )));

        foreach ($roleCodes as $role) {
            $submittedPermissions = array_key_exists($role, $payload)
                ? $payload[$role]
                : (in_array($role, $visibleRoleCodes, true) ? [] : $this->forRole($role));

            $permissions = $role === User::ROLE_SUPER_ADMIN
                ? array_keys($this->permissions())
                : $this->enforceServiceOrUnitChiefBoundary(
                    $role,
                    $this->sanitizePermissionList($submittedPermissions)
                );

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
            ],
            // A06 — DG en lecture seule pure : supervision + indicateurs consolides
            // + audit, mais aucune ecriture ni validation directe. La validation
            // finale PAS/PAO/PTA revient a SUPER_ADMIN / ADMIN. Le DG voit tout
            // mais n est pas une voie d ecriture (separation des pouvoirs).
            // DG (Directeur General) : pilote l'agence — autorite ecriture/suppression
            // globale sur tout le portefeuille planification. Conserve les droits de
            // lecture / reporting / audit. Le DG peut creer, modifier et supprimer
            // PAS / PAO / PTA / Actions sans restriction de perimetre (scope global).
            User::ROLE_DG => [
                'scope.global.read',
                'scope.global.write',
                'planning.read',
                'planning.write.global',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'audit.read',
            ],
            // A37 — Planification : retire `scope.global.write` (trop large).
            // `planning.write.global` reste suffisant pour son metier (gerer la
            // planification de bout en bout). `scope.global.write` est reserve
            // aux profils purement administratifs (SUPER_ADMIN, ADMIN,
            // ADMIN_FONCTIONNEL).
            User::ROLE_PLANIFICATION => [
                'scope.global.read',
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
                'audit.read',
            ],
            // A37 — SCIQ : retire `scope.global.write` (trop large) ; conserve
            // `planning.write.global` et `referentiel.write` pour son metier.
            User::ROLE_SCIQ => [
                'scope.global.read',
                'planning.read',
                'planning.write.global',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'referentiel.write',
                'delegations.manage',
            ],
            User::ROLE_DIRECTION => [
                'planning.read',
                'planning.write.direction',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'delegations.manage',
            ],
            User::ROLE_SERVICE => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'delegations.manage',
            ],
            User::ROLE_CHEF_UNITE => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
            ],
            User::ROLE_AGENT => [
                'planning.read',
                'reporting.read',
                'alerts.read',
            ],
            // A06 — Cabinet et Collaborateurs voient tout mais ne pilotent plus
            // le strategique. La gestion du PAS officiel passe par PLANIFICATION,
            // SCIQ et SUPER_ADMIN/ADMIN.
            User::ROLE_CABINET => [
                'scope.global.read',
                'planning.read',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'audit.read',
            ],
            User::ROLE_COLLABORATEUR => [
                'scope.global.read',
                'planning.read',
                'reporting.read',
                'alerts.read',
                'audit.read',
            ],

            // Profils ajoutés (Lot 2) — alignement organisation ANBG.

            // Admin métier (gestion paramétrage complet hors paramétrage profond
            // réservé au super_admin). A accès rétention et API docs pour gérer
            // la gouvernance applicative en l'absence du super_admin.
            User::ROLE_ADMIN_FONCTIONNEL => [
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
            ],

            // A36 — SCIQ_SUIVI_GLOBAL et CHEF_UNITE_SCIQ sont des ALIAS
            // fonctionnels de SCIQ (cf. RoleRegistryService). Les 3 codes
            // existent en BDD pour la retrocompatibilite et la traçabilite
            // organisationnelle (qui est rattache a quelle unite) mais portent
            // la MEME matrice de permissions. SCIQ est le code canonique : si
            // tu modifies SCIQ, propage manuellement aux deux alias pour
            // garder l alignement.
            User::ROLE_SCIQ_SUIVI_GLOBAL => [
                'scope.global.read',
                'planning.read',
                'planning.write.global',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'referentiel.write',
                'delegations.manage',
            ],

            // Chef planification : meme niveau de droits que Chef d'unite SCIQ.
            // Profil de controle principal : suivi global, imports, pilotage
            // strategique et gestion des utilisateurs direction/service, sans
            // les droits referentiel/delegation du profil SCIQ.
            User::ROLE_CHEF_PLANIFICATION => [
                'scope.global.read',
                'planning.read',
                'planning.write.global',
                'planning.write.service',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'users.manage',
                'users.manage_roles',
            ],

            // Chef d'unite SCIQ : controle principal global + suivi strategique
            // et gestion des utilisateurs direction/service.
            User::ROLE_CHEF_UNITE_SCIQ => [
                'scope.global.read',
                'planning.read',
                'planning.write.global',
                'planning.write.service',
                'planning.strategic.manage',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'users.manage',
                'users.manage_roles',
            ],

            // DGA — supervision vue globale (lecture). A06 : retire le pilotage
            // strategique (gestion PAS = PLANIFICATION / SCIQ / admin uniquement).
            User::ROLE_DGA_SUPERVISION => [
                'scope.global.read',
                'planning.read',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
            ],

            // Chef d'unité DGA — gère son unité (portée service, pas globale).
            User::ROLE_CHEF_UNITE_DGA => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
            ],

            // Cabinet — supervision (équivalent au Cabinet actuel, vue globale lecture).
            // A06 : retire le pilotage strategique.
            User::ROLE_CABINET_SUPERVISION => [
                'scope.global.read',
                'planning.read',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
                'audit.read',
            ],

            // Chef d'unité Cabinet — gère son unité (portée service, pas globale).
            User::ROLE_CHEF_UNITE_CABINET => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
                'referentiel.read',
            ],

            // Chef d'unité UCAS — portée limitée à son unité uniquement.
            User::ROLE_CHEF_UNITE_UCAS => [
                'planning.read',
                'planning.write.service',
                'reporting.read',
                'alerts.read',
            ],

            // UCAS — lecture pilotage + alertes.
            User::ROLE_UCAS => [
                'planning.read',
                'reporting.read',
                'alerts.read',
            ],
            // Auditeur — lecture globale pour audit + reporting + alertes.
            User::ROLE_AUDITEUR => [
                'scope.global.read',
                'planning.read',
                'reporting.read',
                'alerts.read',
                'audit.read',
            ],

            // Invité — lecture très limitée.
            User::ROLE_INVITE_LECTURE => [
                'reporting.read',
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

    /**
     * @param array<int, string> $permissions
     * @return array<int, string>
     */
    private function enforceServiceOrUnitChiefBoundary(string $role, array $permissions): array
    {
        $baseRole = $this->roleRegistry->baseRole($role);
        $isServiceOrUnitChief = in_array($role, User::serviceOrUnitChiefRoles(), true)
            || in_array($baseRole, User::serviceOrUnitChiefRoles(), true);

        if (! $isServiceOrUnitChief) {
            return $permissions;
        }

        $blocked = User::serviceOrUnitChiefBlockedPermissions();

        if (
            in_array($role, User::planningControlChiefRoles(), true)
            || in_array($baseRole, User::planningControlChiefRoles(), true)
        ) {
            $blocked = array_diff($blocked, [
                'scope.global.read',
                'planning.write.global',
                'planning.strategic.manage',
            ]);

            return array_values(array_unique(array_merge(
                array_diff($permissions, $blocked),
                [
                    'scope.global.read',
                    'planning.read',
                    'planning.write.global',
                    'planning.write.service',
                    'planning.strategic.manage',
                    'reporting.read',
                    'alerts.read',
                    'referentiel.read',
                    'users.manage',
                    'users.manage_roles',
                ]
            )));
        }

        return array_values(array_diff($permissions, $blocked));
    }

    /**
     * @return array<int, string>
     */
    private function knownRoleCodes(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->defaults()),
            array_keys($this->roles())
        )));
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
