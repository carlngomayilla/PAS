@props([
    'label' => null,
    'name',
])

<div class="app-form-group">
    @if ($label)
        <label for="{{ $name }}" class="app-label">{{ $label }}</label>
    @endif
    <select
        id="{{ $name }}"
        name="{{ $name }}"
        {{ $attributes->merge(['class' => 'app-select']) }}
    >
        {{ $slot }}
    </select>
    <x-form.error :name="$name" />
</div>
