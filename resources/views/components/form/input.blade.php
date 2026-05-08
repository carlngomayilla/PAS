@props([
    'label' => null,
    'name',
    'type' => 'text',
    'value' => null,
])

<div class="app-form-group">
    @if ($label)
        <label for="{{ $name }}" class="app-label">{{ $label }}</label>
    @endif
    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        {{ $attributes->merge(['class' => 'app-input']) }}
    >
    <x-form.error :name="$name" />
</div>
