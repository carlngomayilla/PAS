<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_DG = 'dg';
    public const ROLE_PLANIFICATION = 'planification';
    public const ROLE_DIRECTION = 'direction';
    public const ROLE_SERVICE = 'service';
    public const ROLE_AGENT = 'agent';
    public const ROLE_CABINET = 'cabinet';

    // Profils ajoutés (Lot 2) — pour aligner l'application sur l'organisation réelle ANBG.
    public const ROLE_ADMIN_FONCTIONNEL = 'admin_fonctionnel';
    public const ROLE_SCIQ_SUIVI_GLOBAL = 'sciq_suivi_global';
    public const ROLE_CHEF_UNITE_SCIQ = 'chef_unite_sciq';
    public const ROLE_CHEF_UNITE_DGA = 'chef_unite_dga';
    public const ROLE_CHEF_UNITE_CABINET = 'chef_unite_cabinet';
    public const ROLE_CHEF_UNITE_UCAS = 'chef_unite_ucas';
    public const ROLE_DGA_SUPERVISION = 'dga_supervision';
    public const ROLE_CABINET_SUPERVISION = 'cabinet_supervision';
    public const ROLE_AUDITEUR = 'auditeur';
    public const ROLE_INVITE_LECTURE = 'invite_lecture';

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
        'custom_role_code',
        'is_active',
        'suspended_until',
        'suspension_reason',
        'is_agent',
        'agent_matricule',
        'agent_fonction',
        'agent_telephone',
        'direction_id',
        'service_id',
        'unite_dg_id',
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
     * Per-request cache for active delegation lookups. Dashboard policies call
     * these helpers often; keeping the result on the model avoids repeated SQL.
     *
     * @var array<string, \Illuminate\Support\Collection<int, \App\Models\Delegation>>
     */
    private array $activeDelegationsCache = [];

    /**
     * Per-request cache for workspace-derived payloads used repeatedly by the
     * layout, sidebar and dashboard.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $workspaceModulesCache = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $profileInteractionsCache = null;

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
            'suspended_until' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
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

    public function uniteDg(): BelongsTo
    {
        return $this->belongsTo(UniteDg::class, 'unite_dg_id');
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot(['last_read_at', 'is_favorite', 'joined_at'])
            ->withTimestamps();
    }

    public function conversationParticipants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messagesSent(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
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

    public function hasEffectiveRole(string ...$roles): bool
    {
        return in_array($this->effectiveRoleCode(), $roles, true);
    }

    public function effectiveRoleCode(): string
    {
        $customRoleCode = trim((string) ($this->custom_role_code ?? ''));

        return $customRoleCode !== '' ? $customRoleCode : (string) $this->role;
    }

    public function baseRoleCode(): string
    {
        return (string) $this->role;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_until !== null && $this->suspended_until->isFuture();
    }

    /**
     * @return array<int, string>
     */
    public function grantedPermissions(): array
    {
        return app(\App\Services\RolePermissionSettings::class)->forUser($this);
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return app(\App\Services\RolePermissionSettings::class)->has($this, $permission);
    }

    public function hasAnyPermission(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasGlobalWriteAccess(): bool
    {
        return $this->hasPermission('scope.global.write');
    }

    public function hasGlobalReadAccess(): bool
    {
        return $this->hasGlobalWriteAccess() || $this->hasPermission('scope.global.read');
    }

    public function roleLabel(): string
    {
        return app(\App\Services\RoleRegistryService::class)->label($this->effectiveRoleCode());
    }

    public function isAgent(): bool
    {
        return $this->role === self::ROLE_AGENT;
    }

    public function profileScopeLabel(): string
    {
        if ($this->isSuperAdmin()) {
            return 'Portée globale plateforme';
        }

        if ($this->hasGlobalWriteAccess()) {
            return 'Portée globale (lecture/écriture)';
        }

        // Rôles à portée globale en lecture seule (cabinet, supervision, audit, invité).
        if ($this->hasRole(
            self::ROLE_CABINET,
            self::ROLE_CABINET_SUPERVISION,
            self::ROLE_DGA_SUPERVISION,
            self::ROLE_AUDITEUR,
            self::ROLE_INVITE_LECTURE,
        )) {
            return 'Portée globale (lecture seule)';
        }

        // Chefs d'unité SCIQ/DGA/Cabinet : vue globale agence + gestion de leur unité.
        if ($this->hasRole(
            self::ROLE_CHEF_UNITE_SCIQ,
            self::ROLE_CHEF_UNITE_DGA,
            self::ROLE_CHEF_UNITE_CABINET,
        )) {
            return 'Portée globale + unité DG';
        }

        // Chef d'unité UCAS : limité à son unité.
        if ($this->hasRole(self::ROLE_CHEF_UNITE_UCAS)) {
            return 'Portée unité DG (UCAS)';
        }

        if ($this->hasRole(self::ROLE_DIRECTION)) {
            return 'Portée directionnelle';
        }

        if ($this->hasRole(self::ROLE_AGENT)) {
            return 'Portée direction et service (agent)';
        }

        if ($this->hasRole(self::ROLE_SERVICE)) {
            return 'Portée direction et service (service)';
        }

        return 'Portée non définie';
    }

    public function activeDelegations(?string $permission = null)
    {
        $permissionKey = trim((string) $permission);
        $cacheKey = $permissionKey !== '' ? $permissionKey : '*';

        if (array_key_exists($cacheKey, $this->activeDelegationsCache)) {
            return $this->activeDelegationsCache[$cacheKey];
        }

        $now = now();

        $query = $this->delegationsReceived()
            ->with(['delegant:id,name,role,direction_id,service_id', 'direction:id,code,libelle', 'service:id,code,libelle'])
            ->where('statut', 'active')
            ->where('date_debut', '<=', $now)
            ->where('date_fin', '>=', $now);

        if ($permissionKey !== '') {
            $query->whereJsonContains('permissions', $permissionKey);
        }

        return $this->activeDelegationsCache[$cacheKey] = $query->get()->values();
    }

    /**
     * @return array<int, int>
     */
    public function delegatedDirectionIds(?string $permission = null): array
    {
        return $this->activeDelegations($permission)
            ->filter(static fn (Delegation $delegation): bool => $delegation->role_scope === Delegation::SCOPE_DIRECTION && $delegation->direction_id !== null)
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
            ->filter(static fn (Delegation $delegation): bool => $delegation->role_scope === Delegation::SCOPE_SERVICE && $delegation->direction_id !== null && $delegation->service_id !== null)
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
        if ($this->profileInteractionsCache !== null) {
            return $this->profileInteractionsCache;
        }

        return $this->profileInteractionsCache = app(\App\Services\UserProfileService::class)->interactionsFor($this);
    }

    /**
     * Retourne le périmètre d'accès de l'utilisateur (global, direction, service, unité, agent ou limited).
     *
     * @return array<string, mixed>
     */
    public function accessScope(): array
    {
        return app(\App\Services\AccessScopeService::class)->scopeFor($this);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function workspaceModules(): array
    {
        if ($this->workspaceModulesCache !== null) {
            return $this->workspaceModulesCache;
        }

        return $this->workspaceModulesCache = app(\App\Services\UserWorkspaceService::class)->modulesFor($this);
    }
}
