@props([
    'variant' => 'full',
    'class' => 'h-16 w-auto object-contain',
])

<x-brand.logo :variant="$variant" {{ $attributes->merge(['class' => $class]) }} />
