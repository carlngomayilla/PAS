@props([
    'title' => null,
    'subtitle' => null,
    'hostId' => null,
    'height' => '320px',
    'empty' => 'Aucune donnée disponible pour votre périmètre.',
])

<x-ui.section-card :title="$title" :subtitle="$subtitle" {{ $attributes->merge(['class' => 'mb-4']) }}>
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset

    <div class="dashboard-canvas" style="min-height: {{ $height }}">
        @if ($hostId)
            <div id="{{ $hostId }}" class="dashboard-chart-host" data-empty-message="{{ $empty }}"></div>
        @else
            {{ $slot }}
        @endif
    </div>
</x-ui.section-card>
