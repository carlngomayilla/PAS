@props([
    'href',
    'label',
    'value',
    'meta' => null,
    'badge' => null,
    'badgeTone' => 'neutral',
    'cardClass' => 'showcase-kpi-card',
    'labelClass' => 'showcase-kpi-label',
    'valueClass' => 'showcase-kpi-number',
    'metaClass' => 'showcase-kpi-meta',
    'valueStyle' => null,
    'hint' => null,
    'tone' => null,
])

@php
    $resolvedTone = $tone ?? ($badge ? $badgeTone : null);
@endphp

<a href="{{ $href }}" {{ $attributes->class([$cardClass, 'stat-card-link', $resolvedTone ? 'showcase-tone-card showcase-tone-card-'.$resolvedTone : null]) }}>
    <div class="flex items-start justify-between gap-2">
        <p class="{{ $labelClass }}">{{ $label }}</p>
        @if ($badge)
            <span class="anbg-badge anbg-badge-{{ $badgeTone }} px-2 py-0.5 text-[10px] font-semibold leading-none">{{ $badge }}</span>
        @endif
    </div>
    <p class="{{ $valueClass }}" @if($valueStyle) style="{{ $valueStyle }}" @endif>{{ $value }}</p>
    @if ($meta)
        <p class="{{ $metaClass }}">{{ $meta }}</p>
    @endif
    @if ($hint)
        <p class="stat-card-hint">{{ $hint }}</p>
    @endif
    {{ $slot }}
</a>
