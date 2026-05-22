@props([
    'title' => null,
    'actions' => null,
])

<section {{ $attributes->merge(['class' => 'app-card data-table-shell']) }}>
    @if ($title || ($slotActions ?? $actions))
        <div class="app-card-header data-table-header">
            @if ($title)
                <h3 class="data-table-title">{{ $title }}</h3>
            @endif
            @if ($slotActions ?? $actions)
                <div class="data-table-actions flex flex-wrap items-center gap-2">
                    {{ $slotActions ?? $actions }}
                </div>
            @endif
        </div>
    @endif

    <div class="app-table-wrapper overflow-x-auto border-0 shadow-none">
        <table class="app-table data-table min-w-[1200px] w-full text-sm">
            {{ $table ?? $slot }}
        </table>
    </div>
</section>
