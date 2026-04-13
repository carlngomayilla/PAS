@props([
    'paginator',
    'label' => 'elements',
])

@php
    $range = $paginator->total() > 0
        ? $paginator->firstItem().' - '.$paginator->lastItem()
        : '0';
@endphp

<div {{ $attributes->merge(['class' => 'mt-4 rounded-2xl border border-slate-200/80 bg-white/90 p-3 dark:border-slate-800 dark:bg-slate-950/80']) }}>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">
            Lignes {{ $range }} sur {{ $paginator->total() }} {{ $label }}
        </p>
        <div class="flex flex-wrap gap-2">
            @if ($paginator->onFirstPage())
                <span class="btn btn-secondary btn-sm rounded-xl opacity-50">Premiere</span>
                <span class="btn btn-secondary btn-sm rounded-xl opacity-50">Precedent</span>
            @else
                <a class="btn btn-secondary btn-sm rounded-xl" href="{{ $paginator->url(1) }}">Premiere</a>
                <a class="btn btn-secondary btn-sm rounded-xl" href="{{ $paginator->previousPageUrl() }}">Precedent</a>
            @endif

            <span class="btn btn-primary btn-sm rounded-xl">
                Page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <a class="btn btn-secondary btn-sm rounded-xl" href="{{ $paginator->nextPageUrl() }}">Suivant</a>
                <a class="btn btn-secondary btn-sm rounded-xl" href="{{ $paginator->url($paginator->lastPage()) }}">Derniere</a>
            @else
                <span class="btn btn-secondary btn-sm rounded-xl opacity-50">Suivant</span>
                <span class="btn btn-secondary btn-sm rounded-xl opacity-50">Derniere</span>
            @endif
        </div>
    </div>
    <div class="pagination mt-3">{{ $paginator->links() }}</div>
</div>
