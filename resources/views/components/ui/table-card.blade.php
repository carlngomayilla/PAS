@props([
    'title' => null,
    'subtitle' => null,
])

<x-ui.section-card :title="$title" :subtitle="$subtitle" {{ $attributes->merge(['class' => 'mb-4']) }}>
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset

    <div class="app-table-wrapper">
        {{ $slot }}
    </div>
</x-ui.section-card>
