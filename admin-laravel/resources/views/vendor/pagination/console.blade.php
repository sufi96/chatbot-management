{{-- The console's own pager. Laravel's default is written for Tailwind, which
     this console does not load, so its arrows rendered at full size. --}}
@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pages">
        <div class="pager-summary">
            Showing <span class="figure-mono">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
            of <span class="figure-mono">{{ $paginator->total() }}</span>
        </div>

        <ul class="pager-list">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="pager-btn is-disabled" aria-disabled="true" aria-label="Previous page">
                        <i class="bi bi-chevron-left"></i><span class="pager-word">Previous</span>
                    </span>
                @else
                    <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page">
                        <i class="bi bi-chevron-left"></i><span class="pager-word">Previous</span>
                    </a>
                @endif
            </li>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="pager-pages"><span class="pager-gap">…</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li class="pager-pages">
                            @if ($page == $paginator->currentPage())
                                <span class="pager-btn is-current" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="pager-btn" href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li class="pager-compact" aria-hidden="true">
                <span class="pager-gap figure-mono">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            </li>

            <li>
                @if ($paginator->hasMorePages())
                    <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page">
                        <span class="pager-word">Next</span><i class="bi bi-chevron-right"></i>
                    </a>
                @else
                    <span class="pager-btn is-disabled" aria-disabled="true" aria-label="Next page">
                        <span class="pager-word">Next</span><i class="bi bi-chevron-right"></i>
                    </span>
                @endif
            </li>
        </ul>
    </nav>
@endif
