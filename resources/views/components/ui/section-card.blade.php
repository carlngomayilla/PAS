@props([
    'title' => null,
    'subtitle' => null,
    'headerClass' => '',
])

<section {{ $attributes->merge(['class' => 'app-card eas-section-card w-full']) }}>
    @if ($title || $subtitle || isset($actions))
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3 {{ $headerClass }}">
            <div class="min-w-0 flex-1">
                @if ($title)
                    <h2 class="showcase-panel-title">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="mt-1 text-sm text-[var(--app-muted)]">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    {{ $slot }}
</section>
