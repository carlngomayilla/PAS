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
        'navy' => 'border-[#1c203d]/30',
        'blue' => 'border-[#3996d3]/30',
        'green' => 'border-[#8fc043]/40',
        'yellow' => 'border-[#f8e932]/60',
        'gold' => 'border-[#f0e509]/60',
        'orange' => 'border-[#f9b13c]/50',
        'danger' => 'border-red-200',
    ];
    $classes = 'no-kpi-band stat-card glass-kpi app-card min-w-[150px] max-w-[220px] flex-1 rounded-xl border '.($tones[$tone] ?? $tones['blue']).' bg-white px-4 py-3 text-center shadow-sm transition hover:shadow-md';
@endphp

@if ($shouldRender)
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
@else
    <div {{ $attributes->merge(['class' => $classes]) }}>
@endif
    <div class="flex min-h-[4.5rem] flex-col items-center justify-center gap-2">
        @if ($icon)
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#eef6fc] text-[#1c203d]">
                {{ $icon }}
            </div>
        @endif

        <div class="min-w-0">
            <p class="truncate text-center text-[11px] font-bold uppercase tracking-wide text-[#667085]">{{ $titleText }}</p>
            <p class="mt-1 text-center text-xl font-extrabold text-[#1c203d]">{{ $value }}</p>
        </div>
    </div>

    @if ($badge)
        <div class="mt-2 flex justify-center">
            <span class="app-badge">{{ $badge }}</span>
        </div>
    @endif
@if ($href)
    </a>
@else
    </div>
@endif
@endif
