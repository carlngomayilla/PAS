@props([
    'label',
    'value' => 0,
    'target' => 100,
    'caption' => null,
])

@php
    $percent = max(0, min(100, (float) $value));
    $targetPercent = max(0, min(100, (float) $target));
@endphp

<div {{ $attributes->merge(['class' => 'space-y-2']) }}>
    <div class="flex items-center justify-between gap-3 text-sm">
        <span class="font-medium text-[var(--dashboard-text)]">{{ $label }}</span>
        <span class="text-[var(--dashboard-muted)]">{{ number_format($percent, 0) }}%</span>
    </div>
    <div class="relative h-2 rounded-full bg-[var(--dashboard-surface-muted)]">
        <div class="absolute inset-y-0 left-0 rounded-full bg-[var(--dashboard-accent)]" style="width: {{ $percent }}%"></div>
        <div class="absolute inset-y-[-3px] w-px bg-[var(--dashboard-warning)]" style="left: {{ $targetPercent }}%"></div>
    </div>
    @if ($caption)
        <p class="text-xs text-[var(--dashboard-muted)]">{{ $caption }}</p>
    @endif
</div>
