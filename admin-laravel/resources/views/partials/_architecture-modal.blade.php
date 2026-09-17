{{-- The architecture, as the install runs it today and as it is planned for
     the two DGX Sparks. Drawn in HTML rather than shipped as an image, so it
     follows the theme, and so the Models tab can read the live settings: a
     picture of which model does which job is wrong the day someone changes
     one. The long form lives in docs/architecture.md and
     docs/model-stack-review.md.

     Every signed-in user can open it from the top bar. The live column is
     only drawn when $architectureLive is set, which the layout does for a
     super admin on an Admin settings page; everyone else sees the plan. --}}
@php
    $architectureLive = $architectureLive ?? false;
    $settings = $architectureLive ? $settings : [];
    $modelRoles = $architectureLive ? $modelRoles : \App\Http\Controllers\AdminSettingsController::MODEL_ROLES;
    $providerNames = $architectureLive ? collect($providers)->pluck('name', 'id') : collect();

    // What each job runs on right now, from the saved settings.
    $liveJob = function (string $providerKey, string $modelKey, string $blank) use ($settings, $providerNames) {
        $providerId = (string) ($settings[$providerKey] ?? '');
        $model = (string) ($settings[$modelKey] ?? '');

        if ($providerId !== '' && $model !== '') {
            return ['set' => true, 'where' => $providerNames[$providerId] ?? 'Missing provider', 'model' => $model];
        }

        return ['set' => false, 'where' => $blank, 'model' => ''];
    };

    $embeddingProvider = (string) ($settings['embedding_provider_id'] ?? '');
    $jobs = [
        [
            'label' => 'Answer', 'icon' => 'bi-chat-square-text',
            'now' => ['set' => true, 'where' => "Each bot's own provider", 'model' => 'set per bot'],
            'plan' => 'Qwen3.5-122B-A10B, NVFP4', 'node' => 'A',
        ],
        [
            'label' => 'Embedding', 'icon' => 'bi-vector-pen',
            'now' => [
                'set' => $embeddingProvider !== '',
                'where' => $embeddingProvider !== '' ? ($providerNames[$embeddingProvider] ?? 'Missing provider') : 'Ollama on this machine',
                'model' => ($settings['embedding_model'] ?? '') . ' · ' . ($settings['embedding_dimensions'] ?? '') . 'd',
            ],
            'plan' => 'Qwen3-Embedding-4B, 1024d', 'node' => 'B',
        ],
    ];

    $plans = [
        'intent' => ['Qwen3.5-35B-A3B', 'B'],
        'sql' => ['Qwen3-Coder-30B-A3B', 'B'],
        'rerank' => ['Qwen3-Reranker-4B', 'B'],
        'guard' => ['Qwen3Guard-Gen-4B', 'B'],
        'vision' => ['Qwen3-VL-8B', 'B'],
    ];

    foreach ($modelRoles as $role => $meta) {
        $jobs[] = [
            'label' => $meta['label'], 'icon' => $meta['icon'],
            'now' => $liveJob("{$role}_model_provider_id", "{$role}_model_name", 'Default: ' . $meta['blank']),
            'plan' => $plans[$role][0] ?? '', 'node' => $plans[$role][1] ?? '',
        ];
    }

    $nodes = [
        'A' => ['title' => 'Node A', 'job' => 'Conversation', 'parts' => [
            ['System', 8, 'sys'], ['Main model, ~120B MoE', 70, 'main'], ['KV cache, concurrent chats', 40, 'cache'],
        ]],
        'B' => ['title' => 'Node B', 'job' => 'Every other job', 'parts' => [
            ['System', 8, 'sys'], ['Intent + SQL, ~30B-A3B MoE', 36, 'main'], ['Embedding', 16, 'job'],
            ['Reranker', 8, 'job'], ['Guard', 8, 'job'], ['Vision OCR', 10, 'job'], ['Image, deferred', 30, 'later'],
        ]],
    ];
@endphp

