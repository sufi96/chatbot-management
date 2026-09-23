@extends('layouts.app')

@section('page-title', 'Analytics')

@section('content')

@if($report)
@php
    $k = $report['kpis'];
    $p = $report['previous'];

    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format($value, $decimals);
    $pct = fn ($value) => $value === null ? '—' : round($value * 100) . '%';
    $secs = fn ($ms) => $ms === null ? '—' : ($ms < 1000 ? $ms . 'ms' : number_format($ms / 1000, 1) . 's');

    $rawChange = function (string $key, string $as) use ($k, $p) {
        $now = $k[$key];
        $before = $p[$key];
        if ($now === null || $before === null) {
            return null;
        }
        if ($as === 'rate') {
            $points = round(($now - $before) * 100);
            return $points === 0.0 ? ['flat', 'No change'] : [$points > 0 ? 'up' : 'down', abs($points) . ' pts'];
        }
        if ($as === 'ms') {
            if ($now === $before) {
                return ['flat', 'No change'];
            }
            return [$now > $before ? 'up' : 'down', number_format(abs($now - $before) / 1000, 1) . 's'];
        }
        if ((float) $before === 0.0) {
            return $now > 0 ? ['up', 'New'] : ['flat', 'No change'];
        }
        $percent = round(($now - $before) / $before * 100);
        return $percent == 0 ? ['flat', 'No change'] : [$percent > 0 ? 'up' : 'down', abs($percent) . '%'];
    };

    // The change from the window before, in words and an arrow, coloured by
    // whether it is good news: $better says which way that is. More flags is
    // worse and more conversations is better. Holding still is good news only
    // where lower is better: no new flags, no slower replies.
    $change = function (string $key, string $as = 'count', string $better = 'up') use ($rawChange) {
        $delta = $rawChange($key, $as);
        if ($delta === null) {
            return null;
        }
        $delta[2] = match (true) {
            $delta[0] === 'flat' => $better === 'down' ? 'good' : 'neutral',
            $delta[0] === $better => 'good',
            default => 'bad',
        };
        return $delta;
    };

    $tiles = [
        ['Conversations', $num($k['conversations']), $change('conversations'), $num($k['new_conversations']) . ' started in this window'],
        ['Visitor messages', $num($k['visitor_messages']), $change('visitor_messages'), $num($k['replies']) . ' bot replies'],
        ['Messages per conversation', $num($k['messages_per_conversation'], 1), $change('messages_per_conversation'), $pct($k['bounce_rate']) . ' left after one message'],
        ['Tokens used', $num($k['tokens']), $change('tokens', 'count', 'down'), $num($k['tokens_per_reply']) . ' per reply'],
        ['First token, median', $secs($k['first_token_median']), $change('first_token_median', 'ms', 'down'), 'How soon the reply starts'],
        ['Full reply, p95', $secs($k['response_p95']), $change('response_p95', 'ms', 'down'), $secs($k['response_median']) . ' median'],
        ['Answer rate', $pct($k['answer_rate']), $change('answer_rate', 'rate'), 'Searches where a source had something'],
        ['Flagged by the guard', $num($k['flagged']), $change('flagged', 'count', 'down'), $pct($k['flag_rate']) . ' of visitor messages'],
    ];

    $rangeLabel = $range === 'custom'
        ? 'Custom'
        : \App\Http\Controllers\AnalyticsController::RANGES[$range]['label'];

    $empty = $k['visitor_messages'] === 0 && $k['replies'] === 0;
    $series = $report['series'];
    $labelEvery = max(1, (int) ceil(count($series) / 10));

    $busiest = max(array_map('max', $report['heatmap']));
    $heatMax = max(1, $busiest);
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    $toLocal = fn ($moment) => $moment === null ? null : \Illuminate\Support\Carbon::parse($moment, 'UTC')->setTimezone($zone);
    $guarded = $bots->contains(fn ($bot) => (bool) $bot->guard_enabled);
@endphp
@endif

