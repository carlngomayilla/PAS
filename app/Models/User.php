<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_DG = 'dg';
    public const ROLE_PLANIFICATION = 'planification';
    public const ROLE_DIRECTION = 'direction';
    public const ROLE_SERVICE = 'service';
    public const ROLE_AGENT = 'agent';
    public const ROLE_CABINET = 'cabinet';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'profile_photo_path',
        'email',
        'password',
        'password_changed_at',
        'role',
        'is_agent',
        'agent_matricule',
        'agent_fonction',
        'agent_telephone',
        'direction_id',
        'service_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'profile_photo_url',
        'profile_initials',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'is_agent' => 'boolean',
        ];
    }

    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            $path = $this->profile_photo_path;
            if (! is_string($path) || trim($path) === '') {
                return null;
            }

            $normalizedPath = collect(explode('/', str_replace('\\', '/', trim($path, '/'))))
                ->filter(fn (string $segment): bool => $segment !== '')
                ->map(static fn (string $segment): string => rawurlencode($segment))
                ->implode('/');

            return '/storage/' . $normalizedPath;
        });
    }

    protected function profileInitials(): Attribute
    {
        return Attribute::get(function (): string {
            $name = trim((string) $this->name);
            if ($name === '') {
                return 'NA';
            }

            $parts = Str::of($name)
                ->replaceMatches('/\s+/', ' ')
                ->explode(' ')
                ->filter(fn (string $part): bool => $part !== '')
                ->values();

            if ($parts->isEmpty()) {
                return 'NA';
            }

            $first = Str::upper(Str::substr((string) $parts->get(0), 0, 1));
            $last = Str::upper(Str::substr((string) $parts->last(), 0, 1));

            if ($parts->count() === 1) {
                $last = Str::upper(Str::substr((string) $parts->get(0), 1, 1));
            }

            $initials = trim($first . $last);

            return $initials !== '' ? $initials : 'NA';
        });
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class);
    }

    public function alertReads(): HasMany
    {
        return $this->hasMany(AlertRead::class);
    }

    public function delegationsGiven(): HasMany
    {
        return $this->hasMany(Delegation::class, 'delegant_id');
    }

    public function delegationsReceived(): HasMany
    {
        return $this->hasMany(Delegation::class, 'delegue_id');
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function hasGlobalWriteAccess(): bool
    {
        return $this->hasRole(
            self::ROLE_ADMIN,
            self::ROLE_DG,
            self::ROLE_PLANIFICATION
        );
    }

    public function hasGlobalReadAccess(): bool
    {
        return $this->hasGlobalWriteAccess() || $this->hasRole(self::ROLE_CABINET);
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_ADMIN => 'Administrateur',
            self::ROLE_DG => 'Direction Generale',
            self::ROLE_PLANIFICATION => 'Planification',
            self::ROLE_DIRECTION => 'Direction',
            self::ROLE_SERVICE => 'Service',
            self::ROLE_AGENT => 'Agent executant',
            self::ROLE_CABINET => 'Cabinet',
            default => 'Profil non defini',
        };
    }

    public function isAgent(): bool
    {
        return $this->role === self::ROLE_AGENT || (bool) $this->is_agent;
    }

    public function profileScopeLabel(): string
    {
        if ($this->hasGlobalWriteAccess()) {
            return 'Portee globale (lecture/ecriture)';
        }

        if ($this->hasRole(self::ROLE_CABINET)) {
            return 'Portee globale (lecture seule)';
        }

        if ($this->hasRole(self::ROLE_DIRECTION)) {
            return 'Portee directionnelle';
        }

        if ($this->hasRole(self::ROLE_AGENT)) {
            return 'Portee direction et service (agent)';
        }

        if ($this->hasRole(self::ROLE_SERVICE)) {
            return 'Portee direction et service (service)';
        }

        return 'Portee non definie';
    }

    public function activeDelegations(?string $permission = null)
    {
        $delegations = $this->delegationsReceived()
            ->with(['delegant:id,name,role,direction_id,service_id', 'direction:id,code,libelle', 'service:id,code,libelle'])
            ->get()
            ->filter(static fn (Delegation $delegation): bool => $delegation->isActiveAt());

        if ($permission !== null && $permission !== '') {
            $delegations = $delegations->filter(
                static fn (Delegation $delegation): bool => $delegation->hasPermission($permission)
            );
        }

        return $delegations->values();
    }

    /**
     * @return array<int, int>
     */
    public function delegatedDirectionIds(?string $permission = null): array
    {
        return $this->activeDelegations($permission)
            ->filter(static fn (Delegation $delegation): bool => $delegation->direction_id !== null)
            ->pluck('direction_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{direction_id:int,service_id:int}>
     */
    public function delegatedServiceScopes(?string $permission = null): array
    {
        return $this->activeDelegations($permission)
            ->filter(static fn (Delegation $delegation): bool => $delegation->direction_id !== null && $delegation->service_id !== null)
            ->map(static fn (Delegation $delegation): array => [
                'direction_id' => (int) $delegation->direction_id,
                'service_id' => (int) $delegation->service_id,
            ])
            ->unique(static fn (array $scope): string => $scope['direction_id'].'-'.$scope['service_id'])
            ->values()
            ->all();
    }

    public function hasDelegatedDirectionScope(?int $directionId, ?string $permission = null): bool
    {
        if ($directionId === null) {
            return false;
        }

        return in_array((int) $directionId, $this->delegatedDirectionIds($permission), true);
    }

    public function hasDelegatedServiceScope(?int $directionId, ?int $serviceId, ?string $permission = null): bool
    {
        if ($directionId === null || $serviceId === null) {
            return false;
        }

        foreach ($this->delegatedServiceScopes($permission) as $scope) {
            if ($scope['direction_id'] === (int) $directionId && $scope['service_id'] === (int) $serviceId) {
                return true;
            }
        }

        return false;
    }

    public function hasDelegatedPermission(string $permission): bool
    {
        return $this->activeDelegations($permission)->isNotEmpty();
    }

    /**
     * @return array<string, mixed>
     */
    public function profileInteractions(): array
    {
        $items = match ($this->role) {
            self::ROLE_ADMIN => [
                [
                    'module' => 'Gouvernance PAS/PAO/PTA',
                    'operations' => ['Creer', 'Modifier', 'Supprimer', 'Valider', 'Verrouiller', 'Consulter'],
                    'portee' => 'Toutes les directions et services',
                ],
                [
                    'module' => 'Execution et performance',
                    'operations' => ['Piloter actions', 'Suivre progression globale', 'Consulter alertes'],
                    'portee' => 'Globale',
                ],
                [
                    'module' => 'Administration',
                    'operations' => ['Gerer utilisateurs', 'Consulter journal audit'],
                    'portee' => 'Globale',
                ],
            ],
            self::ROLE_DG => [
                [
                    'module' => 'PAS',
                    'operations' => ['Valider', 'Verrouiller', 'Consulter'],
                    'portee' => 'Globale',
                ],
                [
                    'module' => 'PAO / PTA',
                    'operations' => ['Superviser', 'Valider', 'Consulter'],
                    'portee' => 'Globale',
                ],
                [
                    'module' => 'Reporting',
                    'operations' => ['Consulter tableaux de bord consolides'],
                    'portee' => 'Globale',
                ],
            ],
            self::ROLE_PLANIFICATION => [
                [
                    'module' => 'Structuration PAS/PAO/PTA',
                    'operations' => ['Creer', 'Modifier', 'Soumettre', 'Consulter'],
                    'portee' => 'Globale',
                ],
                [
                    'module' => 'Objectifs strategiques',
                    'operations' => ['Definir axes/objectifs', 'Configurer indicateurs de suivi'],
                    'portee' => 'Globale',
                ],
                [
                    'module' => 'Suivi et reporting',
                    'operations' => ['Consolider avancement', 'Produire rapports'],
                    'portee' => 'Globale',
                ],
            ],
            self::ROLE_DIRECTION => [
                [
                    'module' => 'PAO de la direction',
                    'operations' => ['Creer', 'Modifier', 'Suivre', 'Soumettre'],
                    'portee' => 'Direction rattachee',
                ],
                [
                    'module' => 'PTA et execution',
                    'operations' => ['Superviser services', 'Suivre actions'],
                    'portee' => 'Direction rattachee',
                ],
            ],
            self::ROLE_SERVICE => [
                [
                    'module' => 'PTA du service',
                    'operations' => ['Executer taches', 'Mettre a jour statuts'],
                    'portee' => 'Direction et service rattaches',
                ],
                [
                    'module' => 'Actions',
                    'operations' => ['Renseigner execution', 'Saisir suivi hebdomadaire', 'Televerser justificatifs', 'Signaler risques'],
                    'portee' => 'Direction et service rattaches',
                ],
            ],
            self::ROLE_AGENT => [
                [
                    'module' => 'Suivi hebdomadaire des actions',
                    'operations' => ['Renseigner suivi hebdomadaire', 'Mettre a jour progression', 'Signaler difficultes', 'Televerser justificatifs hebdomadaires'],
                    'portee' => 'Direction et service rattaches',
                ],
            ],
            self::ROLE_CABINET => [
                [
                    'module' => 'Pilotage',
                    'operations' => ['Consulter PAS/PAO/PTA', 'Consulter reporting'],
                    'portee' => 'Globale',
                ],
                [
                    'module' => 'Audit',
                    'operations' => ['Lecture seule des informations'],
                    'portee' => 'Globale',
                ],
            ],
            default => [],
        };

        return [
            'role' => $this->role,
            'role_label' => $this->roleLabel(),
            'scope' => $this->profileScopeLabel(),
            'items' => $items,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function workspaceModules(): array
    {
        $hasDelegatedPlanningRead = $this->hasDelegatedPermission('planning_read')
            || $this->hasDelegatedPermission('planning_write');
        $hasDelegatedPlanningWrite = $this->hasDelegatedPermission('planning_write');
        $hasDelegatedActionReview = $this->hasDelegatedPermission('action_review');

        $canReadPlanning = $this->hasGlobalReadAccess()
            || $this->hasRole(self::ROLE_DIRECTION, self::ROLE_SERVICE)
            || $hasDelegatedPlanningRead;
        $canWriteGlobal = $this->hasGlobalWriteAccess();
        $canWriteDirection = $this->hasRole(self::ROLE_DIRECTION) || $hasDelegatedPlanningWrite;
        $canWriteService = $this->hasRole(self::ROLE_SERVICE) || $hasDelegatedPlanningWrite;
        $isAgent = $this->isAgent();
        $canWriteOperational = $canWriteGlobal || $canWriteDirection || $canWriteService;
        $canManageActions = $canWriteOperational && ! $isAgent;

        $modules = [];

        if ($canReadPlanning) {
            $modules[] = [
                'code' => 'pas',
                'label' => 'PAS',
                'description' => 'Vision strategique pluriannuelle',
                'endpoint' => '/api/pas',
                'can_write' => $canWriteGlobal,
                'actions' => $canWriteGlobal
                    ? ['Consulter', 'Creer', 'Modifier', 'Valider', 'Verrouiller']
                    : ['Consulter'],
            ];

            $modules[] = [
                'code' => 'pao',
                'label' => 'PAO',
                'description' => 'Declinaison annuelle par direction',
                'endpoint' => '/api/paos',
                'can_write' => $canWriteGlobal || $canWriteDirection,
                'actions' => ($canWriteGlobal || $canWriteDirection)
                    ? ['Consulter', 'Creer', 'Modifier', 'Suivre']
                    : ['Consulter'],
            ];

            $modules[] = [
                'code' => 'pta',
                'label' => 'PTA',
                'description' => 'Planification operationnelle par service',
                'endpoint' => '/api/ptas',
                'can_write' => $canWriteGlobal || $canWriteDirection || $canWriteService,
                'actions' => ($canWriteGlobal || $canWriteDirection || $canWriteService)
                    ? ['Consulter', 'Creer', 'Modifier', 'Executer']
                    : ['Consulter'],
            ];
        }

        if ($canReadPlanning || $isAgent || $hasDelegatedActionReview) {
            $modules[] = [
                'code' => 'execution',
                'label' => 'Actions',
                'description' => 'Execution des taches et suivi de progression',
                'endpoint' => '/api/actions',
                'can_write' => $canWriteOperational || $isAgent || $hasDelegatedActionReview,
                'actions' => $isAgent
                    ? ['Consulter', 'Renseigner suivi hebdomadaire', 'Televerser justificatifs hebdomadaires']
                    : ($canManageActions
                        ? ['Consulter', 'Creer', 'Modifier', 'Supprimer', 'Cloturer', 'Suivi hebdomadaire']
                        : ($hasDelegatedActionReview
                            ? ['Consulter', 'Evaluer', 'Valider ou rejeter']
                            : ['Consulter'])),
            ];
        }

        if ($canReadPlanning) {
            $modules[] = [
                'code' => 'alertes',
                'label' => 'Alertes',
                'description' => 'Retards et indicateurs sous seuil',
                'endpoint' => '/api/alertes',
                'can_write' => false,
                'actions' => ['Consulter'],
            ];

            $modules[] = [
                'code' => 'reporting',
                'label' => 'Reporting',
                'description' => 'Tableau de bord consolide des indicateurs',
                'endpoint' => '/api/reporting/overview',
                'can_write' => false,
                'actions' => ['Consulter'],
            ];
        }

        if ($this->hasGlobalReadAccess()) {
            $modules[] = [
                'code' => 'referentiel',
                'label' => 'Referentiels',
                'description' => 'Directions, services, utilisateurs',
                'endpoint' => '/api/referentiel/utilisateurs',
                'can_write' => $canWriteGlobal,
                'actions' => $canWriteGlobal
                    ? ['Consulter', 'Administrer']
                    : ['Consulter'],
            ];

            $modules[] = [
                'code' => 'audit',
                'label' => 'Journal Audit',
                'description' => 'Tracabilite des actions utilisateurs',
                'endpoint' => '/api/journal-audit',
                'can_write' => false,
                'actions' => ['Consulter'],
            ];

            $modules[] = [
                'code' => 'api_docs',
                'label' => 'Documentation API',
                'description' => 'Contrats OpenAPI et Swagger UI',
                'endpoint' => '/workspace/documentation-api',
                'can_write' => false,
                'actions' => ['Consulter'],
            ];

            $modules[] = [
                'code' => 'retention',
                'label' => 'Retention',
                'description' => 'Archivage et gouvernance des donnees',
                'endpoint' => '/workspace/retention',
                'can_write' => $canWriteGlobal,
                'actions' => $canWriteGlobal ? ['Consulter', 'Piloter'] : ['Consulter'],
            ];
        }

        if ($canWriteGlobal) {
            $modules[] = [
                'code' => 'delegations',
                'label' => 'Delegations',
                'description' => 'Suppleance temporaire de validation',
                'endpoint' => '/workspace/referentiel/delegations',
                'can_write' => true,
                'actions' => ['Consulter', 'Creer', 'Annuler'],
            ];
        }

        return $modules;
    }
}


