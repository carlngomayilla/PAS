<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PlatformSettings
{
    private const GROUP_PUBLISHED = 'general';
    private const GROUP_DRAFT = 'general_draft';

    /**
     * @var array<string, string>|null
     */
    private ?array $resolved = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $draftResolved = null;

    private ?bool $tableAvailable = null;

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        return $this->resolved = $this->readGroup(self::GROUP_PUBLISHED);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function defaults(): array
    {
        return [
            'app_name' => 'PAS ANBG',
            'app_short_name' => 'ANBG',
            'institution_label' => 'Agence Nationale des Bourses du Gabon',
            'default_locale' => 'fr',
            'default_timezone' => 'Africa/Libreville',
            'date_format' => 'd/m/Y',
            'datetime_format' => 'd/m/Y H:i',
            'number_precision' => '2',
            'number_decimal_separator' => ',',
            'number_thousands_separator' => ' ',
            'sidebar_caption' => 'PILOTAGE',
            'admin_header_eyebrow' => 'Administration',
            'guest_space_label' => 'Espace invite',
            'login_page_title' => 'Connexion - PAS',
            'login_welcome_title' => "Bienvenue dans l'espace ANBG",
            'login_welcome_text' => 'Tire sur la corde puis connecte-toi a ton espace de pilotage.',
            'login_form_title' => 'Connexion',
            'login_form_subtitle' => 'Accede a ton espace.',
            'login_identifier_label' => 'Email ou matricule',
            'login_identifier_placeholder' => 'ex: admin@anbg.ga ou ADM-001',
            'login_helper_text' => 'Identifiants de demonstration disponibles sur l environnement local.',
            'footer_text' => 'ANBG | Systeme institutionnel de pilotage PAS / PAO / PTA',
            'logo_mark_path' => '',
            'logo_wordmark_path' => '',
            'logo_full_path' => '',
            'favicon_path' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function localeOptions(): array
    {
        return [
            'fr' => 'Francais',
            'en' => 'English',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function timezoneOptions(): array
    {
        return [
            'Africa/Libreville' => 'Africa/Libreville',
            'UTC' => 'UTC',
            'Europe/Paris' => 'Europe/Paris',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function dateFormatOptions(): array
    {
        return [
            'd/m/Y' => '31/12/2026',
            'Y-m-d' => '2026-12-31',
            'd-m-Y' => '31-12-2026',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function dateTimeFormatOptions(): array
    {
        return [
            'd/m/Y H:i' => '31/12/2026 14:30',
            'Y-m-d H:i' => '2026-12-31 14:30',
            'd-m-Y H:i' => '31-12-2026 14:30',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function numberPrecisionOptions(): array
    {
        return [
            '0' => '0 decimale',
            '1' => '1 decimale',
            '2' => '2 decimales',
            '3' => '3 decimales',
        ];
    }

    public function locale(): string
    {
        $locale = strtolower(trim((string) $this->get('default_locale', 'fr')));

        return array_key_exists($locale, $this->localeOptions()) ? $locale : 'fr';
    }

    public function htmlLang(): string
    {
        return str_replace('_', '-', $this->locale());
    }

    public function timezone(): string
    {
        $timezone = trim((string) $this->get('default_timezone', 'Africa/Libreville'));

        return array_key_exists($timezone, $this->timezoneOptions())
            ? $timezone
            : 'Africa/Libreville';
    }

    public function dateFormat(): string
    {
        $format = trim((string) $this->get('date_format', 'd/m/Y'));

        return array_key_exists($format, $this->dateFormatOptions()) ? $format : 'd/m/Y';
    }

    public function dateTimeFormat(): string
    {
        $format = trim((string) $this->get('datetime_format', 'd/m/Y H:i'));

        return array_key_exists($format, $this->dateTimeFormatOptions()) ? $format : 'd/m/Y H:i';
    }

    public function numberPrecision(): int
    {
        return max(0, min(3, (int) $this->get('number_precision', '2')));
    }

    public function numberDecimalSeparator(): string
    {
        return (string) ($this->get('number_decimal_separator', ',') ?: ',');
    }

    public function numberThousandsSeparator(): string
    {
        return (string) ($this->get('number_thousands_separator', ' ') ?: ' ');
    }

    public function brandAssetUrl(string $variant = 'full'): string
    {
        $settingKey = match ($variant) {
            'mark' => 'logo_mark_path',
            'wordmark' => 'logo_wordmark_path',
            default => 'logo_full_path',
        };

        $storedPath = trim((string) $this->get($settingKey, ''));
        if ($storedPath !== '') {
            return $this->publicStorageUrl($storedPath);
        }

        return match ($variant) {
            'mark' => asset('images/logo-mark.png'),
            'wordmark' => asset('images/logo-wordmark.png'),
            default => asset('images/logo-full.png'),
        };
    }

    public function faviconUrl(): string
    {
        $storedPath = trim((string) $this->get('favicon_path', ''));
        if ($storedPath !== '') {
            return $this->publicStorageUrl($storedPath);
        }

        return $this->brandAssetUrl('mark');
    }

    public function formatDate(DateTimeInterface|string|null $value, ?string $fallback = '-'): string
    {
        $date = $this->normalizeDateValue($value);

        return $date?->timezone($this->timezone())->format($this->dateFormat()) ?? (string) $fallback;
    }

    public function formatDateTime(DateTimeInterface|string|null $value, ?string $fallback = '-'): string
    {
        $date = $this->normalizeDateValue($value);

        return $date?->timezone($this->timezone())->format($this->dateTimeFormat()) ?? (string) $fallback;
    }

    public function formatNumber(int|float|string|null $value, ?int $precision = null): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format(
            (float) $value,
            $precision ?? $this->numberPrecision(),
            $this->numberDecimalSeparator(),
            $this->numberThousandsSeparator()
        );
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return array<string, string>
     */
    public function updateGeneral(array $payload, ?User $actor = null): array
    {
        $this->writeGroup(self::GROUP_PUBLISHED, $payload, $actor);

        $this->flush();

        return $this->all();
    }

    /**
     * @return array<string, string>
     */
    public function editable(): array
    {
        return $this->hasDraft() ? $this->draft() : $this->all();
    }

    /**
     * @return array<string, string>
     */
    public function draft(): array
    {
        if ($this->draftResolved !== null) {
            return $this->draftResolved;
        }

        return $this->draftResolved = $this->readGroup(self::GROUP_DRAFT, $this->all());
    }

    public function hasDraft(): bool
    {
        if (! $this->hasSettingsTable()) {
            return false;
        }

        return PlatformSetting::query()
            ->where('group', self::GROUP_DRAFT)
            ->exists();
    }

    public function draftUpdatedAt(): ?string
    {
        if (! $this->hasDraft()) {
            return null;
        }

        return PlatformSetting::query()
            ->where('group', self::GROUP_DRAFT)
            ->max('updated_at');
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return array<string, string>
     */
    public function updateDraft(array $payload, ?User $actor = null): array
    {
        $this->writeGroup(self::GROUP_DRAFT, $payload, $actor);
        $this->flush();

        return $this->draft();
    }

    /**
     * @return array<string, string>
     */
    public function publishDraft(?User $actor = null): array
    {
        if (! $this->hasDraft()) {
            return $this->all();
        }

        $this->writeGroup(self::GROUP_PUBLISHED, $this->draft(), $actor);
        PlatformSetting::query()->where('group', self::GROUP_DRAFT)->delete();
        $this->flush();

        return $this->all();
    }

    public function discardDraft(): void
    {
        if (! $this->hasSettingsTable()) {
            return;
        }

        PlatformSetting::query()->where('group', self::GROUP_DRAFT)->delete();
        $this->flush();
    }

    public function flush(): void
    {
        $this->resolved = null;
        $this->draftResolved = null;
        $this->tableAvailable = null;
    }

    /**
     * @param  array<string, string>|null  $seed
     * @return array<string, string>
     */
    private function readGroup(string $group, ?array $seed = null): array
    {
        $settings = $seed ?? $this->defaults();

        if ($this->hasSettingsTable()) {
            $stored = PlatformSetting::query()
                ->where('group', $group)
                ->pluck('value', 'key')
                ->map(fn ($value): string => (string) $value)
                ->all();

            foreach ($this->defaults() as $key => $defaultValue) {
                $storageKey = $this->storageKey($group, $key);
                if (array_key_exists($storageKey, $stored)) {
                    $settings[$key] = (string) $stored[$storageKey];
                }
            }
        }

        return $settings;
    }

    /**
     * @param  array<string, string|null>  $payload
     */
    private function writeGroup(string $group, array $payload, ?User $actor = null): void
    {
        $current = $group === self::GROUP_PUBLISHED ? $this->all() : $this->editable();

        foreach ($this->defaults() as $key => $defaultValue) {
            $value = trim((string) ($payload[$key] ?? $current[$key] ?? $defaultValue));

            PlatformSetting::query()->updateOrCreate(
                ['group' => $group, 'key' => $this->storageKey($group, $key)],
                ['value' => $value, 'updated_by' => $actor?->id]
            );
        }
    }

    private function storageKey(string $group, string $key): string
    {
        return $group === self::GROUP_DRAFT ? 'general_draft_'.$key : $key;
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

    private function publicStorageUrl(string $path): string
    {
        $normalizedPath = trim(Str::replace('\\', '/', $path), '/');

        if ($normalizedPath === '') {
            return $this->brandAssetUrl();
        }

        return Storage::disk('public')->url($normalizedPath);
    }

    private function normalizeDateValue(DateTimeInterface|string|null $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance((clone $value));
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $this->timezone());
        } catch (\Throwable) {
            return null;
        }
    }
}