{{-- One GET form for the bots and the window, so a view can be bookmarked.
     The time zone is the viewer's own, filled in by the script below. --}}
<form method="GET" action="{{ route('analytics.index') }}" id="analyticsForm">
    <div class="page-head page-head-wide mb-3">
        <div>
            <h1>Analytics</h1>
            @if($multiWorkspace)
                <p>How visitors use the bots in the workspaces you can open. Pick bots on the right to narrow it down.</p>
            @else
                <p>How visitors use the bots in {{ $activeSystem->name }}. Pick bots on the right to narrow it down.</p>
            @endif
        </div>
        @if($botGroups->isNotEmpty())
            <div class="flex-shrink-0">
                @include('partials._bot-picker', ['pickerAlignEnd' => true])
            </div>
        @endif
    </div>

    @if($canReport)
        <input type="hidden" name="tz" value="{{ $zone }}" data-tz>
        @if($tab === 'kb')
            <input type="hidden" name="tab" value="kb">
        @endif
        {{-- Sent by Apply in the bot picker. A window button pressed later in
             the form sends its own range, which wins. --}}
        <input type="hidden" name="range" value="{{ $range }}">
        {{-- The conversations table's search, sort and page size, kept when
             the bots or the window change. --}}
        @foreach(['q' => $search !== '' ? $search : null, 'sort' => $sort, 'dir' => $dir, 'per_page' => $perPage] as $name => $value)
            @if($value !== null)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach
        <div class="an-toolbar mb-3">
            <div class="an-segment" role="group" aria-label="Time window">
                @foreach(\App\Http\Controllers\AnalyticsController::RANGES as $key => $option)
                    <button type="submit" name="range" value="{{ $key }}" class="{{ $range === $key ? 'is-active' : '' }}"
                            aria-pressed="{{ $range === $key ? 'true' : 'false' }}">{{ $key }}</button>
                @endforeach
                <button type="button" class="{{ $range === 'custom' ? 'is-active' : '' }}" data-custom-toggle
                        aria-pressed="{{ $range === 'custom' ? 'true' : 'false' }}">Custom</button>
            </div>

            <div class="an-custom" data-custom @if($range !== 'custom') hidden @endif>
                <label class="visually-hidden" for="an_from">From</label>
                <input type="date" id="an_from" name="from" class="form-control form-control-sm" value="{{ $from->format('Y-m-d') }}">
                <span class="text-muted">to</span>
                <label class="visually-hidden" for="an_to">To</label>
                <input type="date" id="an_to" name="to" class="form-control form-control-sm" value="{{ $to->copy()->subSecond()->format('Y-m-d') }}">
                <button type="submit" name="range" value="custom" class="btn btn-sm btn-brand">Apply</button>
            </div>

            <div class="an-window figure-mono">
                {{ $from->format('M j, Y H:i') }} – {{ $to->format('M j, Y H:i') }} <span class="text-faint">{{ $zone }}</span>
            </div>
        </div>
    @endif
</form>

@if($canReport)
    {{-- Links, not scripts: each tab is its own report, built only when open. --}}
    <nav class="an-tabs mb-4" aria-label="Analytics">
        @foreach(['bots' => ['Bot analytics', 'bi-robot'], 'kb' => ['Knowledge base', 'bi-journal-text']] as $key => [$tabLabel, $tabIcon])
            <a href="{{ route('analytics.index', array_merge(request()->except(['tab', 'page']), $key === 'kb' ? ['tab' => 'kb'] : [])) }}"
               class="{{ $tab === $key ? 'is-active' : '' }}" @if($tab === $key) aria-current="page" @endif>
                <i class="bi {{ $tabIcon }}"></i> {{ $tabLabel }}
            </a>
        @endforeach
    </nav>
@endif

@if(!$canReport)
    <div class="card">
        <div class="empty">
            <i class="bi bi-bar-chart-line"></i>
            <h6>No bot profiles to report on</h6>
            <p>Once a workspace you can open has a bot profile, its figures appear here.</p>
            <a href="{{ route('bots.index') }}" class="btn btn-brand">Bot profiles</a>
        </div>
    </div>
@elseif($tab === 'kb')
    @include('analytics._kb', ['kb' => $kbReport])
@else


<div class="metrics an-metrics mb-2">
    @foreach($tiles as [$label, $figure, $delta, $note])
        <div class="metric">
            <span class="metric-label">{{ $label }}</span>
            <span class="metric-figure">{{ $figure }}</span>
            <div class="metric-note">
                @if($delta)
                    <span class="an-delta is-{{ $delta[2] }}" title="Compared with the {{ strtolower($rangeLabel === 'Custom' ? 'same length of time' : $rangeLabel) }} before">
                        <i class="bi {{ $delta[0] === 'up' ? 'bi-arrow-up-short' : ($delta[0] === 'down' ? 'bi-arrow-down-short' : 'bi-dash') }}"></i>{{ $delta[1] }}
                    </span>
                @endif
                <span>{{ $note }}</span>
            </div>
        </div>
    @endforeach
</div>
<p class="an-footnote mb-4">
    Arrows compare with the window of the same length just before this one.
    @if($report['untracked'] > 0)
        {{ number_format($report['untracked']) }} {{ \Illuminate\Support\Str::plural('reply', $report['untracked']) }} in this window came before answer tracking began, so {{ $report['untracked'] === 1 ? 'it has' : 'they have' }} no source or timing and {{ $report['untracked'] === 1 ? 'is' : 'are' }} left out of those figures.
    @endif
</p>

{{-- ---- Activity ------------------------------------------------------- --}}
<h2 class="an-section">Activity</h2>
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <span>Over time, by {{ $report['hourly'] ? 'hour' : 'day' }}</span>
                <div class="an-segment an-segment-sm" role="tablist" aria-label="Measure">
                    @foreach(['messages' => 'Visitor messages', 'conversations' => 'Conversations started', 'tokens' => 'Tokens', 'flagged' => 'Flagged'] as $measure => $measureLabel)
                        <button type="button" role="tab" data-measure="{{ $measure }}" class="{{ $loop->first ? 'is-active' : '' }}"
                                aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ $measureLabel }}</button>
                    @endforeach
                </div>
            </div>
            <div class="p-3">
                @foreach(['messages' => 'Visitor messages', 'conversations' => 'Conversations started', 'tokens' => 'Tokens', 'flagged' => 'Flagged'] as $measure => $measureLabel)
                    @php
                        $values = array_column($series, $measure);
                        $peak = max(1, $values ? max($values) : 0);
                        $sum = array_sum($values);
                    @endphp
                    <div class="an-chart" data-chart="{{ $measure }}" @unless($loop->first) hidden @endunless>
                        <div class="an-chart-caption">
                            <span class="figure-mono">{{ number_format($sum) }}</span> {{ strtolower($measureLabel) }} in total
                        </div>
                        <div class="an-plot">
                            <div class="an-axis figure-mono" aria-hidden="true">
                                <span>{{ number_format($peak) }}</span>
                                <span>{{ number_format($peak / 2, $peak < 2 ? 1 : 0) }}</span>
                                <span>0</span>
                            </div>
                            <div class="an-columns" role="img" aria-label="{{ $measureLabel }} per {{ $report['hourly'] ? 'hour' : 'day' }}, peaking at {{ number_format($peak) }}">
                                @foreach($series as $point)
                                    <div class="an-column" tabindex="0" data-tip="{{ $point['title'] }}: {{ number_format($point[$measure]) }}">
                                        <div class="an-column-fill {{ $point[$measure] === 0 ? 'is-zero' : '' }}"
                                             style="height: {{ round($point[$measure] / $peak * 100, 2) }}%"></div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="an-xlabels figure-mono" aria-hidden="true">
                            @foreach($series as $i => $point)
                                {{-- None in the last half-step, where a label would run off the card. --}}
                                <span>{{ $i % $labelEvery === 0 && $i + $labelEvery / 2 < count($series) ? $point['label'] : '' }}</span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card h-100">
            <div class="card-header">Busy hours</div>
            <div class="p-3">
                <div class="an-chart-caption">Visitor messages by weekday and hour, {{ $zone }}</div>
                <div class="an-heat" role="table" aria-label="Visitor messages by weekday and hour">
                    @foreach($report['heatmap'] as $d => $hours)
                        <div class="an-heat-row" role="row">
                            <span class="an-heat-day" role="rowheader">{{ $days[$d] }}</span>
                            @foreach($hours as $h => $count)
                                @php $step = $count === 0 ? 0 : (int) ceil($count / $heatMax * 4); @endphp
                                <span class="an-heat-cell is-{{ $step }}" role="cell" tabindex="0"
                                      data-tip="{{ $days[$d] }} {{ sprintf('%02d:00', $h) }}: {{ number_format($count) }} {{ \Illuminate\Support\Str::plural('message', $count) }}"
                                      aria-label="{{ $days[$d] }} {{ sprintf('%02d:00', $h) }}: {{ $count }}"></span>
                            @endforeach
                        </div>
                    @endforeach
                    <div class="an-heat-row an-heat-hours figure-mono" aria-hidden="true">
                        <span class="an-heat-day"></span>
                        @for($h = 0; $h < 24; $h++)
                            <span>{{ $h % 6 === 0 ? sprintf('%02d', $h) : '' }}</span>
                        @endfor
                    </div>
                </div>
                <div class="an-heat-legend" aria-hidden="true">
                    <span>Fewer</span>
                    @for($s = 0; $s <= 4; $s++)<span class="an-heat-cell is-{{ $s }}"></span>@endfor
                    <span>More</span>
                    <span class="ms-auto figure-mono">Busiest hour: {{ number_format($busiest) }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ---- Visitors and questions ------------------------------------------ --}}
