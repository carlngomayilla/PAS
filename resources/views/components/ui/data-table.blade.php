@props([
    'title' => null,
    'description' => null,
    'actions' => null,
    'enhanced' => false,
    'mobileCards' => false,
    'pageSize' => 25,
    'name' => null,
    'labelSingular' => 'ligne',
    'labelPlural' => 'lignes',
])

<section {{ $attributes->merge(['class' => 'reco-table-shell app-card data-table-shell']) }}>
    @if ($title || $description || ($slotActions ?? $actions))
        <div class="app-card-header data-table-header">
            @if ($title || $description)
                <div class="min-w-0">
                    @if ($title)<h3 class="data-table-title">{{ $title }}</h3>@endif
                    @if ($description)
                        <p class="data-table-subtitle mt-1 text-sm text-[var(--app-muted)]">{{ $description }}</p>
                    @endif
                </div>
            @endif
            @if ($slotActions ?? $actions)
                <div class="data-table-actions flex flex-wrap items-center gap-2">
                    {{ $slotActions ?? $actions }}
                </div>
            @endif
        </div>
    @endif

    @isset($filters)
        <div class="border-b border-[var(--app-border)] p-3" data-table-filter-region>
            {{ $filters }}
        </div>
    @endisset

    <div class="app-table-wrapper overflow-x-auto border-0 shadow-none">
        <table
            class="app-table data-table min-w-full w-full text-sm"
            @if ($enhanced) data-table-enhanced data-table-page-size="{{ (int) $pageSize }}" @endif
            @if ($mobileCards) data-mobile-cards @endif
            @if ($name) data-table-name="{{ $name }}" @endif
            data-table-label-singular="{{ $labelSingular }}"
            data-table-label-plural="{{ $labelPlural }}"
        >
            {{ $table ?? $slot }}
        </table>
    </div>
</section>
