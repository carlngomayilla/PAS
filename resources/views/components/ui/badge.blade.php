@props([
    'tone' => 'neutral',
])

<span {{ $attributes->merge(['class' => 'anbg-badge anbg-badge-'.$tone]) }}>
    {{ $slot }}
</span>