<h2 class="an-section">Visitors</h2>
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header">Where they come from</div>
            <div class="p-3">
                <div class="an-split mb-3">
                    <div><span class="figure-mono">{{ number_format($k['new_conversations']) }}</span> new conversations</div>
                    <div><span class="figure-mono">{{ number_format($report['returning']) }}</span> returning, started earlier</div>
                </div>
                @if(empty($report['origins']))
                    <div class="an-none">No conversations in this window.</div>
                @else
                    @include('analytics._bars', [
                        'items' => collect($report['origins'])->map(fn ($count, $origin) => ['label' => $origin, 'value' => $count, 'mono' => true])->values()->all(),
                        'total' => $k['conversations'],
                    ])
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header">What they ask</div>
            <div class="p-3">
                @php $intentTotal = array_sum($report['intents']); @endphp
                <div class="an-subhead">How messages were read</div>
                @if($intentTotal === 0)
                    <div class="an-none mb-3">No visitor messages in this window.</div>
                @else
                    <div class="mb-3">
                        @include('analytics._bars', [
                            'items' => array_values(array_filter([
                                ['label' => 'Questions, sources searched', 'value' => $report['intents']['facts']],
                                ['label' => 'Small talk, nothing searched', 'value' => $report['intents']['chat']],
                                ['label' => 'Not classified', 'value' => $report['intents']['unread']],
                            ], fn ($item) => $item['value'] > 0)),
                            'total' => $intentTotal,
                        ])
                        @if(!$report['intent_enabled'] && $report['intents']['unread'] > 0)
                            <div class="an-footnote mt-2">Messages are only classified when intent is switched on in the bot's Behaviour settings.</div>
                        @endif
                    </div>
                @endif

                <div class="an-subhead">Most asked</div>
                @if(empty($report['top_questions']))
                    <div class="an-none">Nothing asked in this window.</div>
                @else
                    <ol class="an-list">
                        @foreach($report['top_questions'] as $question)
                            <li>
                                <span class="an-list-text" title="{{ $question['text'] }}">{{ $question['text'] }}</span>
                                <span class="chip figure-mono">{{ number_format($question['count']) }}×</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ---- Answers ---------------------------------------------------------- --}}
