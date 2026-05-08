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

    <div class="app-table-wrapper border-0 shadow-none">
        <table class="app-table data-table">
            {{ $table ?? $slot }}
        </table>
    </div>
</section>
