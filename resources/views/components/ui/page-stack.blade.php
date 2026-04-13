@props(['gap' => 'space-y-6'])

<div {{ $attributes->merge(['class' => $gap]) }}>
    {{ $slot }}
</div>