<h2 class="an-section">Answers</h2>
<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Where answers came from</div>
            <div class="p-3">
                @php $tracked = array_sum($report['sources']); @endphp
                @if($tracked === 0)
                    <div class="an-none">No tracked replies in this window yet. Replies are tracked from the moment this page was added.</div>
                @else
                    @include('analytics._bars', [
                        'items' => collect(\App\Services\Analytics::SOURCE_KINDS)
                            ->map(fn ($label, $kind) => ['label' => $label, 'value' => $report['sources'][$kind], 'tone' => $kind === 'none' ? 'warn' : null])
                            ->filter(fn ($item) => $item['value'] > 0)->values()->all(),
                        'total' => $tracked,
                    ])
                @endif
                @if($report['db_answers'] > 0)
                    <div class="an-split mt-3">
                        <div><span class="figure-mono">{{ number_format($report['db_answers']) }}</span> answered from live data</div>
                        <div><span class="figure-mono">{{ $num($report['db_rows_avg'], 1) }}</span> rows on average</div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Response time</div>
            <div class="p-3">
                <div class="an-split mb-3">
                    <div><span class="figure-mono">{{ $secs($k['first_token_median']) }}</span> first token, median</div>
                    <div><span class="figure-mono">{{ $secs($report['first_token_p95']) }}</span> first token, p95</div>
                    <div><span class="figure-mono">{{ $secs($k['response_median']) }}</span> full reply, median</div>
                    <div><span class="figure-mono">{{ $secs($k['response_p95']) }}</span> full reply, p95</div>
                </div>
                @if($report['timed_replies'] === 0)
                    <div class="an-none">No timed replies in this window yet.</div>
                @else
                    <div class="an-subhead">Full reply, {{ number_format($report['timed_replies']) }} timed</div>
                    @include('analytics._bars', [
                        'items' => collect(\App\Services\Analytics::LATENCY_BANDS)
                            ->map(fn ($band, $i) => ['label' => $band[1], 'value' => $report['latency_bands'][$i]])->all(),
                        'total' => $report['timed_replies'],
                    ])
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Documents that answered</div>
            <div class="p-3">
                @if(empty($report['cited']))
                    <div class="an-none mb-3">No knowledge base document was cited in this window.</div>
                @else
                    <div class="mb-3">
                        @include('analytics._bars', [
                            'items' => collect($report['cited'])->map(fn ($doc, $id) => [
                                'label' => $doc['title'], 'value' => $doc['count'],
                                'href' => route('kb.sources.show', $id),
                            ])->values()->all(),
                        ])
                    </div>
                @endif

                @if($report['unused_documents']->isNotEmpty())
                    <div class="an-subhead">Never cited in this window</div>
                    <div class="an-tags">
                        @foreach($report['unused_documents'] as $doc)
                            <a href="{{ route('kb.sources.show', $doc->id) }}" class="chip" title="{{ $doc->title }}">{{ \Illuminate\Support\Str::limit($doc->title, 40) }}</a>
                        @endforeach
                    </div>
                    <div class="an-footnote mt-2">Ready documents in these bots' collections that no answer drew on. Showing up to 12.</div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Web sources and models</div>
            <div class="p-3">
                <div class="an-subhead">Sites cited</div>
                @if(empty($report['domains']))
                    <div class="an-none mb-3">No web results were cited in this window.</div>
                @else
                    <div class="mb-3">
                        @include('analytics._bars', [
                            'items' => collect($report['domains'])->map(fn ($count, $host) => ['label' => $host, 'value' => $count, 'mono' => true])->values()->all(),
                        ])
                    </div>
                @endif

                <div class="an-subhead">Models by job</div>
                @if(empty($report['models']))
                    <div class="an-none">No model trace in this window.</div>
                @else
                    <table class="table table-sm mb-0 an-table">
                        <thead><tr><th>Job</th><th>Model</th><th class="text-end">Replies</th></tr></thead>
                        <tbody>
                            @foreach($report['models'] as $job => $byModel)
                                @foreach($byModel as $model => $count)
                                    <tr>
                                        <td class="text-muted">{{ $loop->first ? ucfirst($job) : '' }}</td>
                                        <td class="figure-mono">{{ $model }}</td>
                                        <td class="text-end figure-mono">{{ number_format($count) }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ---- Gaps and safety -------------------------------------------------- --}}
