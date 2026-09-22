{{-- The Knowledge base tab of the Analytics page. $kb is KbAnalytics::report(). --}}
@php
    $k = $kb['kpis'];
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format($value, $decimals);
    $pct = fn ($value) => $value === null ? '—' : round($value * 100) . '%';
    $bytes = function ($value) {
        if ($value === null) {
            return '—';
        }
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? $value : number_format($value, 1)) . ' ' . $unit;
            }
            $value /= 1024;
        }
    };
    $toLocal = fn ($moment) => $moment ? \Illuminate\Support\Carbon::parse($moment)->setTimezone($zone) : null;

    $tiles = [
        ['Collections', $num($k['collections']), $num($k['sources']) . ' documents, ' . $num($k['ready']) . ' ready'],
        ['Chunks', $num($k['chunks']), $pct($k['chunks'] ? $k['embedded'] / $k['chunks'] : null) . ' embedded'],
        ['Indexed text', $num($k['chars']) . ' chars', '≈ ' . $num($k['chars'] / 4) . ' tokens'],
        ['Average chunk', $num($k['avg_chunk']) . ' chars', $num($kb['chunks_per_source'], 1) . ' chunks per document'],
        ['Hits in window', $num($k['hits']), 'Chunks cited in ' . $num($k['kb_answers']) . ' answers'],
        ['Hit rate', $pct($k['hit_rate']), $num($k['misses']) . ' searches found nothing'],
        ['Document coverage', $pct($k['coverage']), 'Ready documents cited at least once'],
        ['Citations per answer', $num($k['citations_per_answer'], 1), 'Chunks the model was shown'],
    ];

    $series = $kb['series'];
    $labelEvery = max(1, (int) ceil(count($series) / 10));
    $map = $kb['map'];
    $storage = $kb['storage'];
@endphp

@if($k['collections'] === 0)
    <div class="card">
        <div class="empty">
            <i class="bi bi-journal-text"></i>
            <h6>No knowledge base to report on</h6>
            <p>{{ $selectedBots || $console ? 'The picked bots answer from no collection.' : 'No workspace you can open has a collection yet.' }}</p>
            <a href="{{ route('kb.index') }}" class="btn btn-brand">Knowledge base</a>
        </div>
    </div>
@else

<div class="metrics an-metrics mb-2">
    @foreach($tiles as [$label, $figure, $note])
        <div class="metric">
            <span class="metric-label">{{ $label }}</span>
            <span class="metric-figure">{{ $figure }}</span>
            <div class="metric-note"><span>{{ $note }}</span></div>
        </div>
    @endforeach
</div>
<p class="an-footnote mb-4">
    Contents are every collection {{ $selectedBots || $console ? 'the picked bots answer from' : 'in the workspaces you can open' }}, as they are now.
    Hits count the answers of the picked bots in the window above. A hit is one chunk cited in one answer.
</p>

