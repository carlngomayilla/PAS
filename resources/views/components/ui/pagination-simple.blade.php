@props([
    'paginator',
    'label' => 'elements',
])

@php
    $range = $paginator->total() > 0
        ? $paginator->firstItem().' - '.$paginator->lastItem()
        : '0';
@endphp

<div {{ $attributes->merge(['class' => 'app-pagination mt-5']) }}>
    <span class="mr-auto text-xs font-semibold text-[var(--app-muted)]">Lignes {{ $range }} sur {{ $paginator->total() }} {{ $label }}</span>

    @if ($paginator->onFirstPage())
        <span class="px-3 py-2 text-xs opacity-50">Premier</span>
        <span class="px-3 py-2 text-xs opacity-50">Précédent</span>
    @else
        <a class="px-3 py-2 text-xs" href="{{ $paginator->url(1) }}">Premier</a>
        <a class="px-3 py-2 text-xs" href="{{ $paginator->previousPageUrl() }}">Précédent</a>
    @endif

    <span class="pagination-link-current px-3 py-2 text-xs">Page {{ $paginator->currentPage() }}</span>

    @if ($paginator->hasMorePages())
        <a class="px-3 py-2 text-xs" href="{{ $paginator->nextPageUrl() }}">Suivant</a>
        <a class="px-3 py-2 text-xs" href="{{ $paginator->url($paginator->lastPage()) }}">Dernier</a>
    @else
        <span class="px-3 py-2 text-xs opacity-50">Suivant</span>
        <span class="px-3 py-2 text-xs opacity-50">Dernier</span>
    @endif
</div>
