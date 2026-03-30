<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'is_active',
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
            self::ROLE_DG => 'DG',
            self::ROLE_PLANIFICATION => 'PLANIFICATION',
            self::ROLE_DIRECTION => 'DIRECTION',
            self::ROLE_SERVICE => 'SERVICES',
            self::ROLE_AGENT => 'AGENT',
            self::ROLE_CABINET => 'CABINET',
            default => 'Profil non defini',
        };
    }

    public function isAgent(): bool
    {
        return $this->role === self::ROLE_AGENT;
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
        $now = now();

        $query = $this->delegationsReceived()
            ->with(['delegant:id,name,role,direction_id,service_id', 'direction:id,code,libelle', 'service:id,code,libelle'])
            ->where('statut', 'active')
            ->where('date_debut', '<=', $now)
            ->where('date_fin', '>=', $now);

        if ($permission !== null && $permission !== '') {
            $query->whereJsonContains('permissions', $permission);
        }

        return $query->get()->values();
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
        return app(\App\Services\UserProfileService::class)->interactionsFor($this);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function workspaceModules(): array
    {
        return app(\App\Services\UserWorkspaceService::class)->modulesFor($this);
    }
}
