@props([
    'items' => [],
    'current' => null,
])

@if (!empty($items) || $current)
<nav class="app-breadcrumb" aria-label="Fil d'Ariane">
    @foreach ($items as $item)
        <a href="{{ $item['href'] }}">{{ $item['label'] }}</a>
        <span class="app-breadcrumb-sep" aria-hidden="true">›</span>
    @endforeach
    @if ($current)
        <span class="app-breadcrumb-current" aria-current="page">{{ $current }}</span>
    @endif
</nav>
@endif
