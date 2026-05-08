@props([
    'label' => null,
    'name',
    'value' => null,
])

<x-form.input :label="$label" :name="$name" type="date" :value="$value" {{ $attributes }} />
