@if ($paginator->hasPages())
    <nav class="app-pagination mt-4" role="navigation" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="px-3 py-2 text-xs opacity-50" aria-disabled="true">Premier</span>
            <span class="px-3 py-2 text-xs opacity-50" aria-disabled="true">Précédent</span>
        @else
            <a class="px-3 py-2 text-xs" href="{{ $paginator->url(1) }}" rel="first">Premier</a>
            <a class="px-3 py-2 text-xs" href="{{ $paginator->previousPageUrl() }}" rel="prev">Précédent</a>
        @endif

        <span class="pagination-link-current px-3 py-2 text-xs">Page {{ $paginator->currentPage() }}</span>

        @if ($paginator->hasMorePages())
            <a class="px-3 py-2 text-xs" href="{{ $paginator->nextPageUrl() }}" rel="next">Suivant</a>
            <a class="px-3 py-2 text-xs" href="{{ $paginator->url($paginator->lastPage()) }}" rel="last">Dernier</a>
        @else
            <span class="px-3 py-2 text-xs opacity-50" aria-disabled="true">Suivant</span>
            <span class="px-3 py-2 text-xs opacity-50" aria-disabled="true">Dernier</span>
        @endif
    </nav>
@endif
