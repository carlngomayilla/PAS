@props([
    'title' => null,
    'subtitle' => null,
    'hostId' => null,
    'height' => '280px',
    'empty' => 'Aucune donnée disponible pour votre périmètre.',
])

<x-ui.chart-card
    :title="$title"
    :subtitle="$subtitle"
    :host-id="$hostId"
    :height="$height"
    :empty="$empty"
    {{ $attributes }}
>
    {{ $slot }}
</x-ui.chart-card>
