@props([
    'label',
    'items' => [],
])

@php
    $visibleItems = collect($items)
        ->filter(fn (array $item): bool => (bool) ($item['visible'] ?? true))
        ->values();
@endphp

@if ($visibleItems->count() > 1)
    <nav {{ $attributes->class(['module-family-tabs']) }} aria-label="{{ $label }}">
        <div class="module-family-tabs-list" role="tablist" aria-label="{{ $label }}">
            @foreach ($visibleItems as $item)
                @php($isActive = (bool) ($item['active'] ?? false))
                <a
                    href="{{ $item['href'] }}"
                    class="module-family-tab {{ $isActive ? 'is-active' : '' }}"
                    role="tab"
                    aria-selected="{{ $isActive ? 'true' : 'false' }}"
                    @if ($isActive) aria-current="page" @endif
                >
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>
    </nav>
@endif
