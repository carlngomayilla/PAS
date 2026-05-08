@props([
    'title' => null,
    'label' => null,
    'value' => null,
    'icon' => null,
    'tone' => 'blue',
    'href' => null,
    'badge' => null,
])

<x-ui.stat-card
    :title="$title"
    :label="$label"
    :value="$value"
    :icon="$icon"
    :tone="$tone"
    :href="$href"
    :badge="$badge"
    {{ $attributes }}
/>