<h2 class="an-section">Gaps and safety</h2>
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Questions the sources had nothing for</div>
            @if(empty($report['gaps']))
                <div class="p-3"><div class="an-none">Every search in this window found something.</div></div>
            @else
                <ul class="an-feed">
                    @foreach($report['gaps'] as $gap)
                        <li>
                            <button type="button" class="an-feed-item" onclick="viewTranscript('{{ $gap['conversation_id'] }}')">
                                <span class="an-feed-main">{{ \Illuminate\Support\Str::limit($gap['question'] ?? 'Message not found', 140) }}</span>
                                <span class="an-feed-sub">@if($gap['bot'])<b>{{ $gap['bot'] }}</b> · @endif{{ \Illuminate\Support\Str::limit($gap['answer'], 120) }}</span>
                                <span class="an-feed-when figure-mono">{{ $gap['at']->copy()->setTimezone($zone)->format('M j, H:i') }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Guard</div>
            <div class="p-3" style="border-bottom: 1px solid var(--border);">
                @if(empty($report['flag_categories']))
                    <div class="an-none">
                        {{ $guarded ? 'Nothing was refused or flagged in this window.' : 'The guard is switched off for every bot here.' }}
                    </div>
                @else
                    @include('analytics._bars', [
                        'items' => collect($report['flag_categories'])->map(fn ($count, $category) => ['label' => $category, 'value' => $count, 'tone' => 'danger'])->values()->all(),
                        'total' => $k['flagged'],
                    ])
                @endif
            </div>
            @if($report['flagged']->isNotEmpty())
                <ul class="an-feed">
                    @foreach($report['flagged'] as $message)
                        <li>
                            <button type="button" class="an-feed-item" onclick="viewTranscript('{{ $message->conversation_id }}')">
                                <span class="an-feed-main">{{ \Illuminate\Support\Str::limit($message->content, 140) }}</span>
                                <span class="an-feed-sub">
                                    @if($message->conversation?->bot)<b>{{ $message->conversation->bot->name }}</b> · @endif
                                    <span class="badge bg-danger-subtle text-danger-emphasis">{{ $message->sender === 'user' ? 'Refused' : 'Answer flagged' }}: {{ $message->guard_flag }}</span>
                                </span>
                                <span class="an-feed-when figure-mono">{{ $message->created_at->copy()->setTimezone($zone)->format('M j, H:i') }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

{{-- ---- By bot profile ------------------------------------------------ --}}
@if(count($report['by_bot']) > 1)
    <h2 class="an-section">By bot profile</h2>
    <div class="card mb-4">
        <div class="table-responsive">
            <table class="table table-hover mb-0 an-bot-table">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Bot profile</th>
                        <th class="text-end">Conversations</th>
                        <th class="text-end">Visitor messages</th>
                        <th class="text-end">Replies</th>
                        <th class="text-end">Tokens</th>
                        <th class="text-end">Answer rate</th>
                        <th class="text-end">Flagged</th>
                        <th style="min-width: 140px;">Share of messages</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['by_bot'] as $row)
                        @php $share = $k['visitor_messages'] ? $row['visitor_messages'] / $k['visitor_messages'] : 0; @endphp
                        <tr>
                            <td>
                                <a href="{{ route('analytics.index', ($row['bot']->is_platform ? ['console' => 'only'] : ['bots' => [$row['bot']->id]]) + ['range' => $range, 'from' => $range === 'custom' ? $from->format('Y-m-d') : null, 'to' => $range === 'custom' ? $to->copy()->subSecond()->format('Y-m-d') : null, 'tz' => $zone]) }}"
                                   class="fw-semibold an-bot-link" title="Only {{ $row['bot']->name }}">{{ $row['bot']->name }}</a>
                                @if($row['bot']->is_platform)
                                    <span class="badge bot-picker-builtin ms-1">Built in</span>
                                @endif
                                @if($row['bot']->trashed())
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">Deleted</span>
                                @endif
                                @if($multiWorkspace)
                                    <div class="conv-workspace">{{ $row['bot']->system->name ?? '' }}</div>
                                @endif
                            </td>
                            <td class="text-end figure-mono">{{ number_format($row['conversations']) }}</td>
                            <td class="text-end figure-mono">{{ number_format($row['visitor_messages']) }}</td>
                            <td class="text-end figure-mono">{{ number_format($row['replies']) }}</td>
                            <td class="text-end figure-mono">{{ number_format($row['tokens']) }}</td>
                            <td class="text-end figure-mono">{{ $pct($row['answer_rate']) }}</td>
                            <td class="text-end figure-mono">{{ number_format($row['flagged']) }}</td>
                            <td>
                                <div class="an-bar-track" data-tip="{{ $row['bot']->name }}: {{ round($share * 100) }}% of visitor messages" tabindex="0">
                                    <div class="an-bar-fill" style="width: {{ round($share * 100, 2) }}%"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- ---- Conversations ------------------------------------------------- --}}