<div class="modal fade" id="architectureModal" tabindex="-1" aria-labelledby="architectureModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header flex-wrap gap-2">
                <div class="me-auto">
                    <h5 class="modal-title" id="architectureModalTitle">Architecture</h5>
                    <div class="text-muted small">How a message and a document travel through the system, and where each model runs.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <ul class="nav nav-tabs w-100 mt-1" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#arch-system" type="button" role="tab">System</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#arch-chat" type="button" role="tab">Answering</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#arch-ingest" type="button" role="tab">Indexing</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#arch-models" type="button" role="tab">Models</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#arch-sparks" type="button" role="tab">DGX Sparks</button>
                    </li>
                </ul>
            </div>

            <div class="modal-body tab-content">

                {{-- System ------------------------------------------------ --}}
                <div class="tab-pane fade show active" id="arch-system" role="tabpanel">
                    <div class="arch-system">
                        <div class="arch-node arch-edge" style="grid-area: site;">
                            <i class="bi bi-window"></i>
                            <strong>Customer website</strong>
                            <span>One &lt;script&gt; tag loads the chat widget, a Shadow DOM bubble with no dependencies.</span>
                        </div>
                        <div class="arch-link arch-link-down" style="grid-area: l1;"><span>SSE token stream</span></div>

                        <div class="arch-node arch-core" style="grid-area: engine;">
                            <i class="bi bi-lightning-charge"></i>
                            <strong>api-engine <code>:8000</code></strong>
                            <span>FastAPI. Streams answers, runs the guard and intent, consults sources in the bot's order, chunks, embeds, retrieves, reranks.</span>
                        </div>
                        <div class="arch-link" style="grid-area: l2;"><span>admin token: index, search, list models</span><span>portal token: run a SQL query</span></div>

                        <div class="arch-node arch-core" style="grid-area: portal;">
                            <i class="bi bi-window-sidebar"></i>
                            <strong>admin-laravel <code>:8080</code></strong>
                            <span>Laravel 13. Workspaces, bots, knowledge base, settings. Owns every table's schema and runs queries on customer databases.</span>
                        </div>

                        <div class="arch-link" style="grid-area: l3;"><span>OpenAI-compatible HTTP</span></div>
                        <div class="arch-link arch-link-down" style="grid-area: l4;"><span>shared tables</span></div>
                        <div class="arch-link arch-link-down" style="grid-area: l5;"><span>read-only SQL on customer data</span></div>

                        <div class="arch-node arch-model" style="grid-area: models;">
                            <i class="bi bi-cpu"></i>
                            <strong>Model providers</strong>
                            <span>Ollama, vLLM or llama.cpp today on this machine; two DGX Sparks later. Saved once under Providers.</span>
                        </div>
                        <div class="arch-node arch-store" style="grid-area: db;">
                            <i class="bi bi-database"></i>
                            <strong>PostgreSQL + pgvector</strong>
                            <span>One database for both services. HNSW vectors, tsvector keywords. SQLite fallback behind the same interface.</span>
                        </div>
                        <div class="arch-node arch-edge" style="grid-area: customer;">
                            <i class="bi bi-server"></i>
                            <strong>Customer databases</strong>
                            <span>MySQL, PostgreSQL, SQL Server, SQLite. Read through the portal, validated twice.</span>
                        </div>
                    </div>

                    <div class="arch-principles">
                        <div><i class="bi bi-diagram-2"></i><span><strong>Two services, one database.</strong> The only contract between them is the table schema; they share no code.</span></div>
                        <div><i class="bi bi-sort-numeric-down"></i><span><strong>The operator picks the source.</strong> No model routes between knowledge base, database and web; the bot's order does.</span></div>
                        <div><i class="bi bi-arrow-return-left"></i><span><strong>Blank falls back.</strong> A model job with no provider does what the system did before the job existed.</span></div>
                        <div><i class="bi bi-shield-check"></i><span><strong>Failure is quiet for visitors.</strong> A guard, source or model that is down lets the conversation carry on, and the log says why.</span></div>
                    </div>
                </div>

                {{-- Answering --------------------------------------------- --}}
                <div class="tab-pane fade" id="arch-chat" role="tabpanel">
                    <ol class="arch-steps">
                        <li>
                            <div class="arch-step-title">Message arrives</div>
                            <div class="arch-step-body">The widget posts the message with recent history. An origin not on the workspace's allowlist gets 403.</div>
                        </li>
                        <li>
                            <div class="arch-step-title">Read it, in parallel</div>
                            <div class="arch-row">
                                <div class="arch-chip-card"><i class="bi bi-shield-check"></i><strong>Input guard</strong><span>Blocks the categories and topics set under Guard. Unsafe gets the bot's refusal and nothing else runs.</span></div>
                                <div class="arch-chip-card"><i class="bi bi-signpost-split"></i><strong>Intent</strong><span><em>chat</em> or <em>facts</em>, and the follow-up rewritten as a question that stands on its own.</span></div>
                                <div class="arch-chip-card"><i class="bi bi-chat-dots"></i><strong>Greeting gate</strong><span>Word rules, no model. A greeting consults no source.</span></div>
                            </div>
                        </li>
                        <li>
                            <div class="arch-step-title">Sources, in the bot's order, until one answers</div>
                            <div class="arch-row arch-row-cascade">
                                <div class="arch-chip-card"><i class="bi bi-journal-text"></i><strong>Knowledge base</strong><span>Embed + keyword search, fused by rank (RRF), reranked, kept only above the floor.</span></div>
                                <div class="arch-then" aria-hidden="true"><i class="bi bi-chevron-right"></i><span>miss</span></div>
                                <div class="arch-chip-card"><i class="bi bi-database"></i><strong>Database</strong><span>SQL model writes a SELECT or declines; validated in the engine and again in the portal.</span></div>
                                <div class="arch-then" aria-hidden="true"><i class="bi bi-chevron-right"></i><span>miss</span></div>
                                <div class="arch-chip-card"><i class="bi bi-globe2"></i><strong>Web</strong><span>DuckDuckGo, Tavily or Brave.</span></div>
                            </div>
                        </li>
                        <li>
                            <div class="arch-step-title">Answer</div>
                            <div class="arch-step-body">The bot's main model reads its prompt and the context, and streams over SSE. A sources event goes first, so the widget can show where the answer came from.</div>
                        </li>
                        <li>
                            <div class="arch-step-title">Check and record</div>
                            <div class="arch-step-body">The output guard checks the finished exchange and flags an unsafe answer for review. The message stores its intent, SQL, guard flag and the model behind each job.</div>
                        </li>
                    </ol>
                </div>

                {{-- Indexing ---------------------------------------------- --}}
                <div class="tab-pane fade" id="arch-ingest" role="tabpanel">
                    <div class="arch-pipeline">
                        <div class="arch-chip-card"><i class="bi bi-upload"></i><strong>Source</strong><span>Upload, pasted text or a question and answer.</span></div>
                        <div class="arch-then" aria-hidden="true"><i class="bi bi-chevron-right"></i></div>
                        <div class="arch-chip-card"><i class="bi bi-file-earmark-text"></i><strong>Read</strong><span>markitdown for a text layer; the Vision model for scans and images, or a bot's main model when Vision is blank.</span></div>
                        <div class="arch-then" aria-hidden="true"><i class="bi bi-chevron-right"></i></div>
                        <div class="arch-chip-card"><i class="bi bi-list-nested"></i><strong>Blocks</strong><span>Headings, tables, lists and code kept whole.</span></div>
                        <div class="arch-then" aria-hidden="true"><i class="bi bi-chevron-right"></i></div>
                        <div class="arch-chip-card"><i class="bi bi-scissors"></i><strong>Chunks</strong><span>A new section starts a new chunk. Size is a ceiling. Section and About lines on top.</span></div>
                        <div class="arch-then" aria-hidden="true"><i class="bi bi-chevron-right"></i></div>
                        <div class="arch-chip-card"><i class="bi bi-vector-pen"></i><strong>Embed</strong><span>The Embedding model, normalised vectors.</span></div>
                        <div class="arch-then" aria-hidden="true"><i class="bi bi-chevron-right"></i></div>
                        <div class="arch-chip-card"><i class="bi bi-database"></i><strong>Store</strong><span><code>kb_chunks</code>: content, vector, keywords.</span></div>
                    </div>
                    <div class="settings-note mt-3">
                        <i class="bi bi-info-circle"></i>
                        <span>A question and answer pair is one chunk, never split. Changing the embedding model or dimensions needs every source indexed again, from Rebuild the index.</span>
                    </div>
                </div>

                {{-- Models ------------------------------------------------ --}}
                <div class="tab-pane fade" id="arch-models" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-2 arch-table">
                            <thead>
                                <tr>
                                    <th>Job</th>
                                    @if($architectureLive)
                                        <th>Now, from settings</th>
                                    @endif
                                    <th>On the DGX Sparks</th>
                                    <th class="text-center">Node</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($jobs as $job)
                                    <tr>
                                        <td class="text-nowrap"><i class="bi {{ $job['icon'] }} me-2 text-muted"></i>{{ $job['label'] }}</td>
                                        @if($architectureLive)
                                            <td>
                                                <span class="d-inline-flex align-items-center gap-2">
                                                    <span @class(['state-dot', 'is-live' => $job['now']['set']])></span>
                                                    <span>{{ $job['now']['where'] }}</span>
                                                    @if($job['now']['model'] !== '')
                                                        <code class="small">{{ $job['now']['model'] }}</code>
                                                    @endif
                                                </span>
                                            </td>
                                        @endif
                                        <td class="font-monospace small">{{ $job['plan'] }}</td>
                                        <td class="text-center"><span class="chip">{{ $job['node'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="settings-note">
                        <i class="bi bi-info-circle"></i>
                        @if($architectureLive)
                            <span>A green dot is a job with its own provider. The plan column is the direction agreed for late 2026, to be confirmed against the golden question set before any model is chosen. Change what runs now under <a href="{{ route('admin.settings', 'models') }}">Models</a>.</span>
                        @else
                            <span>The plan column is the direction agreed for late 2026, to be confirmed against a set of test questions before any model is chosen.</span>
                        @endif
                    </div>
                </div>

                {{-- DGX Sparks -------------------------------------------- --}}
                <div class="tab-pane fade" id="arch-sparks" role="tabpanel">
                    <p class="small text-muted">
                        Two independent servers, not one cluster: nothing planned needs more than one node's 128 GB, and
                        splitting a model across the link would slow every token. Each job is its own vLLM process with an
                        explicit memory share, and each is a Provider here.
                    </p>
                    <div class="row g-3">
                        @foreach($nodes as $key => $node)
                            @php($used = collect($node['parts'])->sum(fn ($part) => $part[1]))
                            <div class="col-lg-6">
                                <div class="arch-node-card">
                                    <div class="d-flex align-items-baseline justify-content-between gap-2 mb-2">
                                        <span><strong>{{ $node['title'] }}</strong> <span class="text-muted small">· {{ $node['job'] }}</span></span>
                                        <span class="small text-muted font-monospace">{{ $used }} / 128 GB</span>
                                    </div>
                                    <div class="arch-memory" role="img" aria-label="{{ $node['title'] }} memory plan">
                                        @foreach($node['parts'] as [$label, $gb, $kind])
                                            <span class="arch-mem-{{ $kind }}" style="width: {{ round($gb / 128 * 100, 2) }}%;" title="{{ $label }}: {{ $gb }} GB"></span>
                                        @endforeach
                                    </div>
                                    <ul class="arch-legend">
                                        @foreach($node['parts'] as [$label, $gb, $kind])
                                            <li><span class="arch-swatch arch-mem-{{ $kind }}"></span>{{ $label }}<span class="ms-auto font-monospace">{{ $gb }} GB</span></li>
                                        @endforeach
                                        <li><span class="arch-swatch arch-mem-free"></span>Headroom<span class="ms-auto font-monospace">{{ 128 - $used }} GB</span></li>
                                    </ul>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="settings-note mt-3">
                        <i class="bi bi-info-circle"></i>
                        <span>Figures are plan-grade estimates from <code>docs/model-stack-review.md</code>, to be replaced with measurements when the hardware arrives. Bring-up steps are in <code>docs/sparks-setup.md</code>.</span>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
