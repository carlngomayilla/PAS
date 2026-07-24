<?php

namespace App\Services\Organization;

use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use App\Services\RoleRegistryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OrganizationDirectoryService
{
    /** @var list<string> */
    private const DIRECTION_SCOPED_ROLES = [
        User::ROLE_DIRECTION,
        User::ROLE_SERVICE,
        User::ROLE_AGENT,
        User::ROLE_CHEF_PLANIFICATION,
        User::ROLE_CHEF_UNITE_SCIQ,
        User::ROLE_CHEF_UNITE_DGA,
        User::ROLE_CHEF_UNITE_CABINET,
        User::ROLE_CHEF_UNITE_UCAS,
    ];

    /** @var list<string> */
    private const SERVICE_SCOPED_ROLES = [User::ROLE_SERVICE, User::ROLE_AGENT];

    /** @var list<string> */
    private const UNIT_SCOPED_ROLES = [
        User::ROLE_CHEF_UNITE_SCIQ,
        User::ROLE_CHEF_UNITE_DGA,
        User::ROLE_CHEF_UNITE_CABINET,
        User::ROLE_CHEF_UNITE_UCAS,
    ];

    public function __construct(
        private readonly RoleRegistryService $roleRegistry
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{q:string,actif:string,sort:string,per_page:int,page:int}
     */
    public function normalizeDirectionFilters(array $input): array
    {
        return [
            'q' => Str::limit($this->scalar($input, 'q'), 100, ''),
            'actif' => $this->booleanFilter($input, 'actif', '1'),
            'sort' => $this->choice($input, 'sort', ['code', 'libelle', 'size'], 'code'),
            'per_page' => $this->perPage($input['per_page'] ?? null),
            'page' => $this->positiveInteger($input['page'] ?? null) ?? 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{q:string,direction_id:?int,actif:string,sort:string,per_page:int,page:int}
     */
    public function normalizeServiceFilters(array $input): array
    {
        return [
            'q' => Str::limit($this->scalar($input, 'q'), 100, ''),
            'direction_id' => $this->positiveInteger($input['direction_id'] ?? null),
            'actif' => $this->booleanFilter($input, 'actif'),
            'sort' => $this->choice($input, 'sort', ['code', 'libelle', 'size'], 'code'),
            'per_page' => $this->perPage($input['per_page'] ?? null),
            'page' => $this->positiveInteger($input['page'] ?? null) ?? 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     q:string,direction_id:?int,service_id:?int,role:string,account_state:string,
     *     sort:string,per_page:int,page:int
     * }
     */
    public function normalizeUserFilters(array $input): array
    {
        return [
            'q' => Str::limit($this->scalar($input, 'q'), 100, ''),
            'direction_id' => $this->positiveInteger($input['direction_id'] ?? null),
            'service_id' => $this->positiveInteger($input['service_id'] ?? null),
            'role' => Str::limit($this->scalar($input, 'role'), 100, ''),
            'account_state' => $this->choice(
                $input,
                'account_state',
                ['active', 'inactive', 'suspended', 'renewal', 'unscoped'],
                $this->legacyAccountState($input)
            ),
            'sort' => $this->choice($input, 'sort', ['name', 'recent', 'role'], 'name'),
            'per_page' => $this->perPage($input['per_page'] ?? null),
            'page' => $this->positiveInteger($input['page'] ?? null) ?? 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows:LengthAwarePaginator<int, Direction>,summary:array<string, int>}
     */
    public function directionsWorkspace(User $viewer, array $filters): array
    {
        $contextFilters = $filters;
        $contextFilters['actif'] = '';
        $contextQuery = $this->directionQuery($viewer, $contextFilters);
        $query = $this->directionQuery($viewer, $filters);
        $contextRows = (clone $contextQuery)->get();

        return [
            'rows' => $this->paginateDirections($query, $filters),
            'summary' => [
                'total' => $contextRows->count(),
                'actifs' => $contextRows->where('actif', true)->count(),
                'inactifs' => $contextRows->where('actif', false)->count(),
                'services_total' => (int) $contextRows->sum('services_count'),
                'users_total' => (int) $contextRows->sum('users_count'),
                'paos_total' => (int) $contextRows->sum('paos_count'),
                'ptas_total' => (int) $contextRows->sum('ptas_count'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows:LengthAwarePaginator<int, Service>,summary:array<string, int>}
     */
    public function servicesWorkspace(User $viewer, array $filters): array
    {
        $contextFilters = $filters;
        $contextFilters['actif'] = '';
        $contextQuery = $this->serviceQuery($viewer, $contextFilters);
        $query = $this->serviceQuery($viewer, $filters);
        $contextRows = (clone $contextQuery)->get();

        return [
            'rows' => $this->paginateServices($query, $filters),
            'summary' => [
                'total' => $contextRows->count(),
                'actifs' => $contextRows->where('actif', true)->count(),
                'inactifs' => $contextRows->where('actif', false)->count(),
                'directions_total' => $contextRows->pluck('direction_id')->filter()->unique()->count(),
                'users_total' => (int) $contextRows->sum('users_count'),
                'ptas_total' => (int) $contextRows->sum('ptas_count'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows:LengthAwarePaginator<int, User>,
     *     summary:array<string, int|array<string, int>>,
     *     health:array<int, array{code:string,label:string,tone:string}>
     * }
     */
    public function usersWorkspace(User $viewer, array $filters): array
    {
        $contextFilters = $filters;
        $contextFilters['account_state'] = '';
        $contextQuery = $this->userQuery($viewer, $contextFilters);
        $query = $this->userQuery($viewer, $filters);
        $rows = $this->paginateUsers($query, $filters);

        return [
            'rows' => $rows,
            'summary' => [
                'total' => (clone $query)->count(),
                'directions_total' => (clone $query)->whereNotNull('direction_id')->distinct()->count('direction_id'),
                'services_total' => (clone $query)->whereNotNull('service_id')->distinct()->count('service_id'),
                'scope_counts' => [
                    'all' => (clone $contextQuery)->count(),
                    'active' => $this->countUsersForState($contextQuery, 'active'),
                    'inactive' => $this->countUsersForState($contextQuery, 'inactive'),
                    'suspended' => $this->countUsersForState($contextQuery, 'suspended'),
                    'renewal' => $this->countUsersForState($contextQuery, 'renewal'),
                    'unscoped' => $this->countUsersForState($contextQuery, 'unscoped'),
                ],
            ],
            'health' => $rows->getCollection()
                ->mapWithKeys(fn (User $user): array => [(int) $user->id => $this->userHealth($user)])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Direction>
     */
    public function directionQuery(User $viewer, array $filters): Builder
    {
        $query = Direction::query()->withCount(['services', 'users', 'paos', 'ptas']);
        $this->scopeDirections($query, $viewer);

        $query->when(($filters['actif'] ?? '') !== '', fn (Builder $builder): Builder => $builder->where('actif', $filters['actif'] === '1'));
        $this->applyTextSearch($query, (string) ($filters['q'] ?? ''), ['code', 'libelle']);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Service>
     */
    public function serviceQuery(User $viewer, array $filters): Builder
    {
        $query = Service::query()
            ->with('direction:id,code,libelle')
            ->withCount(['users', 'ptas']);
        $this->scopeServices($query, $viewer);

        $query->when(($filters['direction_id'] ?? null) !== null, fn (Builder $builder): Builder => $builder->where('direction_id', (int) $filters['direction_id']));
        $query->when(($filters['actif'] ?? '') !== '', fn (Builder $builder): Builder => $builder->where('actif', $filters['actif'] === '1'));
        $this->applyTextSearch($query, (string) ($filters['q'] ?? ''), ['code', 'libelle']);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<User>
     */
    public function userQuery(User $viewer, array $filters): Builder
    {
        $query = User::query()->with([
            'direction:id,code,libelle',
            'service:id,direction_id,code,libelle',
            'uniteDg:id,code,libelle',
        ]);
        $this->scopeUsers($query, $viewer);

        $query->when(($filters['direction_id'] ?? null) !== null, fn (Builder $builder): Builder => $builder->where('direction_id', (int) $filters['direction_id']));
        $query->when(($filters['service_id'] ?? null) !== null, fn (Builder $builder): Builder => $builder->where('service_id', (int) $filters['service_id']));
        $query->when(($filters['role'] ?? '') !== '', function (Builder $builder) use ($filters): void {
            $role = (string) $filters['role'];
            if ($this->roleRegistry->isCustomRole($role)) {
                $builder->where('custom_role_code', $role);

                return;
            }

            $builder->where('role', $role)
                ->where(function (Builder $roleQuery): void {
                    $roleQuery->whereNull('custom_role_code')->orWhere('custom_role_code', '');
                });
        });
        $this->applyTextSearch($query, (string) ($filters['q'] ?? ''), [
            'name',
            'email',
            'agent_matricule',
            'agent_fonction',
        ]);
        $this->applyUserState($query, (string) ($filters['account_state'] ?? ''));

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $columns
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateUsers(Builder $query, array $filters, array $columns = ['*']): LengthAwarePaginator
    {
        match ((string) ($filters['sort'] ?? 'name')) {
            'recent' => $query->latest('id'),
            'role' => $query->orderBy('role')->orderBy('name'),
            default => $query->orderBy('name'),
        };

        return $query->paginate(
            (int) ($filters['per_page'] ?? 30),
            $columns,
            'page',
            (int) ($filters['page'] ?? 1)
        )->withQueryString();
    }

    /**
     * @return array{code:string,label:string,tone:string}
     */
    public function userHealth(User $user): array
    {
        if (! $user->is_active) {
            return ['code' => 'inactive', 'label' => 'Inactif', 'tone' => 'danger'];
        }

        if ($user->isSuspended()) {
            return ['code' => 'suspended', 'label' => 'Suspendu', 'tone' => 'warning'];
        }

        if ($user->password_changed_at === null) {
            return ['code' => 'renewal', 'label' => 'Renouvellement requis', 'tone' => 'info'];
        }

        if ($this->userNeedsScope($user)) {
            return ['code' => 'unscoped', 'label' => 'Rattachement incomplet', 'tone' => 'warning'];
        }

        return ['code' => 'active', 'label' => 'Opérationnel', 'tone' => 'success'];
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $filters
     */
    public function writeDirectionsCsv($stream, User $viewer, array $filters): void
    {
        $this->assertStream($stream);
        $this->writeCsvHeader($stream, ['ID', 'Code', 'Libellé', 'État', 'Services', 'Utilisateurs', 'PAO', 'PTA']);

        foreach ($this->directionQuery($viewer, $filters)->reorder()->lazyById(500) as $direction) {
            $this->writeCsvRow($stream, [
                $direction->id,
                $direction->code,
                $direction->libelle,
                $direction->actif ? 'Actif' : 'Inactif',
                $direction->services_count,
                $direction->users_count,
                $direction->paos_count,
                $direction->ptas_count,
            ]);
        }
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $filters
     */
    public function writeServicesCsv($stream, User $viewer, array $filters): void
    {
        $this->assertStream($stream);
        $this->writeCsvHeader($stream, ['ID', 'Direction', 'Code', 'Libellé', 'État', 'Utilisateurs', 'PTA']);

        foreach ($this->serviceQuery($viewer, $filters)->reorder()->lazyById(500) as $service) {
            $this->writeCsvRow($stream, [
                $service->id,
                $service->direction?->code,
                $service->code,
                $service->libelle,
                $service->actif ? 'Actif' : 'Inactif',
                $service->users_count,
                $service->ptas_count,
            ]);
        }
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $filters
     */
    public function writeUsersCsv($stream, User $viewer, array $filters): void
    {
        $this->assertStream($stream);
        $this->writeCsvHeader($stream, [
            'ID',
            'Nom',
            'Email',
            'Rôle',
            'Direction',
            'Service ou unité',
            'Matricule',
            'Fonction',
            'Téléphone',
            'Santé du compte',
        ]);

        foreach ($this->userQuery($viewer, $filters)->reorder()->lazyById(500) as $user) {
            $this->writeCsvRow($stream, [
                $user->id,
                $user->name,
                $user->email,
                $user->roleLabel(),
                $user->direction?->code,
                $user->service?->code ?? $user->uniteDg?->code,
                $user->agent_matricule,
                $user->agent_fonction,
                $user->agent_telephone,
                $this->userHealth($user)['label'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Direction>
     */
    private function paginateDirections(Builder $query, array $filters): LengthAwarePaginator
    {
        match ((string) ($filters['sort'] ?? 'code')) {
            'libelle' => $query->orderBy('libelle'),
            'size' => $query->orderByDesc('users_count')->orderBy('code'),
            default => $query->orderBy('code'),
        };

        return $query->paginate((int) $filters['per_page'], ['*'], 'page', (int) $filters['page'])->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Service>
     */
    private function paginateServices(Builder $query, array $filters): LengthAwarePaginator
    {
        match ((string) ($filters['sort'] ?? 'code')) {
            'libelle' => $query->orderBy('libelle'),
            'size' => $query->orderByDesc('users_count')->orderBy('code'),
            default => $query->orderBy('direction_id')->orderBy('code'),
        };

        return $query->paginate((int) $filters['per_page'], ['*'], 'page', (int) $filters['page'])->withQueryString();
    }

    private function scopeDirections(Builder $query, User $viewer): void
    {
        if ($viewer->hasGlobalReadAccess()) {
            return;
        }

        $viewer->direction_id !== null
            ? $query->whereKey((int) $viewer->direction_id)
            : $query->whereRaw('1 = 0');
    }

    private function scopeServices(Builder $query, User $viewer): void
    {
        if (! $viewer->hasGlobalReadAccess()) {
            $viewer->direction_id !== null
                ? $query->where('direction_id', (int) $viewer->direction_id)
                : $query->whereRaw('1 = 0');
        }

        if ($viewer->hasRole(User::ROLE_SERVICE) && $viewer->service_id !== null) {
            $query->whereKey((int) $viewer->service_id);
        }
    }

    private function scopeUsers(Builder $query, User $viewer): void
    {
        if (! $viewer->isSuperAdmin()) {
            $query->where('role', '!=', User::ROLE_SUPER_ADMIN);
        }

        if ($viewer->isPlanningControlChief()) {
            $query->whereIn('role', [User::ROLE_DIRECTION, User::ROLE_SERVICE, User::ROLE_AGENT]);
        }

        if (! $viewer->hasGlobalReadAccess()) {
            $viewer->direction_id !== null
                ? $query->where('direction_id', (int) $viewer->direction_id)
                : $query->whereRaw('1 = 0');
        }

        if ($viewer->hasRole(User::ROLE_SERVICE) && $viewer->service_id !== null) {
            $query->where('service_id', (int) $viewer->service_id);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function applyTextSearch(Builder $query, string $search, array $columns): void
    {
        if ($search === '') {
            return;
        }

        $pattern = '%'.trim($search).'%';
        $query->where(function (Builder $searchQuery) use ($columns, $pattern): void {
            foreach ($columns as $index => $column) {
                $index === 0
                    ? $searchQuery->whereLike($column, $pattern)
                    : $searchQuery->orWhereLike($column, $pattern);
            }
        });
    }

    private function applyUserState(Builder $query, string $state): void
    {
        match ($state) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'suspended' => $query->where('suspended_until', '>', now()),
            'renewal' => $query->whereNull('password_changed_at'),
            'unscoped' => $this->applyUnscopedUsers($query),
            default => null,
        };
    }

    private function applyUnscopedUsers(Builder $query): Builder
    {
        return $query->where(function (Builder $scopeQuery): void {
            $scopeQuery
                ->where(fn (Builder $builder): Builder => $builder->whereIn('role', self::DIRECTION_SCOPED_ROLES)->whereNull('direction_id'))
                ->orWhere(fn (Builder $builder): Builder => $builder->whereIn('role', self::SERVICE_SCOPED_ROLES)->whereNull('service_id'))
                ->orWhere(fn (Builder $builder): Builder => $builder->whereIn('role', self::UNIT_SCOPED_ROLES)->whereNull('unite_dg_id'));
        });
    }

    private function countUsersForState(Builder $query, string $state): int
    {
        $scoped = clone $query;
        $this->applyUserState($scoped, $state);

        return $scoped->count();
    }

    private function userNeedsScope(User $user): bool
    {
        $role = (string) $user->role;

        return (in_array($role, self::DIRECTION_SCOPED_ROLES, true) && $user->direction_id === null)
            || (in_array($role, self::SERVICE_SCOPED_ROLES, true) && $user->service_id === null)
            || (in_array($role, self::UNIT_SCOPED_ROLES, true) && $user->unite_dg_id === null);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function scalar(array $input, string $key, string $default = ''): string
    {
        $value = $input[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $choices
     */
    private function choice(array $input, string $key, array $choices, string $default = ''): string
    {
        $value = strtolower($this->scalar($input, $key, $default));

        return in_array($value, $choices, true) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function booleanFilter(array $input, string $key, string $default = ''): string
    {
        $value = $this->scalar($input, $key, $default);
        if (array_key_exists($key, $input) && $value === '') {
            return '';
        }

        return in_array($value, ['0', '1'], true) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function legacyAccountState(array $input): string
    {
        $value = $this->booleanFilter($input, 'is_active');

        return match ($value) {
            '1' => 'active',
            '0' => 'inactive',
            default => '',
        };
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function perPage(mixed $value): int
    {
        $perPage = $this->positiveInteger($value) ?? 30;

        return in_array($perPage, [15, 30, 50, 100], true) ? $perPage : 30;
    }

    /**
     * @param  resource  $stream
     */
    private function assertStream($stream): void
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Le flux CSV est invalide.');
        }
    }

    /**
     * @param  resource  $stream
     * @param  list<string>  $columns
     */
    private function writeCsvHeader($stream, array $columns): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $columns, ';', '"', '');
    }

    /**
     * @param  resource  $stream
     * @param  list<mixed>  $values
     */
    private function writeCsvRow($stream, array $values): void
    {
        fputcsv(
            $stream,
            array_map(fn (mixed $value): string => $this->csvCell($value), $values),
            ';',
            '"',
            ''
        );
    }

    private function csvCell(mixed $value): string
    {
        $cell = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $cell) === 1 ? "'".$cell : $cell;
    }
}
