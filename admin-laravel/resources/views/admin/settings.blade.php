@extends('layouts.app')

@section('page-title', 'Admin settings')

@section('content')
<div class="settings-page" data-section-current="{{ $section }}">

    @php
        $tabErrors = $errors->any() ? \App\Http\Controllers\AdminSettingsController::sectionsWithErrors($errors) : [];
    @endphp

    <div class="page-head mb-2">
        <div>
            <h1>{{ $groups[$group]['label'] }}</h1>
            <p>Admin settings. Applies to every workspace.</p>
        </div>
    </div>

    {{-- The group's categories, a tab each. Each tab is its own page, so these
         are links and a category keeps its own address. On a phone they
         become a select. --}}
    <ul class="nav nav-tabs settings-tabs mb-3 d-none d-md-flex">
        @foreach($groups[$group]['sections'] as $key)
            <li class="nav-item">
                <a class="nav-link d-inline-flex align-items-center gap-1.5 {{ $key === $section ? 'active' : '' }}"
                   href="{{ route('admin.settings', $key) }}" @if($key === $section) aria-current="page" @endif>
                    <i class="bi {{ $sections[$key]['icon'] }}"></i> {{ $sections[$key]['label'] }}
                    @if(in_array($key, $tabErrors, true))
                        <span class="sidebar-error-dot" title="Has a problem to fix"></span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
    <select class="form-select d-md-none mb-3" aria-label="Settings category"
            onchange="window.location.href = this.value">
        @foreach($groups[$group]['sections'] as $key)
            <option value="{{ route('admin.settings', $key) }}" @selected($key === $section)>{{ $sections[$key]['label'] }}</option>
        @endforeach
    </select>

    <p class="text-muted mb-3" style="font-size: 0.8125rem;">{{ $sections[$section]['description'] }}</p>

    {{-- Providers --------------------------------------------------------
         Outside the form: each change saves through the modal at once. The
         cards are drawn by the script, so an edit shows without a reload. --}}
    @if($section === 'providers')
        <div id="providerListStatus" class="small fw-medium mb-2" aria-live="polite"></div>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3" id="providerList"></div>
    @endif

    {{-- One form behind every category that has fields. Only the open one is
         shown, but all of them post, so a save never blanks a category
         nobody opened. novalidate, because a required field on a hidden
         category would stop the browser submitting without saying why; the
         server validates and opens the category that failed. --}}
    <form action="{{ route('admin.settings.update') }}" method="POST"
          enctype="multipart/form-data" novalidate>
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="{{ $section }}">

        {{-- Models ----------------------------------------------------------
             One card per job, embedding first. The first option of each
             provider select is what the job does with no provider. --}}
        <div @class(['d-none' => $section !== 'models'])>
            <div class="row row-cols-1 row-cols-md-2 row-cols-xxl-3 g-3 mb-3">
                <div class="col">
                    <div class="card h-100 job-card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <i class="bi bi-vector-pen"></i>
                            <span>Embedding</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto py-0" id="btnTestEmbedding"
                                    title="Embed a test sentence and read the dimensions">
                                <i class="bi bi-plug"></i> Test
                            </button>
                        </div>
                        <div class="p-3">
                            <p class="job-desc" title="Turns passages and questions into vectors. Changing the model or the dimensions invalidates every stored vector: save, then re-index from Maintenance. Until then, collections search by keyword only.">
                                Turns passages and questions into vectors. A change needs a re-index from
                                <a href="{{ route('admin.settings', 'maintenance') }}">Maintenance</a>.
                            </p>
                            @include('admin._model-picker', [
                                'providerField' => 'embedding_provider_id',
                                'modelField' => 'embedding_model',
                                'blank' => 'Ollama on this machine',
                                'placeholder' => 'nomic-embed-text',
                            ])
                            <div class="input-group input-group-sm has-validation">
                                <label for="embedding_dimensions" class="input-group-text picker-label">Dimensions</label>
                                <input type="number" name="embedding_dimensions" id="embedding_dimensions"
                                       class="form-control font-monospace @error('embedding_dimensions') is-invalid @enderror"
                                       min="64" max="4096" title="Test fills this in"
                                       value="{{ old('embedding_dimensions', $settings['embedding_dimensions']) }}">
                                @error('embedding_dimensions')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div id="embeddingTestResult" class="model-status" aria-live="polite"></div>
                        </div>
                    </div>
                </div>

                @foreach ($modelRoles as $role => $meta)
                    <div class="col">
                        <div class="card h-100 job-card">
                            <div class="card-header d-flex align-items-center gap-2">
                                <i class="bi {{ $meta['icon'] }}"></i>
                                <span>{{ $meta['label'] }}</span>
                                <span class="job-default ms-auto" title="What this job does with no provider">
                                    Default: {{ $meta['blank'] }}
                                </span>
                            </div>
                            <div class="p-3">
                                <p class="job-desc" title="{{ $meta['job'] }}">{{ $meta['job'] }}</p>
                                @include('admin._model-picker', [
                                    'providerField' => "{$role}_model_provider_id",
                                    'modelField' => "{$role}_model_name",
                                    'blank' => $meta['blank'],
                                    'placeholder' => $meta['placeholder'],
                                ])
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Guard ----------------------------------------------------------- --}}
        @php
            $chosen = old('guard_categories_present') ? old('guard_categories', []) : $guardChosen;
            $borderline = old('guard_borderline', $settings['guard_borderline'] ?: 'allow');
        @endphp
        <div @class(['d-none' => $section !== 'guard'])>
            <input type="hidden" name="guard_categories_present" value="1">

            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center justify-content-between gap-2">
                            <span class="d-flex align-items-center gap-2">
                                <i class="bi bi-shield-exclamation"></i> Harm categories
                                <span class="chip" id="guardCount"></span>
                            </span>
                            <span class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-guard-all="1">All</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-guard-all="0">None</button>
                            </span>
                        </div>
                        <div class="p-3">
                            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-2">
                                @foreach($guardCategories as $key => $meta)
                                    <div class="col">
                                        <label class="guard-option" for="guard_category_{{ $key }}">
                                            <input class="form-check-input" type="checkbox" name="guard_categories[]"
                                                   value="{{ $key }}" id="guard_category_{{ $key }}"
                                                   @checked(in_array($key, $chosen, true))>
                                            <span>
                                                <span class="guard-option-label">{{ $meta['label'] }}</span>
                                                <span class="guard-option-hint">{{ $meta['hint'] }}</span>
                                            </span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            @error('guard_categories')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                            @error('guard_categories.*')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="col-xl-4 d-flex flex-column gap-3">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <i class="bi bi-question-diamond"></i> Borderline
                        </div>
                        <div class="p-3">
                            <div class="btn-group w-100" role="group" aria-label="Borderline verdicts">
                                <input type="radio" class="btn-check" name="guard_borderline" id="guard_borderline_allow"
                                       value="allow" autocomplete="off" @checked($borderline === 'allow')>
                                <label class="btn btn-sm btn-outline-secondary" for="guard_borderline_allow">Let through</label>
                                <input type="radio" class="btn-check" name="guard_borderline" id="guard_borderline_block"
                                       value="block" autocomplete="off" @checked($borderline === 'block')>
                                <label class="btn btn-sm btn-outline-secondary" for="guard_borderline_block">Block</label>
                            </div>
                            <div class="form-text">
                                When the guard is unsure, such as a question near politics or health. Blocking is
                                safer and refuses more ordinary questions.
                            </div>
                        </div>
                    </div>

                    <div class="card flex-grow-1">
                        <div class="card-header d-flex align-items-center gap-2">
                            <i class="bi bi-slash-circle"></i> Blocked topics
                        </div>
                        <div class="p-3">
                            <textarea name="guard_topics" id="guard_topics" rows="4" maxlength="2000"
                                      class="form-control form-control-sm @error('guard_topics') is-invalid @enderror"
                                      placeholder="competitor pricing&#10;legal advice&#10;medical diagnosis"
                                      aria-label="Blocked topics">{{ old('guard_topics', $settings['guard_topics']) }}</textarea>
                            @error('guard_topics')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">One per line, in plain words. Each bot can add more under Behaviour.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-note mb-3">
                <i class="bi bi-info-circle"></i>
                <span>
                    The guard runs only on bots that switch it on under Behaviour, on the Guard model set in
                    <a href="{{ route('admin.settings', 'models') }}">Models</a> or each bot's main model. A dedicated
                    guard model such as Qwen3Guard judges its own categories only, so topics are then checked by the
                    bot's main model in a second call.
                </span>
            </div>
        </div>

        {{-- Security -------------------------------------------------------- --}}
        @php
            $choice = fn (string $key) => old($key, $settings[$key] ?: \App\Models\AppSetting::DEFAULTS[$key]);
            $securityChoices = [
                'history_source' => [
                    'icon' => 'bi-clock-history', 'title' => 'Conversation history',
                    'options' => ['server' => 'Engine records', 'client' => 'Widget copy'],
                    'hints' => [
                        'server' => 'The models read the conversation as the engine recorded it. A visitor cannot invent earlier turns, such as a fake reply in which the bot agreed to drop its rules.',
                        'client' => 'The models read the copy the widget sends, cleaned of anything but visitor and assistant turns. Only for a widget you control end to end.',
                    ],
                ],
                'injection_shield' => [
                    'icon' => 'bi-shield-exclamation', 'title' => 'Injection shield',
                    'options' => ['block' => 'Block', 'flag' => 'Flag only', 'off' => 'Off'],
                    'hints' => [
                        'block' => 'A message worded to override the bot ("ignore your previous instructions", a fake system tag, hidden characters) gets the refusal, and no model reads it.',
                        'flag' => 'Such a message is answered as usual and marked in conversations, to see what would be blocked before blocking it.',
                        'off' => 'Messages are not checked by pattern. The guard model, where a bot has it on, still runs.',
                    ],
                ],
                'injection_shield_sources' => [
                    'icon' => 'bi-funnel', 'title' => 'Shield retrieved material',
                    'options' => ['drop' => 'Drop', 'off' => 'Off'],
                    'hints' => [
                        'drop' => 'A knowledge-base passage or web result written as instructions to the model is left out before the model reads it. Uploaded files and web pages can carry them too.',
                        'off' => 'Every passage found is used. All material is still marked as reference, not instructions.',
                    ],
                ],
                'leak_guard' => [
                    'icon' => 'bi-incognito', 'title' => 'Prompt leak guard',
                    'options' => ['on' => 'On', 'off' => 'Off'],
                    'hints' => [
                        'on' => 'Each answer carries a secret marker in its instructions. A reply that starts reciting them is stopped before the marker is shown, replaced with the refusal, and flagged.',
                        'off' => 'No marker is added. A reply copying the system prompt goes unflagged.',
                    ],
                ],
            ];
        @endphp
        <div @class(['d-none' => $section !== 'security'])>
            <div class="row g-3 mb-3">
                @foreach($securityChoices as $key => $meta)
                    @php $current = $choice($key); @endphp
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header d-flex align-items-center gap-2"><i class="bi {{ $meta['icon'] }}"></i> {{ $meta['title'] }}</div>
                            <div class="p-3">
                                <div class="btn-group w-100" role="group" aria-label="{{ $meta['title'] }}" data-choice-hints>
                                    @foreach($meta['options'] as $value => $label)
                                        <input type="radio" class="btn-check" name="{{ $key }}" id="{{ $key }}_{{ $value }}"
                                               value="{{ $value }}" autocomplete="off" @checked($current === $value)
                                               data-hint="{{ $meta['hints'][$value] }}">
                                        <label class="btn btn-sm btn-outline-secondary" for="{{ $key }}_{{ $value }}">{{ $label }}</label>
                                    @endforeach
                                </div>
                                <div class="form-text" data-choice-hint>{{ $meta['hints'][$current] ?? '' }}</div>
                                @error($key)<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="settings-note mb-3">
                <i class="bi bi-info-circle"></i>
                <span>
                    These are the layers that need no model. On every bot, retrieved material is also marked as reference
                    rather than instructions, and a message is capped at 4,000 characters. The guard model, switched on per
                    bot under Behaviour, judges what patterns cannot; set its categories under
                    <a href="{{ route('admin.settings', 'guard') }}">Guard</a>.
                </span>
            </div>
        </div>

        {{-- Chunking -------------------------------------------------------- --}}
        <div @class(['d-none' => $section !== 'chunking'])>
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-scissors"></i> Chunking</div>
                <div class="p-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="chunk_size" class="form-label">Chunk size</label>
                            <input type="number" name="chunk_size" id="chunk_size"
                                   class="form-control form-control-sm font-monospace @error('chunk_size') is-invalid @enderror"
                                   min="400" max="8000" value="{{ old('chunk_size', $settings['chunk_size']) }}">
                            @error('chunk_size')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">A ceiling, not a target. Headings, tables and code blocks decide where a passage ends.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="chunk_overlap" class="form-label">Overlap</label>
                            <input type="number" name="chunk_overlap" id="chunk_overlap"
                                   class="form-control form-control-sm font-monospace @error('chunk_overlap') is-invalid @enderror"
                                   min="0" value="{{ old('chunk_overlap', $settings['chunk_overlap']) }}">
                            @error('chunk_overlap')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Characters repeated when one long passage has to be cut. Sections never overlap.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="context_char_budget" class="form-label">Context budget</label>
                            <input type="number" name="context_char_budget" id="context_char_budget"
                                   class="form-control form-control-sm font-monospace @error('context_char_budget') is-invalid @enderror"
                                   min="1000" max="20000" value="{{ old('context_char_budget', $settings['context_char_budget']) }}">
                            @error('context_char_budget')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Characters of retrieved material sent to the model. Lower it if answers wander.</div>
                        </div>
                    </div>
                    <div class="settings-note mt-3">
                        <i class="bi bi-info-circle"></i>
                        <span>Applies to sources indexed from now on. Existing chunks keep their boundaries until you rebuild the index.</span>
                    </div>
                </div>
            </div>

            @php
                $searchChoices = [
                    'keyword_engine' => [
                        'icon' => 'bi-sort-alpha-down', 'title' => 'Keyword ranking',
                        'options' => ['bm25' => 'BM25', 'postgres' => 'Postgres full text'],
                        'hints' => [
                            'bm25' => 'Okapi BM25, run inside the engine: a rare word such as a product code outweighs a common one, and Malay and English are read alike. The same on Postgres and SQLite.',
                            'postgres' => "The database's own full-text search. Stems English words, so Malay matches less well, and weighs how close the words sit rather than how rare they are.",
                        ],
                    ],
                    'contextual_chunks' => [
                        'icon' => 'bi-card-text', 'title' => 'Contextual chunks',
                        'options' => ['off' => 'Off', 'on' => 'On'],
                        'hints' => [
                            'off' => 'Each chunk carries its document title, headings and description, written without a model.',
                            'on' => 'A model also writes one sentence placing each chunk in its document, which is searched with it. One call per chunk while indexing, none while answering. Rebuild the index after switching.',
                        ],
                    ],
                ];
            @endphp
            <div class="row g-3 mb-3">
                @foreach($searchChoices as $key => $meta)
                    @php $current = $choice($key); @endphp
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header d-flex align-items-center gap-2"><i class="bi {{ $meta['icon'] }}"></i> {{ $meta['title'] }}</div>
                            <div class="p-3">
                                <div class="btn-group w-100" role="group" aria-label="{{ $meta['title'] }}" data-choice-hints>
                                    @foreach($meta['options'] as $value => $label)
                                        <input type="radio" class="btn-check" name="{{ $key }}" id="{{ $key }}_{{ $value }}"
                                               value="{{ $value }}" autocomplete="off" @checked($current === $value)
                                               data-hint="{{ $meta['hints'][$value] }}">
                                        <label class="btn btn-sm btn-outline-secondary" for="{{ $key }}_{{ $value }}">{{ $label }}</label>
                                    @endforeach
                                </div>
                                <div class="form-text" data-choice-hint>{{ $meta['hints'][$current] ?? '' }}</div>
                                @error($key)<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Voice ----------------------------------------------------------- --}}
        @php
            $speechEngine = $choice('speech_engine');
            $listenEngine = $choice('transcribe_engine');
            $voiceSlots = [
                'en_female' => 'English, female',
                'en_male' => 'English, male',
                'ms_female' => 'Malay, female',
                'ms_male' => 'Malay, male',
            ];
            $speechHints = [
                'browser' => "The visitor's own device speaks, so there is nothing to install or pay for. The voices are whatever that device has: most have English, fewer have Malay, and not every device has both a female and a male voice. The widget says when one is missing.",
                'server' => 'A speech server you run, or a hosted one, that speaks the OpenAI audio API (POST /v1/audio/speech): Kokoro or a Malaysian model on the DGX Sparks, OpenAI itself, or the openai-edge-tts container for testing. Pick its provider below and give the voice names it uses.',
                'azure' => "Microsoft Azure Speech: the four voices below are Azure's own English and Malay neural voices. Needs a Speech resource's region and key; Azure has a free monthly allowance.",
            ];
            $listenHints = [
                'browser' => "The browser's own speech recognition. Best in Chrome and Edge, missing in some browsers, and Chrome sends the audio to Google to recognise it.",
                'server' => 'A Whisper-family server on the OpenAI audio API (POST /v1/audio/transcriptions), such as faster-whisper or vLLM. The recording goes to it through the engine; only the words are kept.',
            ];
        @endphp
        <div @class(['d-none' => $section !== 'voice'])>
            <div class="row g-3 mb-3">
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-volume-up"></i> Speaking</div>
                        <div class="p-3">
                            <div class="btn-group w-100" role="group" aria-label="Speaking engine" data-choice-hints>
                                @foreach(['browser' => 'Browser', 'server' => 'Speech server', 'azure' => 'Azure Speech'] as $value => $label)
                                    <input type="radio" class="btn-check" name="speech_engine" id="speech_engine_{{ $value }}" value="{{ $value }}"
                                           autocomplete="off" @checked($speechEngine === $value) data-hint="{{ $speechHints[$value] }}">
                                    <label class="btn btn-sm btn-outline-secondary" for="speech_engine_{{ $value }}">{{ $label }}</label>
                                @endforeach
                            </div>
                            <div class="form-text" data-choice-hint>{{ $speechHints[$speechEngine] ?? '' }}</div>

                            <div class="row g-3 mt-1" data-speech-for="server">
                                <div class="col-md-7">
                                    <label for="speech_provider_id" class="form-label">Provider</label>
                                    <select name="speech_provider_id" id="speech_provider_id"
                                            class="form-select form-select-sm @error('speech_provider_id') is-invalid @enderror">
                                        <option value="">Choose a provider…</option>
                                        @foreach($providers as $provider)
                                            <option value="{{ $provider['id'] }}" @selected(old('speech_provider_id', $settings['speech_provider_id']) === $provider['id'])>{{ $provider['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('speech_provider_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">Add one under <a href="{{ route('admin.settings', 'providers') }}">Providers</a>, such as <code>http://localhost:5050/v1</code>.</div>
                                </div>
                                <div class="col-md-5">
                                    <label for="speech_model" class="form-label">Model</label>
                                    <input type="text" name="speech_model" id="speech_model" class="form-control form-control-sm font-monospace"
                                           value="{{ old('speech_model', $settings['speech_model'] ?: 'tts-1') }}" placeholder="tts-1">
                                </div>
                            </div>

                            <div class="row g-3 mt-1" data-speech-for="azure">
                                <div class="col-md-5">
                                    <label for="azure_speech_region" class="form-label">Region</label>
                                    <input type="text" name="azure_speech_region" id="azure_speech_region"
                                           class="form-control form-control-sm font-monospace @error('azure_speech_region') is-invalid @enderror"
                                           value="{{ old('azure_speech_region', $settings['azure_speech_region']) }}" placeholder="southeastasia">
                                    @error('azure_speech_region')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-7">
                                    <label for="azure_speech_key" class="form-label">Key</label>
                                    <input type="password" name="azure_speech_key" id="azure_speech_key" autocomplete="off"
                                           class="form-control form-control-sm font-monospace @error('azure_speech_key') is-invalid @enderror"
                                           value="{{ old('azure_speech_key', $settings['azure_speech_key']) }}">
                                    @error('azure_speech_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-mic"></i> Listening</div>
                        <div class="p-3">
                            <div class="btn-group w-100" role="group" aria-label="Listening engine" data-choice-hints>
                                @foreach(['browser' => 'Browser', 'server' => 'Transcription server'] as $value => $label)
                                    <input type="radio" class="btn-check" name="transcribe_engine" id="transcribe_engine_{{ $value }}" value="{{ $value }}"
                                           autocomplete="off" @checked($listenEngine === $value) data-hint="{{ $listenHints[$value] }}">
                                    <label class="btn btn-sm btn-outline-secondary" for="transcribe_engine_{{ $value }}">{{ $label }}</label>
                                @endforeach
                            </div>
                            <div class="form-text" data-choice-hint>{{ $listenHints[$listenEngine] ?? '' }}</div>

                            <div class="row g-3 mt-1" data-listen-for="server">
                                <div class="col-7">
                                    <label for="transcribe_provider_id" class="form-label">Provider</label>
                                    <select name="transcribe_provider_id" id="transcribe_provider_id"
                                            class="form-select form-select-sm @error('transcribe_provider_id') is-invalid @enderror">
                                        <option value="">Choose a provider…</option>
                                        @foreach($providers as $provider)
                                            <option value="{{ $provider['id'] }}" @selected(old('transcribe_provider_id', $settings['transcribe_provider_id']) === $provider['id'])>{{ $provider['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('transcribe_provider_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-5">
                                    <label for="transcribe_model" class="form-label">Model</label>
                                    <input type="text" name="transcribe_model" id="transcribe_model" class="form-control form-control-sm font-monospace"
                                           value="{{ old('transcribe_model', $settings['transcribe_model'] ?: 'whisper-1') }}" placeholder="whisper-1">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-people"></i> The four voices</div>
                <div class="p-3" id="voiceBoard"
                     data-engine="{{ $speechEngine }}"
                     data-test-url="{{ route('admin.settings.voice-test') }}">
                    <label for="voice_preview_text" class="form-label">Preview text</label>
                    <textarea id="voice_preview_text" rows="2" maxlength="300" class="form-control form-control-sm mb-1"
                              placeholder="Type anything to hear it in a voice below.">Hello! Selamat datang. How can I help you today?</textarea>
                    <div class="form-text mb-3">Press play beside a voice to hear this text in it.</div>

                    <div class="row g-3">
                        @foreach(['en' => ['English', 'EN'], 'ms' => ['Malay', 'BM']] as $lang => [$langLabel, $langMark])
                            <div class="col-lg-6">
                                <div class="voice-lang">
                                    <div class="voice-lang-head">
                                        <span class="voice-lang-mark">{{ $langMark }}</span>
                                        <strong>{{ $langLabel }}</strong>
                                    </div>
                                    @foreach(['female' => ['Female', 'bi-gender-female'], 'male' => ['Male', 'bi-gender-male']] as $gender => [$genderLabel, $genderIcon])
                                        @php $slot = $lang . '_' . $gender; $current = old('voice_' . $slot, $settings['voice_' . $slot] ?: \App\Models\AppSetting::DEFAULTS['voice_' . $slot]); @endphp
                                        <div class="voice-row">
                                            <span class="voice-row-label"><i class="bi {{ $genderIcon }}"></i> {{ $genderLabel }}</span>
                                            <div class="voice-row-pick" data-speech-for="server azure">
                                                <select class="form-select form-select-sm" data-voice-pick="{{ $slot }}" aria-label="{{ $langLabel }} {{ $genderLabel }} voice">
                                                    @foreach($voiceCatalogue[$slot] as $name => $label)
                                                        <option value="{{ $name }}" @selected($current === $name)>{{ $label }}</option>
                                                    @endforeach
                                                    <option value="__custom" @selected(!array_key_exists($current, $voiceCatalogue[$slot]))>Another name…</option>
                                                </select>
                                                <input type="text" name="voice_{{ $slot }}" id="voice_{{ $slot }}" value="{{ $current }}"
                                                       class="form-control form-control-sm font-monospace mt-1 @if(array_key_exists($current, $voiceCatalogue[$slot])) d-none @endif"
                                                       placeholder="The speech server's voice name" aria-label="{{ $langLabel }} {{ $genderLabel }} voice name">
                                            </div>
                                            <div class="voice-row-pick text-muted small" data-speech-for="browser" data-device-voice="{{ $slot }}">
                                                Each visitor's own device voice.
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-voice-test="{{ $slot }}"
                                                    title="Play the preview text" aria-label="Play {{ $langLabel }} {{ $genderLabel }}">
                                                <i class="bi bi-play-fill"></i>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="small fw-medium mt-2" id="voiceTestStatus" aria-live="polite"></div>
                    <div class="settings-note mt-3">
                        <i class="bi bi-info-circle"></i>
                        <span data-speech-for="server azure">
                            Pick a voice and press play to hear it, with the engine as this page shows it, before saving.
                            The names are Microsoft's, the same in Azure Speech and in the openai-edge-tts container. Malay has
                            one female and one male voice; Indonesian, which Malay listeners follow, is offered beside them.
                            For another speech server, choose "Another name" and type its voice's name.
                        </span>
                        <span data-speech-for="browser">
                            With the browser engine, each visitor's device speaks with whatever voices it has. Play uses this
                            device's voices, the way a visitor's would, and says when one of the four is missing.
                        </span>
                    </div>
                </div>
            </div>

            <div class="settings-note mb-3">
                <i class="bi bi-info-circle"></i>
                <span>
                    Switch voice on per bot under Behaviour: a speaker on each answer, reading aloud as answers arrive, and a
                    microphone. Visitors choose among the four voices, English or Malay, female or male, in the widget.
                    Speech made by a server is billed to whoever runs it; only bots with voice on, asked from their
                    workspace's allowed sites, can use it.
                </span>
            </div>
        </div>
        {{-- Web search ------------------------------------------------------ --}}
        <div @class(['d-none' => $section !== 'web-search'])>
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-globe2"></i> Web search</div>
                <div class="p-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="web_search_provider" class="form-label">Provider</label>
                            <select name="web_search_provider" id="web_search_provider"
                                    class="form-select form-select-sm @error('web_search_provider') is-invalid @enderror">
                                <option value="duckduckgo" @selected(old('web_search_provider', $settings['web_search_provider']) === 'duckduckgo')>DuckDuckGo (no key)</option>
                                <option value="tavily" @selected(old('web_search_provider', $settings['web_search_provider']) === 'tavily')>Tavily</option>
                                <option value="brave" @selected(old('web_search_provider', $settings['web_search_provider']) === 'brave')>Brave</option>
                            </select>
                            @error('web_search_provider')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label for="web_search_tavily_key" class="form-label">Tavily API key</label>
                            <input type="password" name="web_search_tavily_key" id="web_search_tavily_key"
                                   class="form-control form-control-sm font-monospace @error('web_search_tavily_key') is-invalid @enderror"
                                   autocomplete="off" value="{{ old('web_search_tavily_key', $settings['web_search_tavily_key']) }}">
                            @error('web_search_tavily_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label for="web_search_brave_key" class="form-label">Brave API key</label>
                            <input type="password" name="web_search_brave_key" id="web_search_brave_key"
                                   class="form-control form-control-sm font-monospace @error('web_search_brave_key') is-invalid @enderror"
                                   autocomplete="off" value="{{ old('web_search_brave_key', $settings['web_search_brave_key']) }}">
                            @error('web_search_brave_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="settings-note mt-3">
                        <i class="bi bi-info-circle"></i>
                        <span>
                            DuckDuckGo needs no key and is rate limited: a way to try the feature, not to rely on.
                            Tavily returns page text; Brave returns snippets. Each key is kept when you switch.
                            Turn web search on, and place it in the answer sources, per bot under Behaviour.
                        </span>
                    </div>
                </div>
            </div>

            @php
                $lending = old('web_search_lending', $settings['web_search_lending'] ?: 'all');
                $lendingHints = [
                    'all' => "A bot in any workspace may search with the provider and key above, on the platform's account. A workspace can still bring its own key under Behaviour.",
                    'none' => "Only the console's own bots use the search above. A workspace bot set to the platform's search uses DuckDuckGo instead, and a workspace brings its own Tavily or Brave key for anything better.",
                ];
            @endphp
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-share"></i> Lend this search to workspaces</div>
                <div class="p-3">
                    <div class="btn-group w-100" role="group" aria-label="Lend this search to workspaces" data-choice-hints style="max-width: 420px;">
                        <input type="radio" class="btn-check" name="web_search_lending" id="web_search_lending_all" value="all"
                               autocomplete="off" @checked($lending === 'all') data-hint="{{ $lendingHints['all'] }}">
                        <label class="btn btn-sm btn-outline-secondary" for="web_search_lending_all">Every workspace</label>
                        <input type="radio" class="btn-check" name="web_search_lending" id="web_search_lending_none" value="none"
                               autocomplete="off" @checked($lending === 'none') data-hint="{{ $lendingHints['none'] }}">
                        <label class="btn btn-sm btn-outline-secondary" for="web_search_lending_none">Console bots only</label>
                    </div>
                    <div class="form-text" data-choice-hint>{{ $lendingHints[$lending] ?? '' }}</div>
                    @error('web_search_lending')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        {{-- Branding -------------------------------------------------------- --}}
        <div @class(['d-none' => $section !== 'branding'])>
            <div class="row row-cols-1 row-cols-md-2 g-3 mb-3">
                <div class="col">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-type"></i> Wordmark</div>
                        <div class="p-3">
                            <div class="brand-preview brand-preview-wide mb-2">
                                <img src="{{ $logoUrl }}" alt="The wordmark in use">
                            </div>
                            <input type="file" name="brand_logo" id="brand_logo" aria-label="Wordmark"
                                   class="form-control form-control-sm @error('brand_logo') is-invalid @enderror"
                                   accept="image/png,image/jpeg,image/webp">
                            @error('brand_logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Sidebar and sign-in screen, about 28px tall. A wide image works best.</div>
                            @if($logoIsCustom)
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           name="brand_logo_revert" id="brand_logo_revert">
                                    <label class="form-check-label" for="brand_logo_revert">Use the built-in one</label>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-app"></i> Browser tab icon</div>
                        <div class="p-3">
                            <div class="brand-preview brand-preview-square mb-2">
                                <img src="{{ $iconUrl }}" alt="The browser tab icon in use">
                            </div>
                            <input type="file" name="brand_icon" id="brand_icon" aria-label="Browser tab icon"
                                   class="form-control form-control-sm @error('brand_icon') is-invalid @enderror"
                                   accept="image/png,image/jpeg,image/webp">
                            @error('brand_icon')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Browser tab, bookmarks and phone home screen. Square, at least 128px.</div>
                            @if($iconIsCustom)
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           name="brand_icon_revert" id="brand_icon_revert">
                                    <label class="form-check-label" for="brand_icon_revert">Use the built-in one</label>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="settings-note mb-3">
                <i class="bi bi-info-circle"></i>
                <span>PNG, JPG or WebP. Not SVG: one served from this site runs its own script for anyone who opens it directly.</span>
            </div>
        </div>

        @if(in_array($section, ['models', 'guard', 'chunking', 'web-search', 'branding'], true))
            <div class="form-actions">
                <span class="text-muted d-none d-sm-inline" style="font-size: 0.75rem;">Saves every category, and applies to every workspace.</span>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <button type="submit" class="btn btn-sm btn-brand">Save settings</button>
                </div>
            </div>
        @endif
    </form>

    {{-- Maintenance ----------------------------------------------------- --}}
    @if($section === 'maintenance')
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
            <div class="col">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-diagram-3"></i> Architecture</div>
                    <div class="p-3 d-flex flex-column gap-3">
                        <p class="text-muted mb-0 small">
                            How the portal, the engine and the models fit together: the path a message takes, how a
                            document is indexed, which model does each job right now, and the plan for the two DGX Sparks.
                        </p>
                        <div class="d-flex flex-wrap gap-1">
                            <span class="chip">2 services</span>
                            <span class="chip">1 database</span>
                            <span class="chip">{{ count($modelRoles) + 2 }} model jobs</span>
                        </div>
                        <div class="mt-auto">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal" data-bs-target="#architectureModal">
                                <i class="bi bi-diagram-3"></i> View architecture
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-arrow-repeat"></i> Rebuild the index</div>
                    <div class="p-3 d-flex flex-column gap-3">
                        <p class="text-muted mb-0 small">
                            Re-embeds every source in every workspace with the saved embedding settings. Run it after
                            changing the model or the dimensions. It runs in the background.
                        </p>
                        <form action="{{ route('admin.settings.reindex') }}" method="POST" class="m-0 mt-auto"
                              data-confirm="Re-index everything?" data-confirm-tone="primary"
                              data-confirm-message="Every source in every workspace is embedded again with the saved settings. It runs in the background, and answers may be thinner until it finishes."
                              data-confirm-label="Re-index everything">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-arrow-repeat"></i> Re-index everything
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>

@include('admin._provider-modal')
{{-- The architecture modal itself is in the layout, for every page; here it
     shows the live model settings. --}}
@endsection

@push('scripts')
<script src="{{ rtrim($apiHost ?? 'http://localhost:8000', '/') }}/widget-voice.js"></script>
<style>
    .voice-lang { border: 1px solid var(--border); border-radius: var(--r-md); padding: 0.75rem; height: 100%; }
    .voice-lang-head { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
    .voice-lang-mark { display: inline-flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem;
        border-radius: 50%; background: var(--surface-2); color: var(--text-muted, inherit); font-size: 0.6875rem; font-weight: 700; }
    .voice-row { display: grid; grid-template-columns: 5.5rem minmax(0, 1fr) auto; align-items: start; gap: 0.5rem; padding: 0.375rem 0; }
    .voice-row + .voice-row { border-top: 1px solid var(--border); }
    .voice-row-label { font-size: 0.8125rem; padding-top: 0.3rem; display: inline-flex; align-items: center; gap: 0.375rem; }
    .voice-row-label i { color: var(--text-faint); }
    .voice-row-pick.text-muted { padding-top: 0.3rem; }
</style>
<script>
    // The Voice page: the fields of the engine picked, a list or a typed name
    // for each of the four voices, and play buttons that speak the preview
    // text, in the browser like a visitor's device or through the engine.
    (function () {
        function show(attr, name) {
            var picked = document.querySelector('input[name="' + name + '"]:checked');
            document.querySelectorAll('[' + attr + ']').forEach(function (el) {
                var wanted = el.getAttribute(attr).split(' ');
                el.classList.toggle('d-none', !picked || wanted.indexOf(picked.value) === -1);
            });
        }
        function engine() {
            var picked = document.querySelector('input[name="speech_engine"]:checked');
            return picked ? picked.value : 'browser';
        }
        function refresh() { show('data-speech-for', 'speech_engine'); show('data-listen-for', 'transcribe_engine'); describeDevice(); }
        document.addEventListener('change', function (event) {
            if (event.target.name === 'speech_engine' || event.target.name === 'transcribe_engine') { refresh(); }
        });

        // A list for each voice, with "Another name" for a server that names
        // its voices its own way. The text box is what is saved.
        document.querySelectorAll('[data-voice-pick]').forEach(function (select) {
            var input = document.getElementById('voice_' + select.dataset.voicePick);
            select.addEventListener('change', function () {
                var custom = select.value === '__custom';
                input.classList.toggle('d-none', !custom);
                if (custom) { input.value = ''; input.focus(); } else { input.value = select.value; }
            });
        });

        var kit = window.__ChatbotVoice || null;
        var status = document.getElementById('voiceTestStatus');
        var text = document.getElementById('voice_preview_text');
        var playing = null;

        function say(message, tone) {
            status.className = 'small fw-medium mt-2 ' + (tone === 'bad' ? 'text-danger' : tone === 'good' ? 'text-success' : 'text-muted');
            status.textContent = message;
        }

        function stop() {
            if (playing) { playing.pause(); playing = null; }
            if (window.speechSynthesis) { window.speechSynthesis.cancel(); }
        }

        var labels = { en: 'English', ms: 'Malay', female: 'female', male: 'male' };

        // In the browser engine, name the device voice each of the four
        // would use here, or say that this device has none.
        function describeDevice() {
            if (engine() !== 'browser' || !kit || !window.speechSynthesis) { return; }
            var voices = window.speechSynthesis.getVoices();
            document.querySelectorAll('[data-device-voice]').forEach(function (el) {
                var parts = el.dataset.deviceVoice.split('_');
                var picked = kit.pickVoice(voices, parts[0], parts[1]);
                el.textContent = picked.voice
                    ? 'Here: ' + picked.voice.name + (picked.match === 'exact' ? '' : ' (closest this device has)')
                    : 'This device has no ' + labels[parts[0]] + ' voice.';
            });
        }

        function playInBrowser(slot, words) {
            if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) { say('This browser cannot read aloud.', 'bad'); return; }
            var parts = slot.split('_');
            var picked = kit ? kit.pickVoice(window.speechSynthesis.getVoices(), parts[0], parts[1]) : { voice: null, match: 'none' };
            var utterance = new SpeechSynthesisUtterance(words);
            utterance.lang = parts[0] === 'ms' ? 'ms-MY' : 'en-US';
            if (picked.voice) { utterance.voice = picked.voice; utterance.lang = picked.voice.lang; }
            window.speechSynthesis.speak(utterance);
            say(picked.match === 'exact' ? 'Playing ' + picked.voice.name + ' on this device.'
                : picked.voice ? 'This device has no ' + parts[1] + ' ' + labels[parts[0]] + ' voice; playing ' + picked.voice.name + '.'
                : 'This device has no ' + labels[parts[0]] + ' voice; the browser default is speaking.',
                picked.match === 'exact' ? 'good' : 'bad');
        }

        function field(name) {
            var el = document.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
        }

        function playOnEngine(slot, words) {
            var name = (document.getElementById('voice_' + slot) || {}).value || '';
            say('Asking for a sample…');
            fetch(document.getElementById('voiceBoard').dataset.testUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'audio/mpeg, text/plain, application/json',
                           'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({
                    voice: slot, text: words, voice_name: name,
                    // The engine as this form has it, saved or not.
                    speech_engine: engine(),
                    speech_provider_id: field('speech_provider_id'),
                    speech_model: field('speech_model'),
                    azure_speech_region: field('azure_speech_region'),
                    azure_speech_key: field('azure_speech_key')
                })
            }).then(function (response) {
                if (!response.ok) { return response.text().then(function (t) { throw new Error(t || ('HTTP ' + response.status)); }); }
                return response.blob();
            }).then(function (blob) {
                playing = new Audio(URL.createObjectURL(blob));
                playing.play();
                say('Playing ' + name + '.', 'good');
            }).catch(function (error) { say(error.message, 'bad'); });
        }

        document.querySelectorAll('[data-voice-test]').forEach(function (button) {
            button.addEventListener('click', function () {
                stop();
                var raw = (text.value || '').trim();
                var words = kit ? kit.speakable(raw) : raw;
                if (!words) { say('Type some preview text first.', 'bad'); return; }
                if (engine() === 'browser') { playInBrowser(button.dataset.voiceTest, words); }
                else { playOnEngine(button.dataset.voiceTest, words.slice(0, 300)); }
            });
        });

        if (window.speechSynthesis && window.speechSynthesis.addEventListener) {
            window.speechSynthesis.addEventListener('voiceschanged', describeDevice);
        }
        refresh();
    })();

    // Each choice card says what its picked option does, and says it again
    // when another is picked.
    document.querySelectorAll('[data-choice-hints]').forEach(function (group) {
        var hint = group.parentElement.querySelector('[data-choice-hint]');
        group.addEventListener('change', function (event) {
            if (hint && event.target.dataset.hint) {
                hint.textContent = event.target.dataset.hint;
            }
        });
    });
</script>
@endpush
