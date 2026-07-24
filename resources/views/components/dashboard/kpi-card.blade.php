@props([
    'label',
    'value',
    'caption' => null,
    'tone' => 'info',
    'href' => null,
])

@php
    $toneColor = match ($tone) {
        'success' => 'var(--dashboard-success)',
        'warning' => 'var(--dashboard-warning)',
        'danger' => 'var(--dashboard-danger)',
        'accent' => 'var(--dashboard-accent)',
        default => 'var(--dashboard-info)',
    };
    $classes = 'block rounded-[var(--dashboard-card-radius)] border border-[var(--dashboard-border)] bg-[var(--dashboard-surface)] p-4 shadow-sm transition hover:border-[var(--dashboard-accent)]';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm font-medium text-[var(--dashboard-muted)]">{{ $label }}</p>
            <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $toneColor }}"></span>
        </div>
        <p class="mt-3 text-2xl font-semibold text-[var(--dashboard-text)]">{{ $value }}</p>
        @if ($caption)
            <p class="mt-1 text-xs text-[var(--dashboard-muted)]">{{ $caption }}</p>
        @endif
    </a>
@else
    <div {{ $attributes->merge(['class' => $classes]) }}>
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm font-medium text-[var(--dashboard-muted)]">{{ $label }}</p>
            <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $toneColor }}"></span>
        </div>
        <p class="mt-3 text-2xl font-semibold text-[var(--dashboard-text)]">{{ $value }}</p>
        @if ($caption)
            <p class="mt-1 text-xs text-[var(--dashboard-muted)]">{{ $caption }}</p>
        @endif
    </div>
@endif
