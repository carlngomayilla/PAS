<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class AppearanceSettings
{
    private const GROUP_PUBLISHED = 'appearance';
    private const GROUP_DRAFT = 'appearance_draft';

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
     * @param  array<string, string|null>  $payload
     * @return array<string, string>
     */
    public function updateAppearance(array $payload, ?User $actor = null): array
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

    /**
     * @param  array<string, string|null>  $payload
     * @return array<string, string>
     */
    public function resolveDraft(array $payload): array
    {
        $defaults = $this->defaults();

        $resolved = $defaults;

        $colorKeys = [
            'primary_color',
            'secondary_color',
            'surface_color',
            'success_color',
            'accent_color',
            'warning_color',
            'danger_color',
            'text_color',
            'muted_text_color',
            'border_color',
            'card_background_color',
            'input_background_color',
        ];

        foreach ($colorKeys as $key) {
            $resolved[$key] = $this->sanitizeHex((string) ($payload[$key] ?? $defaults[$key]), $defaults[$key]);
        }

        $resolved['font_family'] = $this->sanitizeOption((string) ($payload['font_family'] ?? $defaults['font_family']), $this->fontOptions(), $defaults['font_family']);
        $resolved['heading_font_family'] = $this->sanitizeOption((string) ($payload['heading_font_family'] ?? $defaults['heading_font_family']), $this->headingFontOptions(), $defaults['heading_font_family']);
        $resolved['default_theme'] = $this->sanitizeOption((string) ($payload['default_theme'] ?? $defaults['default_theme']), array_keys($this->themeOptions()), $defaults['default_theme']);
        $resolved['sidebar_style'] = $this->sanitizeOption((string) ($payload['sidebar_style'] ?? $defaults['sidebar_style']), array_keys($this->sidebarStyleOptions()), $defaults['sidebar_style']);
        $resolved['header_style'] = $this->sanitizeOption((string) ($payload['header_style'] ?? $defaults['header_style']), array_keys($this->headerStyleOptions()), $defaults['header_style']);
        $resolved['page_background_style'] = $this->sanitizeOption((string) ($payload['page_background_style'] ?? $defaults['page_background_style']), array_keys($this->pageBackgroundStyleOptions()), $defaults['page_background_style']);
        $resolved['card_style'] = $this->sanitizeOption((string) ($payload['card_style'] ?? $defaults['card_style']), array_keys($this->cardStyleOptions()), $defaults['card_style']);
        $resolved['button_style'] = $this->sanitizeOption((string) ($payload['button_style'] ?? $defaults['button_style']), array_keys($this->buttonStyleOptions()), $defaults['button_style']);
        $resolved['input_style'] = $this->sanitizeOption((string) ($payload['input_style'] ?? $defaults['input_style']), array_keys($this->inputStyleOptions()), $defaults['input_style']);
        $resolved['table_style'] = $this->sanitizeOption((string) ($payload['table_style'] ?? $defaults['table_style']), array_keys($this->tableStyleOptions()), $defaults['table_style']);
        $resolved['card_shadow_strength'] = $this->sanitizeOption((string) ($payload['card_shadow_strength'] ?? $defaults['card_shadow_strength']), array_keys($this->cardShadowOptions()), $defaults['card_shadow_strength']);
        $resolved['visual_density'] = $this->sanitizeOption((string) ($payload['visual_density'] ?? $defaults['visual_density']), array_keys($this->densityOptions()), $defaults['visual_density']);
        $resolved['content_width'] = $this->sanitizeOption((string) ($payload['content_width'] ?? $defaults['content_width']), array_keys($this->contentWidthOptions()), $defaults['content_width']);
        $resolved['sidebar_width'] = $this->sanitizeOption((string) ($payload['sidebar_width'] ?? $defaults['sidebar_width']), array_keys($this->sidebarWidthOptions()), $defaults['sidebar_width']);

        $resolved['card_radius'] = $this->sanitizeRadius((string) ($payload['card_radius'] ?? $defaults['card_radius']), $defaults['card_radius']);
        $resolved['button_radius'] = $this->sanitizeRadius((string) ($payload['button_radius'] ?? $defaults['button_radius']), $defaults['button_radius']);
        $resolved['input_radius'] = $this->sanitizeRadius((string) ($payload['input_radius'] ?? $defaults['input_radius']), $defaults['input_radius']);
        $resolved['card_blur'] = $this->sanitizePixel((string) ($payload['card_blur'] ?? $defaults['card_blur']), $defaults['card_blur']);

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    public function defaults(): array
    {
        return [
            'primary_color' => '#243B5A',
            'secondary_color' => '#516B8B',
            'surface_color' => '#162338',
            'success_color' => '#607861',
            'accent_color' => '#A78F63',
            'warning_color' => '#8E6A38',
            'danger_color' => '#8B4D4A',
            'text_color' => '#0F172A',
            'muted_text_color' => '#64748B',
            'border_color' => '#CBD5E1',
            'card_background_color' => '#FFFFFF',
            'input_background_color' => '#FFFFFF',
            'font_family' => 'Public Sans',
            'heading_font_family' => 'Source Serif 4',
            'default_theme' => 'dark',
            'sidebar_style' => 'aurora',
            'header_style' => 'glass',
            'page_background_style' => 'aurora',
            'card_style' => 'glass',
            'button_style' => 'gradient',
            'input_style' => 'soft',
            'table_style' => 'soft',
            'card_radius' => '1.5rem',
            'button_radius' => '1.25rem',
            'input_radius' => '0.85rem',
            'card_shadow_strength' => 'soft',
            'card_blur' => '4px',
            'visual_density' => 'comfortable',
            'content_width' => 'wide',
            'sidebar_width' => 'normal',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function cssVariables(?array $settings = null): array
    {
        $settings = $this->resolveDraft($settings ?? $this->all());

        $primary = $this->sanitizeHex($settings['primary_color']);
        $secondary = $this->sanitizeHex($settings['secondary_color']);
        $surface = $this->sanitizeHex($settings['surface_color']);
        $success = $this->sanitizeHex($settings['success_color']);
        $accent = $this->sanitizeHex($settings['accent_color']);
        $warning = $this->sanitizeHex($settings['warning_color']);
        $danger = $this->sanitizeHex($settings['danger_color']);
        $text = $this->sanitizeHex($settings['text_color']);
        $muted = $this->sanitizeHex($settings['muted_text_color']);
        $border = $this->sanitizeHex($settings['border_color']);
        $cardBackground = $this->sanitizeHex($settings['card_background_color']);
        $inputBackground = $this->sanitizeHex($settings['input_background_color']);

        return [
            '--app-primary' => $primary,
            '--app-secondary' => $secondary,
            '--app-surface' => $surface,
            '--app-success' => $success,
            '--app-accent' => $accent,
            '--app-warning' => $warning,
            '--app-danger' => $danger,
            '--app-text-color' => $text,
            '--app-muted-text-color' => $muted,
            '--app-border-color' => $border,
            '--app-card-background-color' => $cardBackground,
            '--app-input-background-color' => $inputBackground,
            '--app-primary-rgb' => $this->hexToRgbTriplet($primary),
            '--app-secondary-rgb' => $this->hexToRgbTriplet($secondary),
            '--app-surface-rgb' => $this->hexToRgbTriplet($surface),
            '--app-success-rgb' => $this->hexToRgbTriplet($success),
            '--app-accent-rgb' => $this->hexToRgbTriplet($accent),
            '--app-warning-rgb' => $this->hexToRgbTriplet($warning),
            '--app-danger-rgb' => $this->hexToRgbTriplet($danger),
            '--app-text-color-rgb' => $this->hexToRgbTriplet($text),
            '--app-muted-text-color-rgb' => $this->hexToRgbTriplet($muted),
            '--app-border-color-rgb' => $this->hexToRgbTriplet($border),
            '--app-card-background-rgb' => $this->hexToRgbTriplet($cardBackground),
            '--app-input-background-rgb' => $this->hexToRgbTriplet($inputBackground),
            '--anbg-blue-soft' => $this->hexToRgbTriplet($primary),
            '--anbg-blue' => $this->hexToRgbTriplet($secondary),
            '--anbg-blue-deep' => $this->hexToRgbTriplet($surface),
            '--anbg-navy' => $this->hexToRgbTriplet($surface),
            '--anbg-green' => $this->hexToRgbTriplet($success),
            '--anbg-lime' => $this->hexToRgbTriplet($accent),
            '--anbg-gold' => $this->hexToRgbTriplet($warning),
            '--anbg-orange' => $this->hexToRgbTriplet($warning),
            '--app-font-family' => $this->fontStack($settings['font_family']),
            '--app-heading-font-family' => $this->headingFontStack($settings['heading_font_family']),
            '--app-card-radius' => $this->sanitizeRadius($settings['card_radius']),
            '--app-button-radius' => $this->sanitizeRadius($settings['button_radius'], '1.25rem'),
            '--app-input-radius' => $this->sanitizeRadius($settings['input_radius'], '0.85rem'),
            '--app-card-blur' => $this->sanitizePixel($settings['card_blur'], '4px'),
            '--app-density-gap' => $settings['visual_density'] === 'compact' ? '0.75rem' : '1rem',
            '--app-screen-max-width' => $this->screenMaxWidth($settings['content_width'] ?? 'wide'),
            '--app-sidebar-width' => $this->sidebarWidth($settings['sidebar_width'] ?? 'normal'),
            '--app-body-bg-light' => $this->bodyBackground(false, $settings),
            '--app-body-bg-dark' => $this->bodyBackground(true, $settings),
            '--app-header-bg-light' => $this->headerBackground(false, $settings),
            '--app-header-bg-dark' => $this->headerBackground(true, $settings),
            '--app-sidebar-bg' => $this->sidebarBackground($settings),
            '--app-card-surface-light' => $this->cardSurface(false, $settings),
            '--app-card-surface-dark' => $this->cardSurface(true, $settings),
            '--app-form-surface-light' => $this->formSurface(false, $settings),
            '--app-form-surface-dark' => $this->formSurface(true, $settings),
            '--app-card-shadow' => $this->cardShadow(false, $settings),
            '--app-card-shadow-dark' => $this->cardShadow(true, $settings),
            '--app-table-head-bg-light' => $this->tableHeadBackground(false, $settings),
            '--app-table-head-bg-dark' => $this->tableHeadBackground(true, $settings),
            '--app-table-row-hover-light' => $this->tableRowHoverBackground(false, $settings),
            '--app-table-row-hover-dark' => $this->tableRowHoverBackground(true, $settings),
            '--app-button-primary-bg' => $this->buttonBackground('primary', false, $settings),
            '--app-button-primary-bg-hover' => $this->buttonBackground('primary', true, $settings),
            '--app-button-primary-text' => $this->buttonTextColor('primary', $settings),
            '--app-button-secondary-bg' => $this->buttonBackground('secondary', false, $settings),
            '--app-button-secondary-bg-hover' => $this->buttonBackground('secondary', true, $settings),
            '--app-button-secondary-text' => $this->buttonTextColor('secondary', $settings),
            '--app-button-warning-bg' => $this->buttonBackground('warning', false, $settings),
            '--app-button-warning-bg-hover' => $this->buttonBackground('warning', true, $settings),
            '--app-button-warning-text' => $this->buttonTextColor('warning', $settings),
            '--app-button-danger-bg' => $this->buttonBackground('danger', false, $settings),
            '--app-button-danger-bg-hover' => $this->buttonBackground('danger', true, $settings),
            '--app-button-danger-text' => $this->buttonTextColor('danger', $settings),
            '--app-input-border-color' => $this->inputBorderColor($settings),
            '--app-input-surface-light' => $this->inputSurface(false, $settings),
            '--app-input-surface-dark' => $this->inputSurface(true, $settings),
        ];
    }

    public function cssVariablesInline(): string
    {
        return collect($this->cssVariables())
            ->map(fn (string $value, string $key): string => $key.': '.$value)
            ->implode('; ');
    }

    public function bodyBackground(bool $darkMode, ?array $settings = null): string
    {
        $settings = $this->resolveDraft($settings ?? $this->all());
        $style = $settings['page_background_style'] ?? 'aurora';

        if ($darkMode) {
            return match ($style) {
                'flat' => 'linear-gradient(180deg, rgb(var(--app-surface-rgb)) 0%, rgb(12 18 30) 100%)',
                'soft' => 'radial-gradient(circle at top left, rgb(var(--app-primary-rgb) / 0.1) 0%, transparent 26%), linear-gradient(180deg, rgb(var(--app-surface-rgb)) 0%, rgb(16 24 38) 100%)',
                default => 'radial-gradient(circle at top left, rgb(var(--app-primary-rgb) / 0.14) 0%, transparent 24%), radial-gradient(circle at top right, rgb(var(--app-accent-rgb) / 0.08) 0%, transparent 18%), linear-gradient(180deg, rgb(var(--app-surface-rgb)) 0%, rgb(18 28 44) 56%, rgb(12 18 30) 100%)',
            };
        }

        return match ($style) {
            'flat' => 'linear-gradient(180deg, #ffffff 0%, #f1f5f9 100%)',
            'soft' => 'radial-gradient(circle at top left, rgb(var(--app-primary-rgb) / 0.06) 0%, transparent 24%), linear-gradient(180deg, #ffffff 0%, #f7f8fb 54%, #eef2f5 100%)',
            default => 'radial-gradient(circle at top left, rgb(var(--app-primary-rgb) / 0.08) 0%, transparent 25%), radial-gradient(circle at top right, rgb(var(--app-accent-rgb) / 0.06) 0%, transparent 20%), linear-gradient(180deg, #ffffff 0%, #f6f8fb 52%, #eef2f6 100%)',
        };
    }

    public function headerBackground(bool $darkMode, ?array $settings = null): string
    {
        $settings = $this->resolveDraft($settings ?? $this->all());
        $style = $settings['header_style'] ?? 'glass';

        if ($darkMode) {
            return $style === 'solid'
                ? 'linear-gradient(180deg, rgb(var(--app-surface-rgb) / 0.98) 0%, rgb(var(--app-primary-rgb) / 0.76) 100%)'
                : ($style === 'soft'
                    ? 'linear-gradient(180deg, rgb(var(--app-surface-rgb) / 0.94) 0%, rgb(var(--app-secondary-rgb) / 0.18) 100%)'
                    : 'linear-gradient(180deg, rgb(var(--app-surface-rgb) / 0.95) 0%, rgb(var(--app-primary-rgb) / 0.14) 100%)');
        }

        return $style === 'solid'
            ? 'linear-gradient(135deg, rgb(255 255 255 / 0.99) 0%, rgb(var(--app-primary-rgb) / 0.1) 100%)'
            : ($style === 'soft'
                ? 'linear-gradient(135deg, rgb(255 255 255 / 0.98) 0%, rgb(var(--app-secondary-rgb) / 0.08) 100%)'
                : 'linear-gradient(135deg, rgb(255 255 255 / 0.97) 0%, rgb(var(--app-primary-rgb) / 0.08) 52%, rgb(var(--app-secondary-rgb) / 0.06) 100%)');
    }

    public function sidebarBackground(?array $settings = null): string
    {
        $settings = $this->resolveDraft($settings ?? $this->all());

        return match ($settings['sidebar_style'] ?? 'aurora') {
            'solid' => 'linear-gradient(180deg, rgb(var(--app-surface-rgb) / 0.99) 0%, rgb(var(--app-primary-rgb) / 0.84) 100%)',
            'soft' => 'linear-gradient(180deg, rgb(var(--app-surface-rgb) / 0.99) 0%, rgb(var(--app-secondary-rgb) / 0.78) 100%)',
            default => 'radial-gradient(circle at 76% 12%, rgb(var(--app-accent-rgb) / 0.12) 0%, transparent 20%), radial-gradient(circle at 28% 74%, rgb(var(--app-primary-rgb) / 0.16) 0%, transparent 28%), linear-gradient(180deg, rgb(var(--app-surface-rgb) / 0.99) 0%, rgb(var(--app-primary-rgb) / 0.72) 68%, rgb(var(--app-secondary-rgb) / 0.82) 100%)',
        };
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

            foreach ($settings as $key => $defaultValue) {
                $storageKey = $this->storageKey($group, $key);
                if (array_key_exists($storageKey, $stored)) {
                    $settings[$key] = (string) $stored[$storageKey];
                }
            }
        }

        return $this->resolveDraft($settings);
    }

    /**
     * @param  array<string, string|null>  $payload
     */
    private function writeGroup(string $group, array $payload, ?User $actor = null): void
    {
        $resolved = $this->resolveDraft($payload);

        foreach ($this->defaults() as $key => $defaultValue) {
            $value = trim((string) ($resolved[$key] ?? $defaultValue));

            PlatformSetting::query()->updateOrCreate(
                ['group' => $group, 'key' => $this->storageKey($group, $key)],
                ['value' => $value !== '' ? $value : $defaultValue, 'updated_by' => $actor?->id]
            );
        }
    }

    private function storageKey(string $group, string $key): string
    {
        return $group === self::GROUP_DRAFT
            ? 'appearance_draft_'.$key
            : 'appearance_'.$key;
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

    /**
     * @return array<int, string>
     */
    public function fontOptions(): array
    {
        return ['Public Sans', 'Inter', 'Poppins', 'Manrope'];
    }

    /**
     * @return array<int, string>
     */
    public function headingFontOptions(): array
    {
        return ['Source Serif 4', 'Public Sans', 'Poppins', 'Manrope'];
    }

    /**
     * @return array<string, string>
     */
    public function themeOptions(): array
    {
        return ['dark' => 'Sombre', 'light' => 'Clair'];
    }

    /**
     * @return array<string, string>
     */
    public function sidebarStyleOptions(): array
    {
        return ['aurora' => 'Aurora', 'solid' => 'Uni', 'soft' => 'Doux'];
    }

    /**
     * @return array<string, string>
     */
    public function headerStyleOptions(): array
    {
        return ['glass' => 'Verre', 'solid' => 'Uni', 'soft' => 'Doux'];
    }

    /**
     * @return array<string, string>
     */
    public function pageBackgroundStyleOptions(): array
    {
        return ['aurora' => 'Aurora', 'soft' => 'Doux', 'flat' => 'Plat'];
    }

    /**
     * @return array<string, string>
     */
    public function cardStyleOptions(): array
    {
        return ['glass' => 'Verre', 'soft' => 'Doux', 'flat' => 'Plat'];
    }

    /**
     * @return array<string, string>
     */
    public function buttonStyleOptions(): array
    {
        return ['gradient' => 'Degrade', 'solid' => 'Uni', 'soft' => 'Doux'];
    }

    /**
     * @return array<string, string>
     */
    public function inputStyleOptions(): array
    {
        return ['soft' => 'Doux', 'outline' => 'Contour', 'filled' => 'Rempli'];
    }

    /**
     * @return array<string, string>
     */
    public function tableStyleOptions(): array
    {
        return ['soft' => 'Doux', 'lined' => 'Ligne', 'contrast' => 'Contraste'];
    }

    /**
     * @return array<string, string>
     */
    public function densityOptions(): array
    {
        return ['comfortable' => 'Confortable', 'compact' => 'Compact'];
    }

    /**
     * @return array<string, string>
     */
    public function contentWidthOptions(): array
    {
        return ['reading' => 'Lecture', 'wide' => 'Large', 'fluid' => 'Fluide'];
    }

    /**
     * @return array<string, string>
     */
    public function cardShadowOptions(): array
    {
        return ['subtle' => 'Subtil', 'soft' => 'Normal', 'strong' => 'Fort'];
    }

    /**
     * @return array<string, string>
     */
    public function sidebarWidthOptions(): array
    {
        return ['compact' => 'Compacte', 'normal' => 'Normale', 'wide' => 'Large'];
    }

    private function sanitizeHex(string $value, string $fallback = '#243B5A'): string
    {
        $value = strtoupper(trim($value));

        if (preg_match('/^#?[0-9A-F]{6}$/', $value) !== 1) {
            return $fallback;
        }

        return str_starts_with($value, '#') ? $value : '#'.$value;
    }

    private function hexToRgbTriplet(string $hex): string
    {
        $normalized = ltrim($hex, '#');

        return implode(' ', [
            (string) hexdec(substr($normalized, 0, 2)),
            (string) hexdec(substr($normalized, 2, 2)),
            (string) hexdec(substr($normalized, 4, 2)),
        ]);
    }

    private function fontStack(string $fontFamily): string
    {
        $fontFamily = trim($fontFamily);

        return match ($fontFamily) {
            'Public Sans' => "'Public Sans', ui-sans-serif, system-ui, sans-serif",
            'Poppins' => "'Poppins', ui-sans-serif, system-ui, sans-serif",
            'Manrope' => "'Manrope', ui-sans-serif, system-ui, sans-serif",
            'Inter' => "'Public Sans', ui-sans-serif, system-ui, sans-serif",
            default => "'Public Sans', ui-sans-serif, system-ui, sans-serif",
        };
    }

    private function headingFontStack(string $fontFamily): string
    {
        $fontFamily = trim($fontFamily);

        return match ($fontFamily) {
            'Public Sans' => "'Public Sans', ui-sans-serif, system-ui, sans-serif",
            'Poppins' => "'Poppins', ui-sans-serif, system-ui, sans-serif",
            'Manrope' => "'Manrope', ui-sans-serif, system-ui, sans-serif",
            default => "'Source Serif 4', Georgia, serif",
        };
    }

    private function sanitizeRadius(string $radius, string $fallback = '1.5rem'): string
    {
        $radius = trim($radius);

        return preg_match('/^\d+(\.\d+)?(rem|px)$/', $radius) === 1 ? $radius : $fallback;
    }

    private function sanitizePixel(string $value, string $fallback): string
    {
        $value = trim($value);

        return preg_match('/^\d+(\.\d+)?px$/', $value) === 1 ? $value : $fallback;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function sanitizeOption(string $value, array $allowed, string $fallback): string
    {
        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function screenMaxWidth(string $value): string
    {
        return match (trim($value)) {
            'fluid' => '100%',
            'reading' => '1180px',
            default => '1440px',
        };
    }

    private function sidebarWidth(string $value): string
    {
        return match (trim($value)) {
            'compact' => '112px',
            'wide' => '152px',
            default => '128px',
        };
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function cardSurface(bool $darkMode, array $settings): string
    {
        $base = 'rgb(var(--app-card-background-rgb))';

        if ($darkMode) {
            return match ($settings['card_style'] ?? 'glass') {
                'flat' => 'linear-gradient(180deg, rgb(var(--app-surface-rgb) / 0.96) 0%, rgb(var(--app-surface-rgb) / 0.9) 100%)',
                'soft' => 'linear-gradient(180deg, rgb(var(--app-surface-rgb) / 0.9) 0%, rgb(var(--app-secondary-rgb) / 0.14) 100%)',
                default => 'radial-gradient(circle at top right, rgb(var(--app-primary-rgb) / 0.12) 0%, transparent 30%), linear-gradient(180deg, rgb(var(--app-surface-rgb) / 0.88) 0%, rgb(var(--app-surface-rgb) / 0.8) 100%)',
            };
        }

        return match ($settings['card_style'] ?? 'glass') {
            'flat' => 'linear-gradient(180deg, '.$base.' 0%, '.$base.' 100%)',
            'soft' => 'linear-gradient(180deg, rgb(var(--app-card-background-rgb) / 0.98) 0%, rgb(var(--app-primary-rgb) / 0.04) 100%)',
            default => 'radial-gradient(circle at top right, rgb(var(--app-primary-rgb) / 0.08) 0%, transparent 30%), linear-gradient(145deg, rgb(var(--app-card-background-rgb) / 0.99) 0%, rgb(var(--app-card-background-rgb) / 0.96) 100%)',
        };
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function formSurface(bool $darkMode, array $settings): string
    {
        if ($darkMode) {
            return 'linear-gradient(180deg, rgb(var(--app-surface-rgb) / 0.92) 0%, rgb(var(--app-surface-rgb) / 0.82) 100%)';
        }

        return match ($settings['card_style'] ?? 'glass') {
            'flat' => 'linear-gradient(180deg, rgb(var(--app-card-background-rgb) / 0.98) 0%, rgb(var(--app-card-background-rgb) / 0.98) 100%)',
            default => 'linear-gradient(180deg, rgb(var(--app-card-background-rgb) / 0.98) 0%, rgb(var(--app-card-background-rgb) / 0.92) 100%)',
        };
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function cardShadow(bool $darkMode, array $settings): string
    {
        $strength = $settings['card_shadow_strength'] ?? 'soft';

        if ($darkMode) {
            return match ($strength) {
                'subtle' => '0 16px 30px -28px rgba(0, 0, 0, 0.55)',
                'strong' => '0 30px 54px -28px rgba(0, 0, 0, 0.92)',
                default => '0 22px 48px -30px rgba(0, 0, 0, 0.86)',
            };
        }

        return match ($strength) {
            'subtle' => '0 10px 22px -18px rgba(15, 23, 42, 0.22)',
            'strong' => '0 22px 42px -22px rgba(15, 23, 42, 0.38)',
            default => '0 14px 32px -22px rgba(15, 23, 42, 0.45)',
        };
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function tableHeadBackground(bool $darkMode, array $settings): string
    {
        $style = $settings['table_style'] ?? 'soft';

        if ($darkMode) {
            return match ($style) {
                'contrast' => 'rgb(var(--app-primary-rgb) / 0.32)',
                'lined' => 'transparent',
                default => 'rgb(var(--app-surface-rgb) / 0.9)',
            };
        }

        return match ($style) {
            'contrast' => 'rgb(var(--app-primary-rgb) / 0.12)',
            'lined' => 'transparent',
            default => 'rgb(248 250 252 / 0.9)',
        };
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function tableRowHoverBackground(bool $darkMode, array $settings): string
    {
        $style = $settings['table_style'] ?? 'soft';

        if ($darkMode) {
            return match ($style) {
                'contrast' => 'rgb(var(--app-primary-rgb) / 0.22)',
                'lined' => 'rgb(var(--app-secondary-rgb) / 0.08)',
                default => 'rgba(30, 41, 59, 0.6)',
            };
        }

        return match ($style) {
            'contrast' => 'rgb(var(--app-primary-rgb) / 0.08)',
            'lined' => 'rgb(var(--app-secondary-rgb) / 0.05)',
            default => 'rgba(248, 250, 252, 0.7)',
        };
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function buttonBackground(string $tone, bool $hover, array $settings): string
    {
        $style = $settings['button_style'] ?? 'gradient';
        $map = [
            'primary' => ['from' => 'var(--app-primary)', 'to' => 'rgb(var(--app-secondary-rgb))', 'soft' => 'rgb(var(--app-primary-rgb) / 0.12)'],
            'secondary' => ['from' => 'var(--app-secondary)', 'to' => 'rgb(var(--app-primary-rgb))', 'soft' => 'rgb(var(--app-secondary-rgb) / 0.12)'],
            'warning' => ['from' => 'var(--app-warning)', 'to' => 'rgb(var(--app-accent-rgb))', 'soft' => 'rgb(var(--app-warning-rgb) / 0.12)'],
            'danger' => ['from' => 'var(--app-danger)', 'to' => 'rgb(var(--app-danger-rgb) / 0.82)', 'soft' => 'rgb(var(--app-danger-rgb) / 0.12)'],
        ][$tone];

        if ($style === 'solid') {
            return $hover ? (string) $map['to'] : (string) $map['from'];
        }

        if ($style === 'soft') {
            return $hover ? str_replace('/ 0.12', '/ 0.18', (string) $map['soft']) : (string) $map['soft'];
        }

        return 'linear-gradient(135deg, '.$map['from'].' 0%, '.$map['to'].' 100%)';
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function buttonTextColor(string $tone, array $settings): string
    {
        return ($settings['button_style'] ?? 'gradient') === 'soft'
            ? match ($tone) {
                'danger' => 'var(--app-danger)',
                'warning' => 'var(--app-warning)',
                'secondary' => 'var(--app-secondary)',
                default => 'var(--app-primary)',
            }
            : '#FFFFFF';
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function inputBorderColor(array $settings): string
    {
        return match ($settings['input_style'] ?? 'soft') {
            'filled' => 'transparent',
            'outline' => 'rgb(var(--app-primary-rgb) / 0.38)',
            default => 'rgb(var(--app-border-color-rgb) / 0.95)',
        };
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function inputSurface(bool $darkMode, array $settings): string
    {
        $style = $settings['input_style'] ?? 'soft';

        if ($darkMode) {
            return match ($style) {
                'filled' => 'linear-gradient(135deg, rgb(var(--app-surface-rgb) / 0.92) 0%, rgb(var(--app-primary-rgb) / 0.2) 100%)',
                'outline' => 'linear-gradient(135deg, rgba(10, 20, 46, 0.96) 0%, rgba(18, 35, 72, 0.92) 100%)',
                default => 'linear-gradient(135deg, rgba(10, 20, 46, 0.96) 0%, rgba(18, 35, 72, 0.92) 100%)',
            };
        }

        return match ($style) {
            'filled' => 'rgb(var(--app-primary-rgb) / 0.06)',
            'outline' => 'rgba(255, 255, 255, 0.98)',
            default => 'rgba(255, 255, 255, 0.98)',
        };
    }
}



