@props([
    'title',
    'subtitle' => null,
])

<header {{ $attributes->merge(['class' => 'app-page-header mb-6']) }}>
    <div class="min-w-0">
        <h1 class="app-title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-2 text-sm font-medium text-[var(--app-muted)]">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap gap-2">
            {{ $actions }}
        </div>
    @endisset
</header>
