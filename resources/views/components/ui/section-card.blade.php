@props([
    'title' => null,
    'subtitle' => null,
    'headerClass' => '',
])

<section {{ $attributes->merge(['class' => 'ui-card w-full']) }}>
    @if ($title || $subtitle || isset($actions))
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3 {{ $headerClass }}">
            <div>
                @if ($title)
                    <h2 class="showcase-panel-title">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $subtitle }}</p>
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
