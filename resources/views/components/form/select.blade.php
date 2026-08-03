@props([
    'label' => null,
    'name',
    'hint' => null,
])

@php
    $errorBag = $errors ?? new \Illuminate\Support\ViewErrorBag;
    $hasError = $errorBag->has($name);
    $descriptionId = $hasError ? $name.'-error' : ($hint ? $name.'-hint' : null);
@endphp

<div class="app-form-group app-form-field">
    @if ($label)
        <label for="{{ $name }}" class="app-label app-form-label {{ $attributes->has('required') ? 'is-required' : '' }}">{{ $label }}</label>
    @endif
    <select
        id="{{ $name }}"
        name="{{ $name }}"
        @if ($descriptionId) aria-describedby="{{ $descriptionId }}" @endif
        aria-invalid="{{ $hasError ? 'true' : 'false' }}"
        {{ $attributes->merge(['class' => 'app-select app-form-control']) }}
    >
        {{ $slot }}
    </select>
    @if ($hint && ! $hasError)
        <p id="{{ $name }}-hint" class="field-hint app-form-hint">{{ $hint }}</p>
    @endif
    <x-form.error :name="$name" :id="$name.'-error'" />
</div>
