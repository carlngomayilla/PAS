@props([
    'variant' => 'full',
    'class' => '',
])

@php
    $logoPath = match($variant) {
        'mark' => '/images/logo-mark.png',
        'wordmark' => '/images/logo-wordmark.png',
        default => '/images/logo-full.png',
    };
@endphp

<img 
    src="{{ $logoPath }}" 
    alt="ANBG - Agence Nationale des Bourses du Gabon"
    {{ $attributes->merge(['class' => $class]) }}
    style="object-fit: contain;"
/>
