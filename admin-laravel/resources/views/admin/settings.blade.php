@extends('layouts.app')

@section('page-title', 'Admin settings')

@section('content')
<div class="settings-page" data-section-current="{{ $section }}">

    <div class="page-head mb-3">
        <div>
            <h1>{{ $sections[$section]['label'] }}</h1>
            <p>{{ $sections[$section]['description'] }} Applies to every workspace.</p>
        </div>
    </div>

    {{-- On a phone the sidebar keeps only the open category, so the others
         are one select away instead. --}}
    <select class="form-select d-md-none mb-3" aria-label="Settings category"
            onchange="window.location.href = this.value">
        @foreach($sections as $key => $meta)
            <option value="{{ route('admin.settings', $key) }}" @selected($key === $section)>{{ $meta['label'] }}</option>
        @endforeach
    </select>

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
                            <div class="form-text">One per line, in plain words. Each bot can add more under Brain.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-note mb-3">
                <i class="bi bi-info-circle"></i>
                <span>
                    The guard runs only on bots that switch it on under Brain, on the Guard model set in
                    <a href="{{ route('admin.settings', 'models') }}">Models</a> or each bot's main model. A dedicated
                    guard model such as Qwen3Guard judges its own categories only, so topics are then checked by the
                    bot's main model in a second call.
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
                            Turn web search on, and place it in the answer source order, per bot under Brain.
                        </span>
                    </div>
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
@if($section === 'maintenance')
    @include('admin._architecture-modal')
@endif
@endsection
