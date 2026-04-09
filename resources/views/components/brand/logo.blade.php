@props([
    'variant' => 'full',
    'class' => '',
])

@php
    $logoPath = $platformSettings->brandAssetUrl($variant);
@endphp

<img 
    src="{{ $logoPath }}" 
    alt="{{ $platformSettings->get('institution_label', 'ANBG - Agence Nationale des Bourses du Gabon') }}"
    {{ $attributes->merge(['class' => trim('block '.$class)]) }}
    style="object-fit: contain;"
/>
