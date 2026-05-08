@if ($paginator->hasPages())
    <nav class="flex flex-wrap items-center gap-2" role="navigation" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="btn btn-secondary btn-sm rounded-xl opacity-50" aria-disabled="true">Premier</span>
            <span class="btn btn-secondary btn-sm rounded-xl opacity-50" aria-disabled="true">Precedent</span>
        @else
            <a class="btn btn-secondary btn-sm rounded-xl" href="{{ $paginator->url(1) }}" rel="first">Premier</a>
            <a class="btn btn-secondary btn-sm rounded-xl" href="{{ $paginator->previousPageUrl() }}" rel="prev">Precedent</a>
        @endif

        <span class="btn btn-primary btn-sm rounded-xl">Page {{ $paginator->currentPage() }}</span>

        @if ($paginator->hasMorePages())
            <a class="btn btn-secondary btn-sm rounded-xl" href="{{ $paginator->nextPageUrl() }}" rel="next">Suivant</a>
            <a class="btn btn-secondary btn-sm rounded-xl" href="{{ $paginator->url($paginator->lastPage()) }}" rel="last">Dernier</a>
        @else
            <span class="btn btn-secondary btn-sm rounded-xl opacity-50" aria-disabled="true">Suivant</span>
            <span class="btn btn-secondary btn-sm rounded-xl opacity-50" aria-disabled="true">Dernier</span>
        @endif
    </nav>
@endif