{{-- ---- Usage ------------------------------------------------------------ --}}
<h2 class="an-section">Usage</h2>
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-7">
        <div class="card h-100">
            <div class="card-header">Hits over time, by {{ $kb['hourly'] ? 'hour' : 'day' }}</div>
            <div class="p-3">
                @php
                    $values = array_column($series, 'hits');
                    $peak = max(1, $values ? max($values) : 0);
                @endphp
                <div class="an-chart-caption">
                    <span class="figure-mono">{{ number_format(array_sum($values)) }}</span> chunks cited,
                    <span class="figure-mono">{{ number_format(array_sum(array_column($series, 'misses'))) }}</span> searches with nothing found
                </div>
                <div class="an-plot">
                    <div class="an-axis figure-mono" aria-hidden="true">
                        <span>{{ number_format($peak) }}</span>
                        <span>{{ number_format($peak / 2, $peak < 2 ? 1 : 0) }}</span>
                        <span>0</span>
                    </div>
                    <div class="an-columns" role="img" aria-label="Chunks cited per {{ $kb['hourly'] ? 'hour' : 'day' }}, peaking at {{ number_format($peak) }}">
                        @foreach($series as $point)
                            <div class="an-column" tabindex="0" data-tip="{{ $point['title'] }}: {{ number_format($point['hits']) }} hits, {{ number_format($point['misses']) }} found nothing">
                                <div class="an-column-fill {{ $point['hits'] === 0 ? 'is-zero' : '' }}"
                                     style="height: {{ round($point['hits'] / $peak * 100, 2) }}%"></div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="an-xlabels figure-mono" aria-hidden="true">
                    @foreach($series as $i => $point)
                        <span>{{ $i % $labelEvery === 0 && $i + $labelEvery / 2 < count($series) ? $point['label'] : '' }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card h-100">
            <div class="card-header">Most hit documents</div>
            <div class="p-3">
                @if(empty($kb['top_sources']))
                    <div class="an-none mb-3">No document was cited in this window.</div>
                @else
                    <div class="mb-3">
                        @include('analytics._bars', [
                            'items' => collect($kb['top_sources'])->map(fn ($doc) => [
                                'label' => $doc['title'] . ($doc['collection'] ? ' · ' . $doc['collection'] : ''),
                                'value' => $doc['hits'],
                                'href' => $doc['exists'] ? route('kb.sources.show', $doc['id']) : null,
                            ])->all(),
                            'total' => $k['hits'],
                        ])
                    </div>
                @endif

                @if($kb['uncited']->isNotEmpty())
                    <div class="an-subhead">Never hit in this window · {{ number_format($kb['uncited_count']) }}</div>
                    <div class="an-tags">
                        @foreach($kb['uncited'] as $doc)
                            <a href="{{ route('kb.sources.show', $doc->id) }}" class="chip" title="{{ $doc->title }}">{{ \Illuminate\Support\Str::limit($doc->title, 40) }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ---- Semantic map ----------------------------------------------------- --}}
