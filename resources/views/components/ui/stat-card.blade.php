@props([
    'title' => null,
    'label' => null,
    'value' => null,
    'icon' => null,
    'tone' => 'blue',
    'href' => null,
    'badge' => null,
    'show' => true,
    'hideWhenEmpty' => false,
])

@php
    $titleText = $title ?? $label;
    $normalizedValue = is_string($value)
        ? str_replace(['%', ' ', "\u{00A0}"], '', $value)
        : $value;
    $isEmptyValue = $value === null
        || trim((string) $value) === ''
        || (is_numeric($normalizedValue) && (float) $normalizedValue === 0.0);
    $shouldRender = (bool) $show && (! $hideWhenEmpty || ! $isEmptyValue);
    $tones = [
        'navy' => ['accent' => '#1c203d', 'background' => '#e8f3fb'],
        'blue' => ['accent' => '#3996d3', 'background' => '#eff8fd'],
        'green' => ['accent' => '#16a34a', 'background' => '#f0fdf4'],
        'yellow' => ['accent' => '#ca8a04', 'background' => '#fefce8'],
        'gold' => ['accent' => '#d97706', 'background' => '#fffbeb'],
        'orange' => ['accent' => '#ea580c', 'background' => '#fff7ed'],
        'danger' => ['accent' => '#dc2626', 'background' => '#fef2f2'],
    ];
    $resolvedTone = $tones[$tone] ?? $tones['blue'];
    $classes = 'dashboard-primary-kpi-card no-kpi-band stat-card glass-kpi app-card group relative min-h-32 min-w-[150px] flex-1 overflow-hidden px-4 py-4 transition';
    $style = '--dashboard-card-accent: '.$resolvedTone['accent'].'; --dashboard-card-bg: '.$resolvedTone['background'].';';
@endphp

@if ($shouldRender)
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes, 'style' => $style]) }}>
@else
    <div {{ $attributes->merge(['class' => $classes, 'style' => $style]) }}>
@endif
    <span class="dashboard-primary-kpi-glow" aria-hidden="true"></span>
    <div class="relative z-10 flex h-full flex-col justify-between gap-4">
        <div class="flex items-start justify-between gap-3">
            <p class="max-w-[12rem] text-xs font-bold leading-5 text-[#526174]">{{ $titleText }}</p>
            <div class="dashboard-primary-kpi-icon flex h-10 w-10 shrink-0 items-center justify-center rounded-xl">
                @if ($icon)
                {{ $icon }}
                @else
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M4 19V9m6 10V5m6 14v-7m4 7H2"/>
                    </svg>
                @endif
            </div>
        </div>
        <div class="flex items-end justify-between gap-3">
            <div class="min-w-0">
                <p class="text-3xl font-black tracking-tight text-[#17324a]">{{ $value }}</p>
                @if ($badge)
                    <span class="app-badge mt-1">{{ $badge }}</span>
                @endif
            </div>
            @if ($href)
                <svg class="mb-1 h-4 w-4 shrink-0 text-[#9aa8b6] transition group-hover:translate-x-1 group-hover:text-[var(--dashboard-card-accent)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6"/>
                </svg>
            @endif
        </div>
    </div>
@if ($href)
    </a>
@else
    </div>
@endif
@endif
