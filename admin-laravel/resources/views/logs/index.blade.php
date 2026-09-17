@extends('layouts.app')

@section('page-title', 'Conversations')

@section('content')

@php
    $filtered = $search !== '' || !empty($selectedBots);

    // What the bot picker's button says: all, the one bot, or how many.
    $allBots = $botGroups->flatMap(fn ($group) => $group['bots']);
    $pickerLabel = match (true) {
        empty($selectedBots) => 'All bot profiles',
        count($selectedBots) === 1 => $allBots->firstWhere('id', $selectedBots[0])?->name ?? '1 bot profile',
        default => count($selectedBots) . ' bot profiles',
    };

    // A header link sorts by its column; pressing the column already sorted
    // turns it round. Filters and page size ride along, the page does not.
    $sortUrl = function (string $column) use ($sort, $dir) {
        $next = $sort === $column
            ? ($dir === 'asc' ? 'desc' : 'asc')
            : \App\Http\Controllers\LogController::SORTS[$column];

        return request()->fullUrlWithQuery(['sort' => $column, 'dir' => $next, 'page' => null]);
    };
    $sortIcon = fn (string $column) => $sort !== $column
        ? 'bi-chevron-expand'
        : ($dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down');
    $ariaSort = fn (string $column) => $sort !== $column ? 'none' : ($dir === 'asc' ? 'ascending' : 'descending');

    $columns = [
        'bot' => ['label' => 'Bot profile', 'style' => 'min-width: 190px;'],
        'opening' => ['label' => 'Opening message', 'style' => 'min-width: 220px;'],
        'turns' => ['label' => 'Turns', 'style' => 'width: 90px;'],
        'origin' => ['label' => 'Origin', 'style' => 'min-width: 160px;'],
        'started' => ['label' => 'Started', 'style' => 'width: 120px;'],
    ];
@endphp

<div class="page-head page-head-wide mb-4">
    <div>
        <h1>Conversations</h1>
        @if($multiWorkspace)
            <p>Every session recorded in the workspaces you can open, from embedded widgets and from the preview sandbox.</p>
        @else
            <p>Every session recorded in {{ $activeSystem->name }}, from embedded widgets and from the preview sandbox.</p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <span>Recorded sessions</span>
        <span class="chip figure-mono">{{ $conversations->total() }} {{ $filtered ? 'found' : 'total' }}</span>
    </div>

    {{-- One GET form, so every filter lands in the address bar and a
         filtered, sorted view can be bookmarked or shared. --}}
    <form method="GET" action="{{ route('logs.index') }}" class="list-toolbar" role="search">
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">

        <div class="list-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label for="conv_search" class="visually-hidden">Search conversations</label>
            <input type="search" name="q" id="conv_search" class="form-control" value="{{ $search }}"
                   placeholder="Search messages, origin, session or bot" autocomplete="off">
        </div>

        {{-- Bots grouped by workspace, each ticked on its own or a whole
             workspace at once. Every box ticked sends nothing, which the
             list reads as all, so the address stays short. --}}
        <div class="dropdown bot-picker" id="botPicker">
            <button type="button" class="form-select list-select bot-picker-toggle" data-bs-toggle="dropdown"
                    data-bs-auto-close="outside" aria-expanded="false" aria-haspopup="true">
                <span class="text-truncate">{{ $pickerLabel }}</span>
            </button>
            <div class="dropdown-menu bot-picker-menu">
                @if($allBots->count() > 8)
                    <div class="bot-picker-filter">
                        <input type="search" class="form-control form-control-sm" placeholder="Find a bot or workspace"
                               aria-label="Find a bot or workspace" autocomplete="off" data-pick-filter>
                    </div>
                @endif

                <label class="bot-picker-row bot-picker-all">
                    <input type="checkbox" class="form-check-input" data-pick-all @checked(empty($selectedBots))>
                    <span class="bot-picker-name">All bot profiles</span>
                    <span class="bot-picker-count figure-mono">{{ $allBots->count() }}</span>
                </label>

                <div class="bot-picker-list">
                    @forelse($botGroups as $group)
                        <div class="bot-picker-group" data-pick-group data-pick-text="{{ mb_strtolower($group['system']->name) }}">
                            <label class="bot-picker-row bot-picker-head">
                                <input type="checkbox" class="form-check-input" data-pick-workspace>
                                <span class="bot-picker-name">{{ $group['system']->name }}</span>
                                <span class="bot-picker-count figure-mono">{{ $group['bots']->count() }}</span>
                            </label>
                            @foreach($group['bots'] as $b)
                                <label class="bot-picker-row bot-picker-item" data-pick-text="{{ mb_strtolower($b->name) }}">
                                    <input type="checkbox" class="form-check-input" name="bots[]" value="{{ $b->id }}" @checked(empty($selectedBots) || in_array($b->id, $selectedBots, true))>
                                    <span class="bot-picker-name">{{ $b->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    @empty
                        <div class="bot-picker-none">No bot profiles yet.</div>
                    @endforelse
                    <div class="bot-picker-none" data-pick-nomatch hidden>Nothing matches.</div>
                </div>

                <div class="bot-picker-foot">
                    <span class="bot-picker-hint" data-pick-hint></span>
                    <button type="submit" class="btn btn-sm btn-brand" data-pick-apply>Apply</button>
                </div>
            </div>
        </div>

        <label for="per_page" class="visually-hidden">Rows per page</label>
        <select name="per_page" id="per_page" class="form-select list-select list-select-narrow" onchange="this.form.submit()">
            @foreach(\App\Http\Controllers\LogController::PER_PAGE as $size)
                <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }} per page</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-outline-secondary">Search</button>
        @if($filtered)
            <a href="{{ route('logs.index', array_filter(['sort' => $sort, 'dir' => $dir, 'per_page' => $perPage === 25 ? null : $perPage])) }}"
               class="btn btn-link list-reset">Clear filters</a>
        @endif
    </form>

    @if($conversations->isEmpty())
        <div class="empty">
            @if($filtered)
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
                                <div class="fw-semibold">{{ $conv->bot_name ?? 'Deleted profile' }}</div>
                                @if($multiWorkspace && $conv->bot?->system)
                                    <div class="conv-workspace">{{ $conv->bot->system->name }}</div>
                                @endif
                                <div class="figure-mono text-muted" style="font-size: 0.6875rem;">
                                    {{ \Illuminate\Support\Str::limit($conv->session_id, 22) }}
                                </div>
                            </td>
                            <td>
                                <span class="opening-clamp {{ $conv->opening_message === null ? 'text-muted' : '' }}"
                                      title="{{ \Illuminate\Support\Str::limit($conv->opening_message, 500) }}">
                                    {{ $conv->opening_message ?? 'No messages' }}
                                </span>
                            </td>
                            <td><span class="figure-mono">{{ $conv->messages_count }}</span></td>
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

<div class="modal fade" id="transcriptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            {{-- What was said once per message now sits here once for the
                 session: who, where from, when, and which models did the work. --}}
            <div class="modal-header transcript-head">
                <span class="identity transcript-avatar" id="transcriptAvatar"><i class="bi bi-chat-text"></i></span>
                <div class="transcript-heading">
                    <h6 class="modal-title" id="modalBotName">Transcript</h6>
                    <div class="transcript-facts" id="transcriptFacts">Loading</div>
                </div>
                <div class="transcript-models" id="transcriptModels"></div>
                <button type="button" class="btn-close transcript-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body overflow-auto transcript-body" id="transcriptBody"></div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
{{-- The same renderer the widget uses, so a transcript reads the way the
     visitor saw it rather than as raw Markdown. --}}
