@props([
    'label' => null,
    'name',
    'value' => null,
])

<div class="app-form-group">
    @if ($label)
        <label for="{{ $name }}" class="app-label">{{ $label }}</label>
    @endif
    <textarea
        id="{{ $name }}"
        name="{{ $name }}"
        {{ $attributes->merge(['class' => 'app-textarea']) }}
    >{{ old($name, $value) }}</textarea>
    <x-form.error :name="$name" />
</div>
