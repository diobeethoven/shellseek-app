@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pagination">
        <span>Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
        <span class="pages">
            @if ($paginator->onFirstPage())
                <span class="chip faint">← Previous</span>
            @else
                <a class="chip" href="{{ $paginator->previousPageUrl() }}" rel="prev">← Previous</a>
            @endif
            <span class="chip">Page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            @if ($paginator->hasMorePages())
                <a class="chip" href="{{ $paginator->nextPageUrl() }}" rel="next">Next →</a>
            @else
                <span class="chip faint">Next →</span>
            @endif
        </span>
    </nav>
@endif
