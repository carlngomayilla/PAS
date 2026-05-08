@props([
    'paginator',
    'label' => 'éléments',
])

<x-ui.pagination-simple :paginator="$paginator" :label="$label" {{ $attributes }} />
