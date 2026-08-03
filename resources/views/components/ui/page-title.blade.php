@props([
    'title',
    'subtitle' => null,
    'eyebrow' => null,
])

<header {{ $attributes->merge(['class' => 'app-page-header mb-6']) }}>
    <div class="min-w-0">
        @isset($breadcrumbs)
            <nav class="mb-2 text-xs font-semibold text-[var(--app-muted)]" aria-label="Fil d’Ariane">
                {{ $breadcrumbs }}
            </nav>
        @endisset
        @if ($eyebrow)
            <span class="app-eyebrow">{{ $eyebrow }}</span>
        @endif
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