<script src="{{ \App\Services\EngineClient::baseUrl() }}/widget-markdown.js"></script>
<style>
    .transcript-md > *:first-child { margin-top: 0; }
    .transcript-md > *:last-child { margin-bottom: 0; }
    .transcript-md p { margin: 0 0 0.5rem; }
    .transcript-md ul, .transcript-md ol { margin: 0 0 0.5rem; padding-left: 1.25rem; }
    .transcript-md li { margin: 0.1rem 0; }
    .transcript-md code { font-size: 0.75rem; background: var(--surface-2, #f4f4f5); border: 1px solid var(--border); border-radius: 4px; padding: 0 3px; }
    .transcript-md pre { margin: 0 0 0.5rem; padding: 0.5rem 0.65rem; background: #18181B; border-radius: 6px; overflow-x: auto; }
    .transcript-md pre code { background: none; border: 0; padding: 0; color: #F4F4F5; }
    .transcript-md .md-table { overflow-x: auto; margin: 0 0 0.5rem; }
    .transcript-md table { border-collapse: collapse; font-size: 0.75rem; width: 100%; }
    .transcript-md th, .transcript-md td { border: 1px solid var(--border); padding: 0.25rem 0.4rem; vertical-align: top; overflow-wrap: anywhere; min-width: 84px; }
    .transcript-head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; padding: 0.875rem 1rem; }
    .transcript-avatar { flex-shrink: 0; }
    .transcript-heading { flex: 1 1 200px; min-width: 0; }
    .transcript-heading .modal-title { font-size: 0.9375rem; font-weight: 600; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .transcript-facts { font-size: 0.6875rem; line-height: 1.45; color: var(--text-muted); margin-top: 0.125rem; }
    .transcript-facts > div { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .transcript-origin { color: var(--text-faint); }
    .transcript-models { order: 2; display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.3125rem; max-width: 60%; }
    .transcript-models:empty { display: none; }
    .transcript-model { font-family: var(--font-mono); font-size: 0.6875rem; }
    .transcript-model b { font-family: 'Geist', sans-serif; font-weight: 500; color: var(--text-faint); text-transform: uppercase; letter-spacing: 0.04em; font-size: 0.625rem; }
    .transcript-close { order: 3; margin: 0 0 0 0.25rem !important; }
    .transcript-body { max-height: 560px; min-height: 220px; background: var(--bg); }
    @media (max-width: 575.98px) {
        .transcript-models { order: 4; max-width: none; width: 100%; justify-content: flex-start; }
    }
    .transcript-md blockquote { margin: 0 0 0.5rem; padding-left: 0.6rem; border-left: 3px solid var(--border); }
</style>
<script>
    // ---- Bot picker ----------------------------------------------------------
    (function () {
        var picker = document.getElementById('botPicker');
        if (!picker) return;

        var form = picker.closest('form');
        var all = picker.querySelector('[data-pick-all]');
        var bots = Array.prototype.slice.call(picker.querySelectorAll('input[name="bots[]"]'));
        var groups = Array.prototype.slice.call(picker.querySelectorAll('[data-pick-group]'));
        var apply = picker.querySelector('[data-pick-apply]');
        var hint = picker.querySelector('[data-pick-hint]');
        var filter = picker.querySelector('[data-pick-filter]');
        var noMatch = picker.querySelector('[data-pick-nomatch]');

        // A box over several reads ticked, empty or part-ticked from them.
        function mirror(box, members) {
            var ticked = members.filter(function (m) { return m.checked; }).length;
            box.checked = members.length > 0 && ticked === members.length;
            box.indeterminate = ticked > 0 && ticked < members.length;
        }

        function groupBots(group) {
            return Array.prototype.slice.call(group.querySelectorAll('input[name="bots[]"]'));
        }

        function sync() {
            groups.forEach(function (group) {
                mirror(group.querySelector('[data-pick-workspace]'), groupBots(group));
            });
            mirror(all, bots);

            var ticked = bots.filter(function (b) { return b.checked; }).length;
            apply.disabled = bots.length > 0 && ticked === 0;
            hint.textContent = ticked === 0 ? 'Tick at least one'
                : (ticked === bots.length ? 'All selected' : ticked + ' of ' + bots.length + ' selected');
        }

        all.addEventListener('change', function () {
            bots.forEach(function (b) { b.checked = all.checked; });
            sync();
        });
        groups.forEach(function (group) {
            var head = group.querySelector('[data-pick-workspace]');
            head.addEventListener('change', function () {
                groupBots(group).forEach(function (b) { b.checked = head.checked; });
                sync();
            });
        });
        bots.forEach(function (b) { b.addEventListener('change', sync); });

        if (filter) {
            filter.addEventListener('input', function () {
                var q = filter.value.trim().toLowerCase();
                var shown = 0;
                groups.forEach(function (group) {
                    var groupHit = !q || group.dataset.pickText.indexOf(q) !== -1;
                    var any = false;
                    group.querySelectorAll('.bot-picker-item').forEach(function (item) {
                        var hit = groupHit || item.dataset.pickText.indexOf(q) !== -1;
                        item.hidden = !hit;
                        any = any || hit;
                    });
                    group.hidden = !any;
                    if (any) shown++;
                });
                noMatch.hidden = shown > 0;
            });
            picker.addEventListener('shown.bs.dropdown', function () { filter.focus(); });
        }

        // Everything ticked is the same as nothing chosen: send no ids.
        form.addEventListener('submit', function () {
            if (bots.every(function (b) { return b.checked; })) {
                bots.forEach(function (b) { b.disabled = true; });
            }
        });

        sync();
    })();

    function transcriptSkeleton() {
        var rows = '';
        var widths = ['62%', '44%', '72%'];
        for (var i = 0; i < widths.length; i++) {
            var alignSelf = i % 2 === 1 ? 'margin-left:auto;' : '';
            rows += '<div class="skeleton mb-3" style="height: 42px; width: ' + widths[i] + '; ' + alignSelf + '"></div>';
        }
        return rows;
    }

    // An ISO timestamp read as local time, or left as it came.
    function transcriptTime(value) {
        var date = value ? new Date(value) : null;
        return date && !isNaN(date) ? date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : (value || '');
    }

    function renderTranscriptHead(data) {
        document.getElementById('modalBotName').textContent = data.bot_name;
        document.getElementById('transcriptAvatar').textContent = (data.bot_name || '?').charAt(0).toUpperCase();

        // When and how many on one line, where from on the next. The full
        // session id is on hover: it is for matching logs, not for reading.
        var facts = document.getElementById('transcriptFacts');
        facts.innerHTML = '';
        var when = document.createElement('div');
        when.textContent = [data.started_at, data.message_count + (data.message_count === 1 ? ' message' : ' messages')]
            .filter(Boolean).join(' \u00b7 ');
        facts.appendChild(when);
        var origin = document.createElement('div');
        origin.className = 'figure-mono transcript-origin';
        origin.textContent = data.origin || 'preview sandbox';
        facts.appendChild(origin);
        facts.title = data.session_id ? 'Session ' + data.session_id : '';

        // One chip per job; a job whose model changed mid-session lists both.
        var models = document.getElementById('transcriptModels');
        models.innerHTML = '';
        Object.keys(data.models || {}).forEach(function (job) {
            var chip = document.createElement('span');
            chip.className = 'chip transcript-model';
            chip.title = job + ': ' + data.models[job].join(', ');
            var label = document.createElement('b');
            label.textContent = job;
            chip.appendChild(label);
            chip.appendChild(document.createTextNode(data.models[job].join(' / ')));
            models.appendChild(chip);
        });
    }

    function viewTranscript(convId) {
        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('transcriptModal'));
        var body = document.getElementById('transcriptBody');

        document.getElementById('modalBotName').textContent = 'Transcript';
        document.getElementById('transcriptAvatar').innerHTML = '<i class="bi bi-chat-text"></i>';
        document.getElementById('transcriptFacts').textContent = 'Loading';
        document.getElementById('transcriptModels').innerHTML = '';
        body.innerHTML = transcriptSkeleton();
        modal.show();

        fetch('/logs/' + encodeURIComponent(convId) + '/transcript')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                renderTranscriptHead(data);
                body.innerHTML = '';

                if (!data.messages || data.messages.length === 0) {
                    body.innerHTML = '<div class="empty"><i class="bi bi-chat-left-text"></i>' +
                                     '<h6>No messages in this session</h6>' +
                                     '<p>The session was opened but nothing was sent.</p></div>';
                    return;
                }

                data.messages.forEach(function (msg) {
                    var isUser = msg.sender === 'user';

                    var wrapper = document.createElement('div');
                    wrapper.className = 'd-flex flex-column mb-3 ' + (isUser ? 'align-items-end' : 'align-items-start');

                    var bubble = document.createElement('div');
                    bubble.style.maxWidth = '78%';
                    bubble.style.padding = '0.5rem 0.75rem';
                    bubble.style.fontSize = '0.8125rem';
                    bubble.style.lineHeight = '1.55';
                    bubble.style.border = '1px solid var(--border)';
                    bubble.style.background = isUser ? 'var(--accent-soft)' : 'var(--surface)';
                    bubble.style.color = 'var(--text)';
                    bubble.style.borderRadius = isUser
                        ? 'var(--r-md) var(--r-md) var(--r-xs) var(--r-md)'
                        : 'var(--r-md) var(--r-md) var(--r-md) var(--r-xs)';
                    if (isUser) { bubble.style.borderColor = 'var(--accent-line)'; }
                    // A visitor's own words stay literal; only the bot's
                    // Markdown is turned into markup.
                    var renderer = window.__ChatbotMarkdown;
                    if (!isUser && renderer) {
                        bubble.innerHTML = renderer.render(msg.content);
                        bubble.classList.add('transcript-md');
                    } else {
                        bubble.style.whiteSpace = 'pre-wrap';
                        bubble.textContent = msg.content;
                    }

                    if (!isUser && msg.reasoning) {
                        var think = document.createElement('details');
                        think.style.maxWidth = '78%';
                        think.style.marginBottom = '0.35rem';
                        think.style.fontSize = '0.75rem';
                        think.style.border = '1px solid var(--border)';
                        think.style.borderRadius = 'var(--r-sm, 6px)';
                        think.style.background = 'var(--surface-2, var(--surface))';
                        think.style.padding = '0.35rem 0.6rem';

                        var summary = document.createElement('summary');
                        summary.className = 'text-muted';
                        summary.style.cursor = 'pointer';
                        summary.textContent = 'Thinking';
                        think.appendChild(summary);

                        var thought = document.createElement('div');
                        thought.className = 'text-muted mt-2';
                        thought.style.whiteSpace = 'pre-wrap';
                        thought.style.lineHeight = '1.55';
                        thought.style.maxHeight = '220px';
                        thought.style.overflowY = 'auto';
                        thought.textContent = msg.reasoning;
                        think.appendChild(thought);

                        wrapper.appendChild(think);
                    }

                    if (!isUser && msg.db_sql) {
                        // Auditing a wrong answer needs the statement, not a
                        // guess at it. Folded away, like the reasoning above.
                        var query = document.createElement('details');
                        query.style.maxWidth = '78%';
                        query.style.marginBottom = '0.35rem';
                        query.style.fontSize = '0.75rem';
                        query.style.border = '1px solid var(--border)';
                        query.style.borderRadius = 'var(--r-sm, 6px)';
                        query.style.background = 'var(--surface-2, var(--surface))';
                        query.style.padding = '0.35rem 0.6rem';

                        var querySummary = document.createElement('summary');
                        querySummary.className = 'text-muted';
                        querySummary.style.cursor = 'pointer';
                        querySummary.textContent = 'Answered from live data, '
                            + (msg.db_row_count === null ? 'unknown' : msg.db_row_count) + ' rows';
                        query.appendChild(querySummary);

                        var statement = document.createElement('pre');
                        statement.className = 'figure-mono text-muted mt-2 mb-0';
                        statement.style.whiteSpace = 'pre-wrap';
                        statement.style.fontSize = '0.72rem';
                        statement.textContent = msg.db_sql;
                        query.appendChild(statement);

                        wrapper.appendChild(query);
                    }

                    // The time stays within reach on hover. Who spoke is the side
                    // the bubble sits on, and the models are in the header.
                    bubble.title = (isUser ? 'Visitor' : data.bot_name) + ', ' + transcriptTime(msg.created_at);

                    wrapper.appendChild(bubble);

                    // What the intent step made of a visitor's message. Without
                    // it, an answer about the wrong product reads as the bot's
                    // mistake rather than the question's.
                    if (isUser && (msg.intent === 'chat' || msg.intent_query)) {
                        var understood = document.createElement('span');
                        understood.className = 'text-muted mt-1';
                        understood.style.fontSize = '0.6875rem';
                        understood.style.maxWidth = '78%';
                        understood.textContent = msg.intent === 'chat'
                            ? 'Read as small talk, so no source was searched'
                            : 'Searched as: ' + msg.intent_query;
                        wrapper.appendChild(understood);
                    }

                    // What the guard named. On a visitor's message it was
                    // refused; on an answer it had already been sent.
                    if (msg.guard_flag) {
                        var flag = document.createElement('span');
                        flag.className = 'badge bg-danger-subtle text-danger-emphasis mt-1';
                        flag.style.fontSize = '0.6875rem';
                        flag.textContent = (isUser ? 'Refused by the guard: ' : 'Flagged by the guard: ') + msg.guard_flag;
                        wrapper.appendChild(flag);
                    }

                    body.appendChild(wrapper);
                });
            })
            .catch(function () {
                document.getElementById('transcriptFacts').textContent = 'Not loaded';
                body.innerHTML = '<div class="empty"><i class="bi bi-exclamation-triangle"></i>' +
                                 '<h6>Could not load the transcript</h6>' +
                                 '<p>The request to the server failed. Try again in a moment.</p></div>';
            });
    }
</script>
@endpush
