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
])

@php
    $resolvedTone = $tone ?? ($badge ? $badgeTone : null);
@endphp

<a href="{{ $href }}" {{ $attributes->class([$cardClass, 'stat-card-link stat-card flex min-w-[150px] max-w-[260px] flex-col items-center justify-center p-3 text-center', $resolvedTone ? 'showcase-tone-card showcase-tone-card-'.$resolvedTone : null]) }}>
    <div class="flex w-full flex-col items-center justify-center gap-2">
        <p class="{{ $labelClass }} max-w-full text-center leading-snug">{{ $label }}</p>
        @if ($badge)
            <span class="app-badge app-badge-{{ $badgeTone }}">{{ $badge }}</span>
        @endif
    </div>
    <p class="{{ $valueClass }} mt-1 text-center" @if($valueStyle) style="{{ $valueStyle }}" @endif>{{ $value }}</p>
    {{ $slot }}
</a>