<h2 class="an-section">Semantic map</h2>
<div class="row g-3 mb-4">
    <div class="col-12 {{ $map['overlap'] ? 'col-xl-8' : '' }}">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <span>Chunks laid out by meaning</span>
                @if($map['points'])
                    <span class="an-footnote figure-mono">{{ number_format($map['sampled']) }} of {{ number_format($k['embedded']) }} embedded chunks</span>
                @endif
            </div>
            <div class="p-3">
                @if(!$map['points'])
                    <div class="an-none">No embedded chunks to draw yet. Chunks appear here once indexing has embedded them.</div>
                @else
                    <div class="an-chart-caption">
                        Each dot is a chunk. Chunks about the same thing sit close together; far-apart clusters are distinct topics.
                        Solid dots come from documents hit in this window.
                    </div>
                    <svg class="kb-map" viewBox="0 0 1000 520" role="img"
                         aria-label="Scatter of {{ count($map['points']) }} chunks by meaning, coloured by collection">
                        <rect x="0" y="0" width="1000" height="520" class="kb-map-bg" rx="6"/>
                        @foreach($map['points'] as $point)
                            <circle cx="{{ round(20 + $point['x'] * 960, 1) }}" cy="{{ round(500 - $point['y'] * 480, 1) }}" r="5"
                                    class="kb-dot s{{ $point['slot'] }} {{ $point['cited'] ? 'is-cited' : '' }}"
                                    data-tip="{{ \Illuminate\Support\Str::limit($point['title'], 60) }}{{ $point['heading'] !== '' ? ' › ' . \Illuminate\Support\Str::limit($point['heading'], 60) : '' }} · chunk {{ $point['ordinal'] + 1 }}{{ $point['cited'] ? ' · hit' : '' }}"/>
                        @endforeach
                    </svg>
                    <div class="kb-legend">
                        @foreach($map['legend'] as $entry)
                            <span><i class="kb-swatch s{{ $entry['slot'] }}"></i>{{ $entry['label'] }} <span class="text-faint figure-mono">{{ number_format($entry['count']) }}</span></span>
                        @endforeach
                        <span class="ms-auto"><i class="kb-swatch is-hollow"></i>Not hit</span>
                        <span><i class="kb-swatch is-solid"></i>Hit</span>
                    </div>
                    <div class="an-footnote mt-2">
                        The two leading principal components of the embeddings; they hold {{ $pct($map['variance']) }} of the spread, so distances are a sketch, not exact.
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if($map['overlap'])
        <div class="col-12 col-xl-4">
            <div class="card h-100">
                <div class="card-header">Collection overlap</div>
                <div class="p-3">
                    <div class="an-chart-caption">Cosine similarity of each collection's average chunk. Near 1 means they cover the same ground.</div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 an-table kb-overlap">
                            <thead>
                                <tr>
                                    <th></th>
                                    @foreach($map['overlap']['names'] as $name)
                                        <th class="text-end" title="{{ $name }}">{{ \Illuminate\Support\Str::limit($name, 10) }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($map['overlap']['matrix'] as $a => $row)
                                    <tr>
                                        <th title="{{ $map['overlap']['names'][$a] }}">{{ \Illuminate\Support\Str::limit($map['overlap']['names'][$a], 14) }}</th>
                                        @foreach($row as $b => $similarity)
                                            <td class="text-end figure-mono {{ $a === $b ? 'text-faint' : '' }}"
                                                style="{{ $a !== $b && $similarity !== null ? '--v:' . round(max(0, $similarity) * 45) . '%' : '' }}">
                                                {{ $similarity === null ? '—' : number_format($similarity, 2) }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

{{-- ---- Collections ------------------------------------------------------- --}}
<h2 class="an-section">Collections</h2>
<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0 an-bot-table">
            <thead>
                <tr>
                    <th style="min-width: 200px;">Collection</th>
                    <th class="text-end">Bots</th>
                    <th class="text-end">Documents</th>
                    <th class="text-end">Chunks</th>
                    <th class="text-end">Embedded</th>
                    <th class="text-end">Avg chunk</th>
                    <th class="text-end">Text</th>
                    <th class="text-end">Hits</th>
                    <th>Last indexed</th>
                </tr>
            </thead>
            <tbody>
                @foreach($kb['collections'] as $row)
                    <tr>
                        <td>
                            <a href="{{ route('kb.show', $row['collection']->id) }}" class="fw-semibold an-bot-link">{{ $row['collection']->name }}</a>
                            @if($multiWorkspace)
                                <div class="conv-workspace">{{ $row['collection']->system->name ?? '' }}</div>
                            @endif
                        </td>
                        <td class="text-end figure-mono">{{ number_format($row['collection']->bots_count) }}</td>
                        <td class="text-end figure-mono">
                            {{ number_format($row['sources']) }}
                            @if($row['failed'])<span class="badge bg-danger-subtle text-danger-emphasis ms-1" title="Failed to index">{{ $row['failed'] }} failed</span>@endif
                            @if($row['pending'])<span class="badge bg-warning-subtle text-warning-emphasis ms-1" title="Waiting or indexing">{{ $row['pending'] }} pending</span>@endif
                        </td>
                        <td class="text-end figure-mono">{{ number_format($row['chunks']) }}</td>
                        <td class="text-end figure-mono">{{ $pct($row['chunks'] ? $row['embedded'] / $row['chunks'] : null) }}</td>
                        <td class="text-end figure-mono">{{ $num($row['avg_chunk']) }}</td>
                        <td class="text-end figure-mono">{{ $bytes($row['chars']) }}</td>
                        <td class="text-end figure-mono">{{ number_format($row['hits']) }}</td>
                        <td class="figure-mono text-muted">{{ $toLocal($row['last_indexed'])?->format('M j, H:i') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- ---- Chunking and embedding ------------------------------------------ --}}
<h2 class="an-section">Chunking and embedding</h2>
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Chunk sizes</div>
            <div class="p-3">
                <div class="an-split mb-3">
                    <div><span class="figure-mono">{{ $num($kb['size_min']) }}</span> shortest</div>
                    <div><span class="figure-mono">{{ $num($kb['size_median']) }}</span> median</div>
                    <div><span class="figure-mono">{{ $num($kb['size_p95']) }}</span> p95</div>
                    <div><span class="figure-mono">{{ $num($kb['size_max']) }}</span> longest</div>
                </div>
                @if($k['chunks'] === 0)
                    <div class="an-none">No chunks yet.</div>
                @else
                    <div class="an-subhead">Characters per chunk</div>
                    @include('analytics._bars', [
                        'items' => collect(\App\Services\KbAnalytics::SIZE_BANDS)
                            ->map(fn ($band, $i) => ['label' => $band[1], 'value' => $kb['size_bands'][$i]])->all(),
                        'total' => $k['chunks'],
                    ])
                    <div class="an-footnote mt-2">
                        {{ $pct($kb['with_heading'] / $k['chunks']) }} of chunks carry a heading path from structure-aware chunking.
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Settings and models</div>
            <div class="p-3">
                <div class="an-split mb-3">
                    <div><span class="figure-mono">{{ number_format($kb['settings']['chunk_size']) }}</span> target chunk size</div>
                    <div><span class="figure-mono">{{ number_format($kb['settings']['chunk_overlap']) }}</span> overlap</div>
                    <div><span class="figure-mono">{{ $kb['settings']['embedding_dimensions'] }}</span> dimensions</div>
                    <div><span class="figure-mono">{{ number_format($kb['settings']['context_char_budget']) }}</span> context budget</div>
                </div>
                <div class="an-subhead">Chunks by embedding model · current is <span class="figure-mono">{{ $kb['settings']['embedding_model'] }}</span></div>
                @if(empty($kb['models']))
                    <div class="an-none">No chunks yet.</div>
                @else
                    @include('analytics._bars', [
                        'items' => collect($kb['models'])->map(fn ($count, $model) => [
                            'label' => $model, 'value' => $count, 'mono' => true,
                            'tone' => $model === $kb['settings']['embedding_model'] ? null : 'warn',
                        ])->values()->all(),
                        'total' => $k['chunks'],
                    ])
                @endif
                @if($kb['stale'] > 0)
                    <div class="an-footnote mt-2">
                        <i class="bi bi-exclamation-triangle" style="color: var(--warn);"></i>
                        {{ number_format($kb['stale']) }} {{ \Illuminate\Support\Str::plural('chunk', $kb['stale']) }} were embedded by another model, or not at all. Vector search skips them until their documents are re-indexed.
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ---- Documents and storage -------------------------------------------- --}}
<h2 class="an-section">Documents and storage</h2>
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header">Documents</div>
            <div class="p-3">
                <div class="an-subhead">By type</div>
                <div class="mb-3">
                    @include('analytics._bars', [
                        'items' => collect($kb['types'])->map(fn ($count, $type) => [
                            'label' => ['text' => 'Text', 'file' => 'Uploaded file', 'qa' => 'Question and answer', 'website' => 'Website'][$type] ?? ucfirst($type),
                            'value' => $count,
                        ])->values()->all(),
                        'total' => $k['sources'],
                    ])
                </div>
                <div class="an-subhead">By status</div>
                @include('analytics._bars', [
                    'items' => collect($kb['statuses'])->map(fn ($count, $status) => [
                        'label' => ucfirst($status), 'value' => $count,
                        'tone' => $status === 'failed' ? 'danger' : ($status === 'ready' ? null : 'warn'),
                    ])->values()->all(),
                    'total' => $k['sources'],
                ])
                <div class="an-footnote mt-2">{{ $bytes($kb['file_bytes']) }} of uploaded files.</div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header">Needs attention</div>
            @if($kb['attention']->isEmpty())
                <div class="p-3"><div class="an-none">Every document is indexed and has chunks.</div></div>
            @else
                <ul class="an-feed">
                    @foreach($kb['attention'] as $doc)
                        <li>
                            <a href="{{ route('kb.sources.show', $doc->id) }}" class="an-feed-item text-decoration-none">
                                <span class="an-feed-main">{{ $doc->title }}</span>
                                <span class="an-feed-sub">
                                    <span class="badge {{ $doc->status === 'failed' ? 'bg-danger-subtle text-danger-emphasis' : 'bg-warning-subtle text-warning-emphasis' }}">{{ $doc->status === 'ready' ? 'No chunks' : ucfirst($doc->status) }}</span>
                                    {{ \Illuminate\Support\Str::limit($doc->error_message ?? '', 100) }}
                                </span>
                                <span class="an-feed-when figure-mono">{{ $toLocal($doc->updated_at)?->format('M j, H:i') }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header">Vector database</div>
            <div class="p-3">
                <table class="table table-sm mb-0 an-table">
                    <tbody>
                        <tr><td class="text-muted">Database</td><td class="figure-mono text-end">{{ $storage['driver'] }}</td></tr>
                        <tr><td class="text-muted">Vectors stored as</td><td class="figure-mono text-end">{{ $storage['vector_type'] }}</td></tr>
                        <tr><td class="text-muted">Search</td><td class="text-end">{{ $storage['search'] }}</td></tr>
                        <tr><td class="text-muted">Chunk rows, all workspaces</td><td class="figure-mono text-end">{{ number_format($storage['rows']) }}</td></tr>
                        <tr><td class="text-muted">Text, these collections</td><td class="figure-mono text-end">{{ $bytes($storage['text_bytes']) }}</td></tr>
                        <tr><td class="text-muted">Vectors, these collections</td><td class="figure-mono text-end">≈ {{ $bytes($storage['vector_bytes']) }}</td></tr>
                        @if($storage['table_bytes'] !== null)
                            <tr><td class="text-muted">Table on disk, with indexes</td><td class="figure-mono text-end">{{ $bytes($storage['table_bytes']) }}</td></tr>
                        @endif
                    </tbody>
                </table>
                @if($storage['indexes'])
                    <div class="an-subhead mt-3">Indexes</div>
                    <div class="an-tags">
                        @foreach($storage['indexes'] as $index)
                            <span class="chip figure-mono">{{ $index }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@endif

@push('scripts')
<style>
    .kb-map { width: 100%; height: auto; display: block; }
    .kb-map-bg { fill: var(--surface-2); }
    /* Categorical slots, validated on this console's light and dark surfaces. */
    .kb-map, .kb-legend { --s0: #3987e5; --s1: #d95926; --s2: #199e70; --s3: #c98500; --s4: #d55181; --s5: #008300; --s6: #7d7d78; }
    [data-theme="light"] .kb-map, [data-theme="light"] .kb-legend { --s0: #2a78d6; --s1: #eb6834; --s2: #1baf7a; --s3: #eda100; --s4: #e87ba4; --s5: #008300; --s6: #8f8f8a; }
    .kb-dot { fill: transparent; stroke-width: 1.5; stroke: var(--c); opacity: 0.75; }
    .kb-dot.is-cited { fill: var(--c); stroke: var(--surface-2); stroke-width: 1; opacity: 1; }
    .kb-dot:hover { stroke: var(--text); stroke-width: 2; opacity: 1; }
    @for($s = 0; $s <= 6; $s++)
        .kb-dot.s{{ $s }}, .kb-swatch.s{{ $s }} { --c: var(--s{{ $s }}); }
    @endfor
    .kb-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 0.375rem 1rem; margin-top: 0.75rem; font-size: 0.75rem; color: var(--text-muted); }
    .kb-swatch { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: var(--c); margin-right: 0.375rem; vertical-align: -1px; }
    .kb-swatch.is-hollow { background: none; border: 1.5px solid var(--text-muted); }
    .kb-swatch.is-solid { background: var(--text-muted); }
    .kb-overlap td { background: color-mix(in srgb, var(--accent) var(--v, 0%), transparent); }
    .kb-overlap th { white-space: nowrap; }
</style>
@endpush