<h2 class="an-section">Conversations</h2>
<div class="mb-4">
    @include('logs._list', [
        'listAction' => route('analytics.index'),
        // The bots and window above stay put while the table is searched.
        'listCarry' => array_filter([
            ...$botQuery,
            'range' => $range,
            'from' => $range === 'custom' ? $from->format('Y-m-d') : null,
            'to' => $range === 'custom' ? $to->copy()->subSecond()->format('Y-m-d') : null,
            'tz' => $zone,
        ]),
        'listShowPicker' => false,
        'listFiltered' => $search !== '',
        'listClearUrl' => route('analytics.index', array_filter([
            ...$botQuery, 'range' => $range,
            'from' => $range === 'custom' ? $from->format('Y-m-d') : null,
            'to' => $range === 'custom' ? $to->copy()->subSecond()->format('Y-m-d') : null,
            'tz' => $zone, 'sort' => $sort, 'dir' => $dir, 'per_page' => $perPage === 25 ? null : $perPage,
        ])),
        'listAnchor' => 'conversations',
        'listTitle' => 'Sessions active in this window',
    ])
</div>

@include('logs._transcript')
@endif

<div class="an-tip" id="anTip" role="tooltip" hidden></div>

@endsection

@push('scripts')
<style>
    .an-tabs { display: flex; gap: 0.25rem; border-bottom: 1px solid var(--border); }
    .an-tabs a { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.5rem 0.875rem; margin-bottom: -1px;
        font-size: 0.8125rem; color: var(--text-muted); text-decoration: none; border-bottom: 2px solid transparent; }
    .an-tabs a:hover { color: var(--text); }
    .an-tabs a.is-active { color: var(--accent); border-bottom-color: var(--accent); font-weight: 500; }
    .an-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; }
    .an-segment { display: inline-flex; border: 1px solid var(--border); border-radius: var(--r-sm); background: var(--surface); padding: 2px; gap: 2px; }
    .an-segment button { border: 0; background: none; color: var(--text-muted); font-size: 0.78125rem; padding: 0.25rem 0.625rem; border-radius: var(--r-xs); font-family: var(--font-mono); }
    .an-segment-sm button { font-family: var(--font-sans); font-size: 0.71875rem; padding: 0.1875rem 0.5rem; }
    .an-segment button:hover { color: var(--text); }
    .an-segment button.is-active { background: var(--accent-soft); color: var(--accent); }
    .an-custom { display: flex; align-items: center; gap: 0.5rem; }
    .an-custom[hidden] { display: none; }
    .an-custom input { width: auto; }
    .an-window { font-size: 0.71875rem; color: var(--text-muted); margin-left: auto; }
    .text-faint { color: var(--text-faint); }
    .an-metrics .metric-figure { font-size: 1.3125rem; }
    .an-metrics .metric:nth-child(5) { border-left: 0; }
    .an-metrics .metric:nth-child(n+5) { border-top: 1px solid var(--border); }
    .an-metrics .metric-note { display: flex; flex-wrap: wrap; gap: 0 0.375rem; }
    .an-delta { color: var(--text-muted); font-family: var(--font-mono); white-space: nowrap; }
    .an-delta i { margin-right: -1px; }
    .an-delta.is-good { color: var(--ok); }
    .an-delta.is-bad { color: var(--danger); }
    .an-footnote { font-size: 0.71875rem; color: var(--text-faint); margin: 0; }
    .an-section { font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.07em; color: var(--text-faint); font-weight: 600; margin: 0 0 0.625rem; }
    .an-subhead { font-size: 0.71875rem; color: var(--text-muted); font-weight: 500; margin-bottom: 0.5rem; }
    .an-none { font-size: 0.78125rem; color: var(--text-faint); }
    .an-chart-caption { font-size: 0.71875rem; color: var(--text-muted); margin-bottom: 0.625rem; }
    .an-plot { display: flex; gap: 0.5rem; height: 180px; }
    .an-axis { display: flex; flex-direction: column; justify-content: space-between; font-size: 0.625rem; color: var(--text-faint); text-align: right; min-width: 2rem; line-height: 1; }
    .an-columns { flex: 1; display: flex; align-items: flex-end; gap: 2px; min-width: 0; position: relative;
        border-bottom: 1px solid var(--border-strong);
        background-image: linear-gradient(var(--border) 1px, transparent 1px);
        background-size: 100% 50%; }
    .an-column { flex: 1; height: 100%; display: flex; align-items: flex-end; min-width: 0; cursor: default; outline: none; }
    .an-column-fill { width: 100%; background: var(--accent); border-radius: 4px 4px 0 0; min-height: 0; }
    .an-column-fill.is-zero { background: transparent; }
    .an-column:hover .an-column-fill, .an-column:focus-visible .an-column-fill { background: var(--accent-hover); }
    .an-column:hover, .an-column:focus-visible { background: var(--accent-soft); }
    .an-xlabels { display: flex; gap: 2px; margin-left: 2.5rem; margin-top: 0.3125rem; font-size: 0.625rem; color: var(--text-faint); }
    .an-xlabels span { flex: 1; min-width: 0; white-space: nowrap; overflow: visible; }
    .an-heat { display: flex; flex-direction: column; gap: 2px; }
    .an-heat-row { display: grid; grid-template-columns: 2.25rem repeat(24, 1fr); gap: 2px; align-items: center; }
    .an-heat-day { font-size: 0.65625rem; color: var(--text-muted); }
    .an-heat-cell { aspect-ratio: 1; border-radius: 2px; background: var(--surface-2); outline: none; min-width: 0; }
    .an-heat-cell.is-1 { background: color-mix(in srgb, var(--accent) 25%, var(--surface-2)); }
    .an-heat-cell.is-2 { background: color-mix(in srgb, var(--accent) 50%, var(--surface-2)); }
    .an-heat-cell.is-3 { background: color-mix(in srgb, var(--accent) 75%, var(--surface-2)); }
    .an-heat-cell.is-4 { background: var(--accent); }
    .an-heat-cell[tabindex]:hover, .an-heat-cell[tabindex]:focus-visible { box-shadow: 0 0 0 2px var(--text); }
    .an-heat-hours span { font-size: 0.625rem; color: var(--text-faint); white-space: nowrap; overflow: visible; }
    .an-heat-legend { display: flex; align-items: center; gap: 3px; margin-top: 0.75rem; font-size: 0.65625rem; color: var(--text-faint); }
    .an-heat-legend .an-heat-cell { width: 12px; }
    .an-heat-legend > span:first-child { margin-right: 0.25rem; }
    .an-heat-legend > span:nth-child(7) { margin-left: 0.25rem; }
    .an-bars { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
    .an-bar { outline: none; }
    .an-bar-text { display: flex; justify-content: space-between; gap: 0.75rem; font-size: 0.78125rem; margin-bottom: 0.1875rem; }
    .an-bar-label { color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; text-decoration: none; }
    a.an-bar-label:hover { text-decoration: underline; }
    .an-bar-label.figure-mono { font-size: 0.71875rem; }
    .an-bar-value { color: var(--text); font-size: 0.75rem; white-space: nowrap; }
    .an-bar-share { color: var(--text-faint); margin-left: 0.5rem; display: inline-block; min-width: 2.25rem; text-align: right; }
    .an-bar-track { height: 6px; background: var(--surface-2); border-radius: 3px; overflow: hidden; }
    .an-bar-fill { height: 100%; background: var(--accent); border-radius: 3px; }
    .an-bar.is-warn .an-bar-fill { background: var(--warn); }
    .an-bar.is-danger .an-bar-fill { background: var(--danger); }
    .an-bar:hover .an-bar-track, .an-bar:focus-visible .an-bar-track { background: var(--surface-3); }
    .an-split { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.375rem 1rem; font-size: 0.75rem; color: var(--text-muted); }
    .an-split .figure-mono { color: var(--text); font-size: 0.875rem; margin-right: 0.25rem; }
    .an-list { list-style: none; counter-reset: q; margin: 0; padding: 0; }
    .an-list li { counter-increment: q; display: flex; align-items: center; gap: 0.625rem; padding: 0.3125rem 0; font-size: 0.78125rem; }
    .an-list li + li { border-top: 1px solid var(--border); }
    .an-list li::before { content: counter(q); font-family: var(--font-mono); font-size: 0.6875rem; color: var(--text-faint); min-width: 1.125rem; }
    .an-list-text { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .an-tags { display: flex; flex-wrap: wrap; gap: 0.3125rem; }
    .an-tags .chip { text-decoration: none; color: var(--text-muted); }
    .an-tags .chip:hover { color: var(--text); }
    .an-table th { font-size: 0.6875rem; font-weight: 500; color: var(--text-faint); }
    .an-table td { font-size: 0.75rem; }
    .an-feed { list-style: none; margin: 0; padding: 0; }
    .an-feed li + li { border-top: 1px solid var(--border); }
    .an-feed-item { display: grid; grid-template-columns: 1fr auto; gap: 0.125rem 0.75rem; width: 100%; text-align: left; background: none; border: 0; padding: 0.625rem 1rem; color: var(--text); }
    .an-feed-item:hover, .an-feed-item:focus-visible { background: var(--surface-2); }
    .an-feed-main { font-size: 0.78125rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .an-feed-sub { grid-column: 1; font-size: 0.71875rem; color: var(--text-faint); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .an-feed-when { grid-column: 2; grid-row: 1; font-size: 0.6875rem; color: var(--text-faint); }
    /* Links back to the table land below the top bar, not under it. */
    #conversations { scroll-margin-top: calc(var(--bar-h) + 1rem); }
    .an-feed-sub b { font-weight: 500; color: var(--text-muted); }
    .an-bot-link { color: var(--text); text-decoration: none; }
    .an-bot-link:hover { text-decoration: underline; }
    .an-bot-table td { vertical-align: middle; }
    .an-bot-table th { white-space: nowrap; }
    .an-tip { position: fixed; z-index: 2000; pointer-events: none; background: var(--surface-3); color: var(--text); border: 1px solid var(--border-strong); border-radius: var(--r-sm); padding: 0.25rem 0.5rem; font-size: 0.71875rem; box-shadow: var(--shadow-pop); white-space: nowrap; }
    @media (max-width: 991.98px) {
        .an-metrics .metric:nth-child(odd) { border-left: 0; }
        .an-metrics .metric:nth-child(n+3) { border-top: 1px solid var(--border); }
        .an-window { margin-left: 0; width: 100%; }
    }
    @media (max-width: 575.98px) {
        .an-split { grid-template-columns: 1fr; }
        .an-plot { height: 140px; }
    }
</style>
<script>
    (function () {
        // The viewer's own time zone, so hours and days read as theirs. The
        // first visit is sent back once with it; after that it rides along.
        var tzInput = document.querySelector('[data-tz]');
        var browserZone = null;
        try { browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) {}
        if (browserZone && tzInput) {
            var params = new URLSearchParams(location.search);
            if (!params.get('tz') && browserZone !== tzInput.value) {
                params.set('tz', browserZone);
                location.replace(location.pathname + '?' + params.toString());
                return;
            }
            tzInput.value = params.get('tz') || browserZone;
        }

        var toggle = document.querySelector('[data-custom-toggle]');
        var custom = document.querySelector('[data-custom]');
        if (!toggle) return;
        toggle.addEventListener('click', function () {
            custom.hidden = !custom.hidden;
            if (!custom.hidden) custom.querySelector('input').focus();
        });

        // One measure on the activity chart at a time: each has its own scale.
        var tabs = document.querySelectorAll('[data-measure]');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (t) {
                    var on = t === tab;
                    t.classList.toggle('is-active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                document.querySelectorAll('[data-chart]').forEach(function (chart) {
                    chart.hidden = chart.dataset.chart !== tab.dataset.measure;
                });
            });
        });

        // One tooltip for every mark that carries data-tip, on hover and focus.
        var tip = document.getElementById('anTip');
        function show(target) {
            tip.textContent = target.dataset.tip;
            tip.hidden = false;
            var box = target.getBoundingClientRect();
            var left = Math.min(window.innerWidth - tip.offsetWidth - 8, Math.max(8, box.left + box.width / 2 - tip.offsetWidth / 2));
            var top = box.top - tip.offsetHeight - 6;
            tip.style.left = left + 'px';
            tip.style.top = (top < 8 ? box.bottom + 6 : top) + 'px';
        }
        function hide() { tip.hidden = true; }
        ['mouseover', 'focusin'].forEach(function (type) {
            document.addEventListener(type, function (event) {
                var target = event.target.closest && event.target.closest('[data-tip]');
                if (target) show(target); else if (type === 'mouseover') hide();
            });
        });
        document.addEventListener('focusout', hide);
        window.addEventListener('scroll', hide, true);
    })();
</script>
@endpush
