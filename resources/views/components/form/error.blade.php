@props([
    'name' => null,
])

@php($errorBag = $errors ?? new \Illuminate\Support\ViewErrorBag)

@if ($name && $errorBag->has($name))
    <p {{ $attributes->merge(['class' => 'field-error app-form-error', 'role' => 'alert']) }}>{{ $errorBag->first($name) }}</p>
@elseif ($slot->isNotEmpty())
    <p {{ $attributes->merge(['class' => 'field-error app-form-error', 'role' => 'alert']) }}>{{ $slot }}</p>
@endif
