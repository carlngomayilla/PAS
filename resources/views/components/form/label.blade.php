@props([
    'for' => null,
])

<label @if($for) for="{{ $for }}" @endif {{ $attributes->merge(['class' => 'app-label app-form-label']) }}>
    {{ $slot }}
</label>
