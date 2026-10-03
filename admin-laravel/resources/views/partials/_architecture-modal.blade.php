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
        // The three newer jobs are short, structured calls the intent model
        // already handles well, so they share its process on node B.
        'expand' => ['Qwen3.5-35B-A3B (shared with Intent)', 'B'],
        'verify' => ['Qwen3.5-35B-A3B (shared with Intent)', 'B'],
        'context' => ['Qwen3.5-35B-A3B, batch at night', 'B'],
    ];

    foreach ($modelRoles as $role => $meta) {
        $jobs[] = [
            'label' => $meta['label'], 'icon' => $meta['icon'],
            'now' => $liveJob("{$role}_model_provider_id", "{$role}_model_name", 'Default: ' . $meta['blank']),
            'plan' => $plans[$role][0] ?? '', 'node' => $plans[$role][1] ?? '',
        ];
    }

    // Every retrieval and security technique worth naming, and where this
    // install stands on it. The question the Techniques tab answers is "do we
    // already do X", so each row says how, and where it is switched.
    $statuses = [
        'on' => ['Built, on', 'bg-success-subtle text-success-emphasis'],
        'choice' => ['Built, a choice', 'bg-primary-subtle text-primary-emphasis'],
        'model' => ['Built, needs its model', 'bg-info-subtle text-info-emphasis'],
        'sparks' => ['On the Sparks', 'bg-warning-subtle text-warning-emphasis'],
        'not' => ['Not planned yet', 'bg-secondary-subtle text-secondary-emphasis'],
    ];
    $techniques = [
        'Retrieval' => [
            ['Dense vector search', 'on', 'pgvector HNSW, cosine. Every bot with a knowledge base.'],
            ['Sparse keyword search, BM25', 'on', 'Okapi BM25 in the engine, Malay and English alike. Postgres full text instead under Chunking and search.'],
            ['Hybrid search', 'on', 'Both branches on every question. Behaviour, Search mode.'],
            ['Reciprocal Rank Fusion', 'on', 'Merged by rank, weighted. Behaviour, Keyword weight.'],
            ['Query rewriting', 'on', 'Follow-ups rewritten to stand alone by the Intent model. Behaviour, Understand follow-up questions.'],
            ['Multi-query expansion', 'choice', 'Rephrasings searched too. Behaviour, Query expansion.'],
            ['HyDE', 'choice', 'A hypothetical answer searched by meaning. Behaviour, Query expansion.'],
            ['Cross-encoder reranking', 'model', 'Needs a reranker on /v1/rerank (bge-reranker-v2-m3 now). A chat model cannot stand in.'],
            ['Small-to-big retrieval', 'choice', 'Neighbouring passages from the same section. Behaviour, Neighbouring passages.'],
            ['Contextual retrieval', 'choice', 'A model-written context line per chunk. Chunking and search, Contextual chunks.'],
            ['Structure-aware chunking', 'on', 'Headings, tables and code decide where a chunk ends.'],
            ['Semantic cache', 'choice', 'Reused answers for reworded questions. Behaviour, Answer cache.'],
            ['Grounding check (self-RAG)', 'choice', 'Unsupported claims flagged. Behaviour, Check answers against their sources.'],
            ['Text-to-SQL', 'on', 'The database source, read-only and validated twice.'],
            ['Learned sparse (SPLADE, BGE-M3)', 'sparks', 'Needs a model that outputs sparse vectors. BM25 covers exact terms until then.'],
            ['Agentic retrieval', 'sparks', 'The model deciding when to search. Tool calling is unreliable at 4B; revisit with the 120B model.'],
            ['GraphRAG', 'not', 'Pays off for questions that hop across linked entities. Support material is mostly policy and FAQ, and the database source answers relational questions. Revisit if the test set shows multi-hop failures.'],
        ],
        'Security' => [
            ['History from the engine\'s records', 'on', 'A visitor cannot invent earlier turns. Security, Conversation history.'],
            ['Injection shield, messages', 'on', 'Patterns and hidden characters, no model. Security, Injection shield.'],
            ['Injection shield, retrieved material', 'on', 'Passages written as instructions are dropped. Security.'],
            ['Spotlighting', 'on', 'All material wrapped and marked as data, never instructions. Always.'],
            ['Prompt leak canary', 'on', 'A secret marker stops a reply reciting its prompt. Security, Prompt leak guard.'],
            ['Guard model, in and out', 'choice', 'Categories and topics. Per bot under Behaviour; rules under Guard.'],
            ['Origin allowlist, message cap, read-only SQL', 'on', 'Per workspace, 4,000 characters, SELECT only.'],
            ['Voice in and out', 'choice', 'Four voices, English and Malay, female and male: the browser, Azure Speech or a speech server. Behaviour, Voice; Admin settings, Voice.'],
            ['Web search keys per workspace', 'choice', 'A workspace brings its own Tavily or Brave key, never shown again once saved. Behaviour, Web search.'],
        ],
    ];

    $nodes = [
        'A' => ['title' => 'Node A', 'job' => 'Conversation', 'parts' => [
            ['System', 8, 'sys'], ['Main model, ~120B MoE', 70, 'main'], ['KV cache, concurrent chats', 40, 'cache'],
        ]],
        'B' => ['title' => 'Node B', 'job' => 'Every other job', 'parts' => [
            ['System', 8, 'sys'], ['Intent, SQL, expansion, checks · ~30B-A3B MoE', 36, 'main'], ['Embedding', 16, 'job'],
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
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#arch-techniques" type="button" role="tab">Techniques</button>
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
                            <span>FastAPI. Streams answers, runs the security layers, guard and intent, consults sources combined or in the bot's order, chunks, embeds, retrieves (vectors + BM25), reranks, caches and checks answers.</span>
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
                            <span>One database for both services. HNSW vectors, chunk text for BM25, the answer cache and every transcript. SQLite fallback behind the same interface.</span>
                        </div>
                        <div class="arch-node arch-edge" style="grid-area: customer;">
                            <i class="bi bi-server"></i>
                            <strong>Customer databases</strong>
                            <span>MySQL, PostgreSQL, SQL Server, SQLite. Read through the portal, validated twice.</span>
                        </div>
                    </div>

                    <div class="arch-principles">
                        <div><i class="bi bi-diagram-2"></i><span><strong>Two services, one database.</strong> The only contract between them is the table schema; they share no code.</span></div>
                        <div><i class="bi bi-sort-numeric-down"></i><span><strong>The operator picks the source.</strong> No model routes between them. By default the knowledge base and database answer together; a bot can use a fixed order instead.</span></div>
                        <div><i class="bi bi-arrow-return-left"></i><span><strong>Blank falls back.</strong> A model job with no provider does what the system did before the job existed.</span></div>
                        <div><i class="bi bi-shield-lock"></i><span><strong>Defence in layers.</strong> Patterns, markers and records stop prompt injection without a model; the guard model and answer check add judgement on top.</span></div>
                        <div><i class="bi bi-toggles"></i><span><strong>Competing techniques are a choice.</strong> BM25 or Postgres search, HyDE or multi-query, cache or not: each is a setting, off or neutral until switched on.</span></div>
                        <div><i class="bi bi-shield-check"></i><span><strong>Failure is quiet for visitors.</strong> A guard, source or model that is down lets the conversation carry on, and the log says why.</span></div>
                    </div>
                </div>

                {{-- Answering --------------------------------------------- --}}
                <div class="tab-pane fade" id="arch-chat" role="tabpanel">
                    <ol class="arch-steps">
                        <li>
                            <div class="arch-step-title">Message arrives</div>
                            <div class="arch-step-body">An origin not on the workspace's allowlist gets 403, and a message over 4,000 characters is refused. The conversation so far is read from the engine's own records, not from the browser, so a visitor cannot invent earlier turns.</div>
                        </li>
                        <li>
                            <div class="arch-step-title">Screen it, without a model</div>
                            <div class="arch-row">
                                <div class="arch-chip-card"><i class="bi bi-shield-exclamation"></i><strong>Injection shield</strong><span>"Ignore your instructions", fake system tags, hidden characters. Blocked, flagged or let through, as set under Security. Instant.</span></div>
                                <div class="arch-chip-card"><i class="bi bi-chat-dots"></i><strong>Greeting gate</strong><span>Word rules, no model. A greeting consults no source.</span></div>
                            </div>
                        </li>
                        <li>
                            <div class="arch-step-title">Read it, in parallel</div>
                            <div class="arch-row">
                                <div class="arch-chip-card"><i class="bi bi-shield-check"></i><strong>Input guard</strong><span>Blocks the categories and topics set under Guard. Unsafe gets the bot's refusal and nothing else runs.</span></div>
                                <div class="arch-chip-card"><i class="bi bi-signpost-split"></i><strong>Intent</strong><span><em>chat</em> or <em>facts</em>, and the follow-up rewritten as a question that stands on its own. On for new bots.</span></div>
                            </div>
                        </li>
                        <li>
                            <div class="arch-step-title">Answer cache, when the bot keeps one</div>
                            <div class="arch-step-body">A question worded close enough to one answered from the knowledge base before gets that answer back at once: no search, no model. Only standalone questions; never database or web answers.</div>
                        </li>
                        <li>
                            <div class="arch-step-title">Sources, the way the bot is set to ask them</div>
                            <div class="arch-row">
                                <div class="arch-chip-card"><i class="bi bi-journal-text"></i><strong>Knowledge base</strong><span>Vector and BM25 search, optionally on rephrasings and a hypothetical answer (HyDE), fused by weighted rank (RRF), injected passages dropped, reranked, kept above the floor, widened with neighbouring passages.</span></div>
                                <div class="arch-chip-card"><i class="bi bi-database"></i><strong>Database</strong><span>SQL model writes a SELECT or declines; validated in the engine and again in the portal.</span></div>
                                <div class="arch-chip-card"><i class="bi bi-globe2"></i><strong>Web</strong><span>DuckDuckGo, Tavily or Brave, on the platform's key or the workspace's own.</span></div>
                            </div>

                            {{-- The two answer-source modes a bot chooses between under
                                 Behaviour. Pills stand for the sources above. --}}
                            <div class="arch-modes mt-2">
                                <div class="arch-mode arch-mode-default">
                                    <div class="arch-mode-title">
                                        <i class="bi bi-intersect"></i> Combined <span class="arch-mode-badge">default</span>
                                    </div>
                                    <div class="arch-flow">
                                        <span class="arch-pill-group">
                                            <span class="arch-pill"><i class="bi bi-journal-text"></i> Knowledge base</span>
                                            <span class="arch-flow-op">+</span>
                                            <span class="arch-pill"><i class="bi bi-database"></i> Database</span>
                                        </span>
                                        <span class="arch-flow-arrow"><i class="bi bi-arrow-right"></i><small>at once</small></span>
                                        <span class="arch-pill arch-pill-out"><i class="bi bi-list-ol"></i> One context</span>
                                    </div>
                                    <div class="arch-flow arch-flow-fallback">
                                        <span class="arch-flow-arrow"><i class="bi bi-arrow-return-right"></i><small>both miss</small></span>
                                        <span class="arch-pill"><i class="bi bi-globe2"></i> Web</span>
                                    </div>
                                    <p class="arch-mode-note">Every hit answers, so a question needing a policy and a record gets both. Citations renumbered as one list; half the context budget each.</p>
                                </div>
                                <div class="arch-mode">
                                    <div class="arch-mode-title">
                                        <i class="bi bi-sort-numeric-down"></i> Source order
                                    </div>
                                    <div class="arch-flow">
                                        <span class="arch-pill">1st</span>
                                        <span class="arch-flow-arrow"><i class="bi bi-chevron-right"></i><small>miss</small></span>
                                        <span class="arch-pill">2nd</span>
                                        <span class="arch-flow-arrow"><i class="bi bi-chevron-right"></i><small>miss</small></span>
                                        <span class="arch-pill">3rd</span>
                                    </div>
                                    <p class="arch-mode-note">The operator's order, and the first source with something answers alone. Cheaper and faster; a question needing two sources gets one.</p>
                                </div>
                            </div>
                        </li>
                        <li>
                            <div class="arch-step-title">Answer</div>
                            <div class="arch-step-body">The bot's model reads its prompt and the material, which is wrapped and marked as reference rather than instructions, and streams over SSE. The prompt carries a secret marker: a reply that starts reciting its instructions is stopped before the marker shows, replaced with the refusal, and flagged.</div>
                        </li>
                        <li>
                            <div class="arch-step-title">Check and record, after the visitor has the answer</div>
                            <div class="arch-row">
                                <div class="arch-chip-card"><i class="bi bi-shield-check"></i><strong>Output guard</strong><span>Flags an unsafe answer for review.</span></div>
                                <div class="arch-chip-card"><i class="bi bi-patch-check"></i><strong>Answer check</strong><span>Flags a claim the material does not support, such as an invented price.</span></div>
                                <div class="arch-chip-card"><i class="bi bi-lightning"></i><strong>Cache</strong><span>A clean knowledge-base answer is kept for the next visitor who asks.</span></div>
                            </div>
                            <div class="arch-step-body mt-2">The message stores its intent, SQL, flags, grounding verdict, cache hit, timings, tokens and the model behind each job.</div>
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
                        <div class="arch-chip-card arch-chip-optional"><i class="bi bi-card-text"></i><strong>Context</strong><span>Optional. A model writes a sentence placing each chunk in its document.</span></div>
                        <div class="arch-then" aria-hidden="true"><i class="bi bi-chevron-right"></i></div>
                        <div class="arch-chip-card"><i class="bi bi-vector-pen"></i><strong>Embed</strong><span>The Embedding model, normalised vectors.</span></div>
                        <div class="arch-then" aria-hidden="true"><i class="bi bi-chevron-right"></i></div>
                        <div class="arch-chip-card"><i class="bi bi-database"></i><strong>Store</strong><span><code>kb_chunks</code>: content and vector. The BM25 index is built from it on the next question.</span></div>
                    </div>
                    <div class="settings-note mt-3">
                        <i class="bi bi-info-circle"></i>
                        <span>A question and answer pair is one chunk, never split. Re-indexing a collection empties the answer cache of every bot that reads it. Changing the embedding model or dimensions, or switching contextual chunks, needs every source indexed again, from Rebuild the index.</span>
                    </div>
                </div>

                {{-- Techniques -------------------------------------------- --}}
                <div class="tab-pane fade" id="arch-techniques" role="tabpanel">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        @foreach($statuses as [$statusLabel, $statusClass])
                            <span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
                        @endforeach
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-2 arch-table">
                            <tbody>
                                @foreach($techniques as $group => $rows)
                                    <tr><th colspan="3" class="pt-3">{{ $group }}</th></tr>
                                    @foreach($rows as [$name, $status, $how])
                                        <tr>
                                            <td class="text-nowrap">{{ $name }}</td>
                                            <td class="text-nowrap"><span class="badge {{ $statuses[$status][1] }}">{{ $statuses[$status][0] }}</span></td>
                                            <td class="small text-muted">{{ $how }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="settings-note">
                        <i class="bi bi-info-circle"></i>
                        <span>Where two techniques compete, the operator chooses, and every choice starts off or neutral so an existing bot answers as before. Judge a change with the evaluation set (<code>python -m evals</code>) before making it a default.</span>
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
