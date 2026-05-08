@props([
    'name' => null,
])

@if ($name && $errors->has($name))
    <p {{ $attributes->merge(['class' => 'field-error']) }}>{{ $errors->first($name) }}</p>
@elseif ($slot->isNotEmpty())
    <p {{ $attributes->merge(['class' => 'field-error']) }}>{{ $slot }}</p>
@endif
