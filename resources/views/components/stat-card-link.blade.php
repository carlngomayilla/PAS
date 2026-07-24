@props([
    'href',
    'label',
    'value',
    'meta' => null,
    'badge' => null,
    'badgeTone' => 'neutral',
    'cardClass' => 'app-card eas-stat-card',
    'labelClass' => 'eas-stat-card-label',
    'valueClass' => 'eas-stat-card-value',
    'metaClass' => 'eas-stat-card-meta',
    'valueStyle' => null,
    'hint' => null,
    'tone' => null,
    'show' => true,
    'hideWhenEmpty' => false,
])

@php
    $resolvedTone = $tone ?? ($badge ? $badgeTone : null);
    $toneColors = [
        'success' => ['accent' => '#16a34a', 'background' => '#f0fdf4'],
        'warning' => ['accent' => '#d97706', 'background' => '#fffbeb'],
        'danger' => ['accent' => '#dc2626', 'background' => '#fef2f2'],
        'info' => ['accent' => '#2563eb', 'background' => '#eff6ff'],
        'neutral' => ['accent' => '#3996d3', 'background' => '#f7fbfd'],
    ];
    $resolvedColors = $toneColors[$resolvedTone] ?? $toneColors['neutral'];
    $normalizedValue = is_string($value)
        ? str_replace(['%', ' ', "\u{00A0}"], '', $value)
        : $value;
    $isEmptyValue = $value === null
        || trim((string) $value) === ''
        || (is_numeric($normalizedValue) && (float) $normalizedValue === 0.0);
    $shouldRender = (bool) $show && (! $hideWhenEmpty || ! $isEmptyValue);
@endphp

@if ($shouldRender)
<a
    href="{{ $href }}"
    style="--dashboard-card-accent: {{ $resolvedColors['accent'] }}; --dashboard-card-bg: {{ $resolvedColors['background'] }};"
    {{ $attributes->class([$cardClass, 'dashboard-primary-kpi-card group relative no-kpi-band stat-card-link stat-card flex min-h-32 min-w-[150px] flex-1 flex-col justify-between overflow-hidden p-4', $resolvedTone ? 'showcase-tone-card showcase-tone-card-'.$resolvedTone : null]) }}
>
    <span class="dashboard-primary-kpi-glow" aria-hidden="true"></span>
    <div class="relative z-10 flex items-start justify-between gap-3">
        <p class="{{ $labelClass }} max-w-[12rem] leading-5">{{ $label }}</p>
        <span class="dashboard-primary-kpi-icon flex h-10 w-10 shrink-0 items-center justify-center rounded-xl">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M4 19V9m6 10V5m6 14v-7m4 7H2"/>
            </svg>
        </span>
    </div>
    <div class="relative z-10 mt-4 flex items-end justify-between gap-3">
        <div class="min-w-0">
            <p class="{{ $valueClass }} text-3xl font-black tracking-tight" @if($valueStyle) style="{{ $valueStyle }}" @endif>{{ $value }}</p>
            @if ($meta)
                <p class="{{ $metaClass }} mt-1 line-clamp-2">{{ $meta }}</p>
            @endif
            @if ($badge)
                <span class="app-badge app-badge-{{ $badgeTone }} mt-1">{{ $badge }}</span>
            @endif
            {{ $slot }}
        </div>
        <svg class="mb-1 h-4 w-4 shrink-0 text-[#9aa8b6] transition group-hover:translate-x-1 group-hover:text-[var(--dashboard-card-accent)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6"/>
        </svg>
    </div>
</a>
@endif
