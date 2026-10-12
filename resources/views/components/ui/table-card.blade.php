@props([
    'title' => null,
    'subtitle' => null,
])

<x-ui.section-card :title="$title" :subtitle="$subtitle" {{ $attributes->merge(['class' => 'reco-table-shell mb-4']) }}>
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset

    <div class="app-table-wrapper overflow-x-auto">
        {{ $slot }}
    </div>
</x-ui.section-card>
