{{-- The conversations table: search, page size, sortable columns, pages and
     a transcript per row. Drawn by the Conversations page and at the foot of
     the Analytics page, from App\Support\ConversationList.

     $listAction      where the search form goes
     $listCarry       hidden fields the form keeps (name => value or list)
     $listShowPicker  whether the bot picker sits in the toolbar
     $listFiltered    whether a filter is on, for the count and Clear filters
     $listClearUrl    where Clear filters goes
     $listAnchor      an id for the card, kept on every link back to it
     $listTitle       the card's heading --}}
@php
    // A header link sorts by its column; pressing the column already sorted
    // turns it round. Filters and page size ride along, the page does not.
    $anchor = !empty($listAnchor) ? '#' . $listAnchor : '';
    $sortUrl = function (string $column) use ($sort, $dir, $anchor) {
        $next = $sort === $column
            ? ($dir === 'asc' ? 'desc' : 'asc')
            : \App\Support\ConversationList::SORTS[$column];

        return request()->fullUrlWithQuery(['sort' => $column, 'dir' => $next, 'page' => null]) . $anchor;
    };
    $sortIcon = fn (string $column) => $sort !== $column
        ? 'bi-chevron-expand'
        : ($dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down');
    $ariaSort = fn (string $column) => $sort !== $column ? 'none' : ($dir === 'asc' ? 'ascending' : 'descending');

    $columns = [
        'bot' => ['label' => 'Bot profile', 'style' => 'min-width: 190px;'],
        'opening' => ['label' => 'Opening message', 'style' => 'min-width: 220px;'],
        // As narrow as its heading: a count of three digits fits under it.
        'turns' => ['label' => 'Turns', 'style' => 'width: 1%;'],
        'tokens' => ['label' => 'Tokens', 'style' => 'width: 110px;'],
        'origin' => ['label' => 'Origin', 'style' => 'width: 225px;'],
        'started' => ['label' => 'Started', 'style' => 'width: 120px;'],
    ];
@endphp

<div class="card" @if(!empty($listAnchor)) id="{{ $listAnchor }}" @endif>
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <span>{{ $listTitle ?? 'Recorded sessions' }}</span>
        <span class="chip figure-mono">{{ $conversations->total() }} {{ $listFiltered ? 'found' : 'total' }}</span>
    </div>

    {{-- One GET form, so every filter lands in the address bar and a
         filtered, sorted view can be bookmarked or shared. --}}
    <form method="GET" action="{{ $listAction }}{{ $anchor }}" class="list-toolbar" role="search">
        {{-- What the page around the table was showing, kept when searching it. --}}
        @foreach($listCarry ?? [] as $name => $value)
            @foreach((array) $value as $item)
                <input type="hidden" name="{{ is_array($value) ? $name . '[]' : $name }}" value="{{ $item }}">
            @endforeach
        @endforeach
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">

        <div class="list-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label for="conv_search" class="visually-hidden">Search conversations</label>
            <input type="search" name="q" id="conv_search" class="form-control" value="{{ $search }}"
                   placeholder="Search messages, origin, session or bot" autocomplete="off">
        </div>

        @if(!empty($listShowPicker))
            @include('partials._bot-picker')
        @endif

        <label for="per_page" class="visually-hidden">Rows per page</label>
        <select name="per_page" id="per_page" class="form-select list-select list-select-narrow" onchange="this.form.submit()">
            @foreach(\App\Support\ConversationList::PER_PAGE as $size)
                <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }} per page</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-outline-secondary">Search</button>
        @if($listFiltered)
            <a href="{{ $listClearUrl }}{{ $anchor }}" class="btn btn-link list-reset">Clear filters</a>
        @endif
    </form>

    @if($conversations->isEmpty())
        <div class="empty">
            @if($listFiltered)
                <i class="bi bi-search"></i>
                <h6>No sessions match</h6>
                <p>Nothing recorded here fits those filters. Try fewer words, or another bot profile.</p>
            @else
                <i class="bi bi-chat-left-text"></i>
                <h6>No sessions recorded</h6>
                <p>Once a visitor opens a widget on a host site, the session and every message land here.</p>
            @endif
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0 sortable-table">
                <thead>
                    <tr>
                        {{-- The number is the row's place in this view, so it
                             never sorts: it always counts down the page. --}}
                        <th class="row-number" scope="col">#</th>
                        @foreach($columns as $key => $column)
                            <th style="{{ $column['style'] }}" scope="col" aria-sort="{{ $ariaSort($key) }}">
                                <a href="{{ $sortUrl($key) }}" class="sort-link {{ $sort === $key ? 'is-active' : '' }}">
                                    {{ $column['label'] }} <i class="bi {{ $sortIcon($key) }}" aria-hidden="true"></i>
                                </a>
                            </th>
                        @endforeach
                        <th class="text-end" style="width: 120px;" scope="col">Transcript</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($conversations as $conv)
                        <tr>
                            <td class="row-number">{{ $conversations->firstItem() + $loop->index }}</td>
                            <td>
                                <div class="fw-semibold">
                                    {{ $conv->bot_name ?? 'Deleted profile' }}
                                    @if($conv->bot?->is_platform)
                                        <span class="badge bot-picker-builtin ms-1">Built in</span>
                                    @endif
                                    @if($conv->bot?->trashed())
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">Deleted</span>
                                    @endif
                                </div>
                                @if($multiWorkspace && $conv->bot?->system)
                                    <div class="conv-workspace">{{ $conv->bot->system->name }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="opening-clamp {{ $conv->opening_message === null ? 'text-muted' : '' }}"
                                      title="{{ \Illuminate\Support\Str::limit($conv->opening_message, 500) }}">
                                    {{ $conv->opening_message ?? 'No messages' }}
                                </span>
                            </td>
                            <td><span class="figure-mono">{{ $conv->messages_count }}</span></td>
                            <td>
                                @if($conv->tokens_total)
                                    {{-- Total, then in and out, one to a line. In and out
                                         only exist on answers saved since they were kept. --}}
                                    <div class="conv-tokens figure-mono cell-nowrap" title="Total, prompt tokens in, completion tokens out">
                                        <div>{{ number_format($conv->tokens_total) }}</div>
                                        <div class="conv-tokens-part"><span>in</span> {{ $conv->tokens_in === null ? '—' : number_format($conv->tokens_in) }}</div>
                                        <div class="conv-tokens-part"><span>out</span> {{ $conv->tokens_out === null ? '—' : number_format($conv->tokens_out) }}</div>
                                    </div>
                                @else
                                    <span class="figure-mono text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="figure-mono text-muted origin-clamp" title="{{ $conv->origin ?: 'preview sandbox' }}">
                                    {{ $conv->origin ?: 'preview sandbox' }}
                                </span>
                            </td>
                            <td class="text-muted figure-mono cell-nowrap" style="font-size: 0.75rem;" title="{{ $conv->created_at->format('M j, Y H:i') }}">
                                {{ $conv->created_at->format('M j, H:i') }}
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        onclick="viewTranscript('{{ $conv->id }}')">
                                    <i class="bi bi-chat-text"></i> Open
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="p-3" style="border-top: 1px solid var(--border);">
            @if($conversations->hasPages())
                {{ $conversations->links() }}
            @else
                <div class="pager-summary">
                    Showing <span class="figure-mono">{{ $conversations->firstItem() }}–{{ $conversations->lastItem() }}</span>
                    of <span class="figure-mono">{{ $conversations->total() }}</span>
                </div>
            @endif
        </div>
    @endif
</div>

@push('scripts')
<style>
    .conv-tokens { line-height: 1.35; }
    .conv-tokens-part { font-size: 0.6875rem; color: var(--text-muted); }
    .conv-tokens-part span { display: inline-block; min-width: 1.75rem; color: var(--text-faint); }
</style>
@endpush
