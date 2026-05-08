@props([
    'for' => null,
])

<label @if($for) for="{{ $for }}" @endif {{ $attributes->merge(['class' => 'app-label']) }}>
    {{ $slot }}
</label>
