<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentPolicySettings
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $resolved = null;

    private ?bool $tableAvailable = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $settings = $this->defaults();

        if ($this->hasSettingsTable()) {
            $stored = PlatformSetting::query()
                ->where('group', 'document_policy')
                ->pluck('value', 'key')
                ->all();

            foreach (array_keys($settings) as $key) {
                if (! array_key_exists($key, $stored)) {
                    continue;
                }

                $decoded = json_decode((string) $stored[$key], true);
                if ($decoded !== null) {
                    $settings[$key] = $decoded;
                }
            }
        }

        $settings['allowed_extensions'] = $this->sanitizeExtensions($settings['allowed_extensions'] ?? []);
        $settings['upload_roles'] = $this->sanitizeRoles($settings['upload_roles'] ?? []);
        $settings['view_roles'] = $this->sanitizeRoles($settings['view_roles'] ?? []);
        $settings['category_visibility'] = $this->sanitizeCategoryVisibility($settings['category_visibility'] ?? []);
        $settings['max_upload_mb'] = max(1, min(50, (int) ($settings['max_upload_mb'] ?? 10)));
        $settings['retention_days'] = max(30, min(3650, (int) ($settings['retention_days'] ?? 365)));

        return $this->resolved = $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'],
            'max_upload_mb' => 10,
            'retention_days' => 365,
            'upload_roles' => [
                User::ROLE_AGENT,
                User::ROLE_SERVICE,
                User::ROLE_DIRECTION,
                User::ROLE_PLANIFICATION,
                User::ROLE_ADMIN,
                User::ROLE_SUPER_ADMIN,
            ],
            'view_roles' => [
                User::ROLE_SERVICE,
                User::ROLE_DIRECTION,
                User::ROLE_PLANIFICATION,
                User::ROLE_DG,
                User::ROLE_ADMIN,
                User::ROLE_SUPER_ADMIN,
            ],
            'category_visibility' => [
                'hebdomadaire' => [User::ROLE_AGENT, User::ROLE_SERVICE, User::ROLE_DIRECTION, User::ROLE_PLANIFICATION, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN],
                'final' => [User::ROLE_SERVICE, User::ROLE_DIRECTION, User::ROLE_PLANIFICATION, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN],
                'evaluation_chef' => [User::ROLE_SERVICE, User::ROLE_DIRECTION, User::ROLE_PLANIFICATION, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN],
                'evaluation_direction' => [User::ROLE_DIRECTION, User::ROLE_PLANIFICATION, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN],
                'financement' => [User::ROLE_DIRECTION, User::ROLE_PLANIFICATION, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        /** @var list<string> $extensions */
        $extensions = $this->all()['allowed_extensions'];

        return $extensions;
    }

    public function maxUploadKilobytes(): int
    {
        return $this->maxUploadMb() * 1024;
    }

    public function maxUploadMb(): int
    {
        return (int) $this->all()['max_upload_mb'];
    }

    public function retentionDays(): int
    {
        return (int) $this->all()['retention_days'];
    }

    /**
     * @return list<string>
     */
    public function uploadRoles(): array
    {
        /** @var list<string> $roles */
        $roles = $this->all()['upload_roles'];

        return $roles;
    }

    /**
     * @return list<string>
     */
    public function viewRoles(): array
    {
        /** @var list<string> $roles */
        $roles = $this->all()['view_roles'];

        return $roles;
    }

    /**
     * @return array<string, list<string>>
     */
    public function categoryVisibility(): array
    {
        /** @var array<string, list<string>> $visibility */
        $visibility = $this->all()['category_visibility'];

        return $visibility;
    }

    public function acceptAttribute(): string
    {
        return collect($this->allowedExtensions())
            ->map(fn (string $extension): string => '.'.$extension)
            ->implode(',');
    }

    public function mimesRule(): string
    {
        return 'mimes:'.implode(',', $this->allowedExtensions());
    }

    public function canUpload(User $user): bool
    {
        if ($user->isSuperAdmin() || $user->hasRole(User::ROLE_ADMIN)) {
            return true;
        }

        return in_array($user->effectiveRoleCode(), $this->uploadRoles(), true);
    }

    public function canView(User $user): bool
    {
        if ($user->isSuperAdmin() || $user->hasRole(User::ROLE_ADMIN)) {
            return true;
        }

        return in_array($user->effectiveRoleCode(), $this->viewRoles(), true);
    }

    public function canViewCategory(User $user, ?string $category): bool
    {
        if ($user->isSuperAdmin() || $user->hasRole(User::ROLE_ADMIN)) {
            return true;
        }

        $category = trim((string) $category);
        if ($category === '') {
            return $this->canView($user);
        }

        $visibleRoles = $this->categoryVisibility()[$category] ?? $this->viewRoles();

        return in_array($user->effectiveRoleCode(), $visibleRoles, true);
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return [
            'extensions_total' => count($this->allowedExtensions()),
            'upload_roles_total' => count($this->uploadRoles()),
            'view_roles_total' => count($this->viewRoles()),
            'retention_days' => $this->retentionDays(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(array $payload, ?User $actor = null): array
    {
        $settings = $this->normalizePayload($payload);

        foreach ($settings as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'document_policy', 'key' => $key],
                [
                    'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'updated_by' => $actor?->id,
                ]
            );
        }

        $this->flush();

        return $this->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload): array
    {
        $extensions = $this->sanitizeExtensions(
            preg_split('/\r\n|\r|\n/', (string) Arr::get($payload, 'allowed_extensions', '')) ?: []
        );

        if ($extensions === []) {
            throw ValidationException::withMessages([
                'allowed_extensions' => 'Renseignez au moins une extension documentaire autorisee.',
            ]);
        }

        $uploadRoles = $this->sanitizeRoles(Arr::wrap($payload['upload_roles'] ?? []));
        $viewRoles = $this->sanitizeRoles(Arr::wrap($payload['view_roles'] ?? []));

        if ($uploadRoles === []) {
            throw ValidationException::withMessages([
                'upload_roles' => 'Selectionnez au moins un role autorise a televerser des justificatifs.',
            ]);
        }

        if ($viewRoles === []) {
            throw ValidationException::withMessages([
                'view_roles' => 'Selectionnez au moins un role autorise a consulter les justificatifs.',
            ]);
        }

        return [
            'allowed_extensions' => $extensions,
            'max_upload_mb' => max(1, min(50, (int) Arr::get($payload, 'max_upload_mb', 10))),
            'retention_days' => max(30, min(3650, (int) Arr::get($payload, 'retention_days', 365))),
            'upload_roles' => $uploadRoles,
            'view_roles' => $viewRoles,
            'category_visibility' => $this->sanitizeCategoryVisibility([
                'hebdomadaire' => Arr::wrap($payload['category_visibility']['hebdomadaire'] ?? []),
                'final' => Arr::wrap($payload['category_visibility']['final'] ?? []),
                'evaluation_chef' => Arr::wrap($payload['category_visibility']['evaluation_chef'] ?? []),
                'evaluation_direction' => Arr::wrap($payload['category_visibility']['evaluation_direction'] ?? []),
                'financement' => Arr::wrap($payload['category_visibility']['financement'] ?? []),
            ]),
        ];
    }

    public function flush(): void
    {
        $this->resolved = null;
        $this->tableAvailable = null;
    }

    /**
     * @param  iterable<int, mixed>  $extensions
     * @return list<string>
     */
    private function sanitizeExtensions(iterable $extensions): array
    {
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'webp', 'csv'];

        return collect($extensions)
            ->map(fn ($extension): string => Str::lower(trim((string) $extension)))
            ->map(fn (string $extension): string => ltrim($extension, '.'))
            ->filter(fn (string $extension): bool => in_array($extension, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int, mixed>  $roles
     * @return list<string>
     */
    private function sanitizeRoles(iterable $roles): array
    {
        $allowed = [
            User::ROLE_AGENT,
            User::ROLE_SERVICE,
            User::ROLE_DIRECTION,
            User::ROLE_PLANIFICATION,
            User::ROLE_DG,
            User::ROLE_CABINET,
            User::ROLE_ADMIN,
            User::ROLE_SUPER_ADMIN,
        ];

        return collect($roles)
            ->map(fn ($role): string => trim((string) $role))
            ->filter(fn (string $role): bool => in_array($role, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $visibility
     * @return array<string, list<string>>
     */
    private function sanitizeCategoryVisibility(array $visibility): array
    {
        $defaults = $this->defaults()['category_visibility'];
        $resolved = [];

        foreach ($defaults as $category => $roles) {
            $sanitized = $this->sanitizeRoles(Arr::wrap($visibility[$category] ?? $roles));
            $resolved[$category] = $sanitized !== [] ? $sanitized : $roles;
        }

        return $resolved;
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
