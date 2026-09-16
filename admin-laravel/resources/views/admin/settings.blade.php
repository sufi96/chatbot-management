@extends('layouts.app')

@section('page-title', 'Admin settings')

@section('content')
<div style="max-width: 920px;" data-section-current="{{ $section }}">

    <div class="page-head mb-4">
        <div>
            <h1>{{ $sections[$section]['label'] }}</h1>
            <p>{{ $sections[$section]['description'] }} These apply to every workspace, so only super admins can change them.</p>
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
         Outside the form: each change saves through the modal at once. --}}
    @if($section === 'providers')
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <span>Saved providers</span>
                <button type="button" class="btn btn-sm btn-brand" data-provider-new>
                    <i class="bi bi-plus-lg"></i> Add provider
                </button>
            </div>
            <div id="providerList"></div>
            <div id="providerListStatus" class="px-3 pb-3 small fw-medium" aria-live="polite"></div>
        </div>
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

        {{-- Models ---------------------------------------------------------- --}}
        <div @class(['d-none' => $section !== 'models'])>
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <span>Embedding</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestEmbedding">
                        <i class="bi bi-plug"></i> Test connection
                    </button>
                </div>
                <div class="p-3">
                    <p class="text-muted" style="font-size: 0.8rem;">
                        Turns passages and questions into vectors. None uses Ollama on this machine,
                        at <span class="font-monospace">{{ $defaultEmbeddingUrl }}</span>.
                    </p>

                    @include('admin._model-picker', [
                        'providerField' => 'embedding_provider_id',
                        'modelField' => 'embedding_model',
                        'blank' => 'Ollama on this machine',
                        'placeholder' => 'nomic-embed-text',
                    ])

                    <div class="row g-3 mt-0">
                        <div class="col-12 col-sm-4">
                            <label for="embedding_dimensions" class="form-label">Dimensions</label>
                            <input type="number" name="embedding_dimensions" id="embedding_dimensions"
                                   class="form-control font-monospace @error('embedding_dimensions') is-invalid @enderror"
                                   min="64" max="4096"
                                   value="{{ old('embedding_dimensions', $settings['embedding_dimensions']) }}">
                            @error('embedding_dimensions')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Test connection fills this in.</div>
                        </div>
                    </div>

                    <div id="embeddingTestResult" class="mt-2" style="font-size: 0.8125rem;" aria-live="polite"></div>

                    <div class="alert alert-warning mt-3 mb-0">
                        <div class="mb-2">
                            Changing the model or the dimensions invalidates every vector already stored.
                            Existing collections keep working on keyword search alone until they are indexed again.
                        </div>
                        <div class="text-muted" style="font-size: 0.75rem;">
                            Save your changes first, then re-index from
                            <a href="{{ route('admin.settings', 'maintenance') }}">Maintenance</a>. On PostgreSQL a
                            dimension change also alters the column, which clears the old vectors before rebuilding them.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Model jobs</div>
                <div class="p-3">
                    <p class="text-muted" style="font-size: 0.8rem;">
                        Every job a model does besides answering. A job set to None falls back as
                        described beside it.
                    </p>

                    @foreach ($modelRoles as $role => $meta)
                        @include('admin._model-picker', [
                            'providerField' => "{$role}_model_provider_id",
                            'modelField' => "{$role}_model_name",
                            'label' => $meta['label'],
                            'job' => $meta['job'],
                            'blank' => $meta['blank'],
                            'placeholder' => $meta['placeholder'],
                        ])
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Chunking -------------------------------------------------------- --}}
        <div @class(['d-none' => $section !== 'chunking'])>
            <div class="card mb-3">
                <div class="card-header">Chunking</div>
                <div class="p-3">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label for="chunk_size" class="form-label">Chunk size</label>
                            <input type="number" name="chunk_size" id="chunk_size"
                                   class="form-control font-monospace @error('chunk_size') is-invalid @enderror"
                                   min="400" max="8000" value="{{ old('chunk_size', $settings['chunk_size']) }}">
                            @error('chunk_size')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">A ceiling, not a target. Headings, tables and code blocks decide where a passage ends; this only stops one growing without limit.</div>
                        </div>
                        <div class="col-sm-6">
                            <label for="chunk_overlap" class="form-label">Overlap</label>
                            <input type="number" name="chunk_overlap" id="chunk_overlap"
                                   class="form-control font-monospace @error('chunk_overlap') is-invalid @enderror"
                                   min="0" value="{{ old('chunk_overlap', $settings['chunk_overlap']) }}">
                            @error('chunk_overlap')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Characters repeated when one long passage has to be cut. Sections never overlap, because the boundary between them already means something.</div>
                        </div>
                        <div class="col-sm-6">
                            <label for="context_char_budget" class="form-label">Context budget</label>
                            <input type="number" name="context_char_budget" id="context_char_budget"
                                   class="form-control font-monospace @error('context_char_budget') is-invalid @enderror"
                                   min="1000" max="20000" value="{{ old('context_char_budget', $settings['context_char_budget']) }}">
                            @error('context_char_budget')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Characters of retrieved material sent to the model. Lower it if answers wander on a small model.</div>
                        </div>
                    </div>
                    <div class="form-text mt-2">Chunking applies to sources indexed from now on. Existing chunks keep the boundaries they were made with until you rebuild the index.</div>
                </div>
            </div>
        </div>

        {{-- Web search ------------------------------------------------------ --}}
        <div @class(['d-none' => $section !== 'web-search'])>
            <div class="card mb-3">
                <div class="card-header">Web search</div>
                <div class="p-3">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label for="web_search_provider" class="form-label">Provider</label>
                            <select name="web_search_provider" id="web_search_provider"
                                    class="form-select @error('web_search_provider') is-invalid @enderror">
                                <option value="duckduckgo" @selected(old('web_search_provider', $settings['web_search_provider']) === 'duckduckgo')>DuckDuckGo (no key)</option>
                                <option value="tavily" @selected(old('web_search_provider', $settings['web_search_provider']) === 'tavily')>Tavily</option>
                                <option value="brave" @selected(old('web_search_provider', $settings['web_search_provider']) === 'brave')>Brave</option>
                            </select>
                            @error('web_search_provider')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">DuckDuckGo needs no key and is rate limited, so treat it as a way to try the feature rather than something to rely on. Tavily returns page text; Brave returns snippets.</div>
                        </div>
                        <div class="col-sm-6">
                            <label for="web_search_tavily_key" class="form-label">Tavily API key</label>
                            <input type="password" name="web_search_tavily_key" id="web_search_tavily_key"
                                   class="form-control font-monospace @error('web_search_tavily_key') is-invalid @enderror"
                                   autocomplete="off" value="{{ old('web_search_tavily_key', $settings['web_search_tavily_key']) }}">
                            @error('web_search_tavily_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <label for="web_search_brave_key" class="form-label mt-3">Brave API key</label>
                            <input type="password" name="web_search_brave_key" id="web_search_brave_key"
                                   class="form-control font-monospace @error('web_search_brave_key') is-invalid @enderror"
                                   autocomplete="off" value="{{ old('web_search_brave_key', $settings['web_search_brave_key']) }}">
                            @error('web_search_brave_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Each key is kept when you switch provider, so you can change back without retyping it.</div>
                        </div>
                    </div>
                    <div class="form-text mt-2">Where the web sits in a bot's answer source order decides when it runs. Set that, and turn it on, per bot under Brain.</div>
                </div>
            </div>
        </div>

        {{-- Branding -------------------------------------------------------- --}}
        <div @class(['d-none' => $section !== 'branding'])>
            <div class="card mb-3">
                <div class="card-header">Branding</div>
                <div class="p-3">
                    <p class="text-muted" style="font-size: 0.8rem;">
                        PNG, JPG or WebP. Not SVG: one served from this site runs its own script for
                        anyone who opens it directly.
                    </p>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <label for="brand_logo" class="form-label">Wordmark</label>
                            <div class="brand-preview brand-preview-wide mb-2">
                                <img src="{{ $logoUrl }}" alt="The wordmark in use">
                            </div>
                            <input type="file" name="brand_logo" id="brand_logo"
                                   class="form-control @error('brand_logo') is-invalid @enderror"
                                   accept="image/png,image/jpeg,image/webp">
                            @error('brand_logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Shown in the sidebar and on the sign-in screen, about 28px tall. A wide image works best.</div>
                            @if($logoIsCustom)
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           name="brand_logo_revert" id="brand_logo_revert">
                                    <label class="form-check-label" for="brand_logo_revert">Use the built-in one</label>
                                </div>
                            @else
                                <div class="form-text">Currently the mark the console ships with.</div>
                            @endif
                        </div>

                        <div class="col-md-6">
                            <label for="brand_icon" class="form-label">Browser tab icon</label>
                            <div class="brand-preview brand-preview-square mb-2">
                                <img src="{{ $iconUrl }}" alt="The browser tab icon in use">
                            </div>
                            <input type="file" name="brand_icon" id="brand_icon"
                                   class="form-control @error('brand_icon') is-invalid @enderror"
                                   accept="image/png,image/jpeg,image/webp">
                            @error('brand_icon')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Shown on the browser tab, in bookmarks and on a phone home screen. Square, at least 128px.</div>
                            @if($iconIsCustom)
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           name="brand_icon_revert" id="brand_icon_revert">
                                    <label class="form-check-label" for="brand_icon_revert">Use the built-in one</label>
                                </div>
                            @else
                                <div class="form-text">Currently the mark the console ships with.</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if(in_array($section, ['models', 'chunking', 'web-search', 'branding'], true))
            <div class="form-actions">
                <span class="text-muted d-none d-sm-inline" style="font-size: 0.75rem;">Saves every category, and applies to every workspace.</span>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <button type="submit" class="btn btn-brand">Save settings</button>
                </div>
            </div>
        @endif
    </form>

    {{-- Maintenance ----------------------------------------------------- --}}
    @if($section === 'maintenance')
        <div class="card mb-3">
            <div class="card-header">Rebuild the index</div>
            <div class="p-3 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                <p class="text-muted mb-0" style="font-size: 0.8125rem;">
                    Re-embeds every source in every workspace with the saved embedding settings. Run this after
                    changing the model or the dimensions. It runs in the background.
                </p>
                <form action="{{ route('admin.settings.reindex') }}" method="POST" class="flex-shrink-0 m-0"
                      onsubmit="return confirm('Re-embed every source in every workspace?');">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-repeat"></i> Re-index everything
                    </button>
                </form>
            </div>
        </div>
    @endif

</div>

@include('admin._provider-modal')
@endsection
