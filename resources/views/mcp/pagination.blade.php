@if($paginator->hasPages())
<nav class="form-actions" aria-label="Record pages">
    @if($paginator->onFirstPage())<span class="muted">Previous</span>@else<a class="btn btn-secondary btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>@endif
    <span>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }} · {{ $paginator->total() }} records</span>
    @if($paginator->hasMorePages())<a class="btn btn-secondary btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>@else<span class="muted">Next</span>@endif
</nav>
@endif
