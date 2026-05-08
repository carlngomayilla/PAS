@props([
    'type' => 'button',
    'variant' => 'primary',
    'size' => 'sm',
    'href' => null,
])

@php
    $sizes = [
        'xs' => 'px-3 py-2 text-xs',
        'sm' => 'px-4 py-2.5 text-sm',
        'md' => 'px-5 py-3 text-sm',
    ];
    $variants = [
        'primary' => 'app-btn app-btn-primary',
        'secondary' => 'app-btn app-btn-secondary',
        'outline' => 'app-btn app-btn-outline',
        'danger' => 'app-btn app-btn-danger',
    ];
    $classes = trim(($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['sm']));
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @isset($icon)
            <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center">{{ $icon }}</span>
        @endisset
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @isset($icon)
            <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center">{{ $icon }}</span>
        @endisset
        {{ $slot }}
    </button>
@endif
