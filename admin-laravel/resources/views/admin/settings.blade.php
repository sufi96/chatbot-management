@extends('layouts.app')

@section('page-title', 'Admin settings')

@section('content')
<div style="max-width: 860px;">

    <div class="page-head mb-4">
        <div>
            <h1>Admin settings</h1>
            <p>How content is turned into vectors and where those vectors live. These apply to every workspace, so only super admins can change them.</p>
        </div>
    </div>

    <form action="{{ route('admin.settings.update') }}" method="POST">
        @csrf
        @method('PUT')

        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <span>Embedding</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="testEmbedding()">
                    <i class="bi bi-plug"></i> Test connection
                </button>
            </div>
            <div class="p-3">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label for="embedding_base_url" class="form-label">Base URL</label>
                        <input type="text" name="embedding_base_url" id="embedding_base_url"
                               class="form-control font-monospace"
                               value="{{ old('embedding_base_url', $settings['embedding_base_url']) }}" required>
                        <div class="form-text">OpenAI-compatible. Local Ollama serves this at /v1.</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="embedding_api_key" class="form-label">API key</label>
                        <input type="password" name="embedding_api_key" id="embedding_api_key"
                               class="form-control font-monospace"
                               value="{{ old('embedding_api_key', $settings['embedding_api_key']) }}"
                               placeholder="Not needed for local Ollama">
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-sm-8">
                        <label for="embedding_model" class="form-label">Model</label>
                        <input type="text" name="embedding_model" id="embedding_model"
                               class="form-control font-monospace"
                               value="{{ old('embedding_model', $settings['embedding_model']) }}" required>
                    </div>
                    <div class="col-12 col-sm-4">
                        <label for="embedding_dimensions" class="form-label">Dimensions</label>
                        <input type="number" name="embedding_dimensions" id="embedding_dimensions"
                               class="form-control font-monospace" min="64" max="4096"
                               value="{{ old('embedding_dimensions', $settings['embedding_dimensions']) }}" required>
                    </div>
                </div>

                <div id="embeddingTestResult" class="mt-2" style="font-size: 0.8125rem;"></div>

                <div class="alert alert-warning mt-3 mb-0">
                    Changing the model or the dimensions invalidates every vector already stored.
                    Existing collections keep working on keyword search alone until they are indexed again.
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Vector store</div>
            <div class="p-3">
                <div style="max-width: 340px;">
                    <label for="vector_driver" class="form-label">Driver</label>
                    <select name="vector_driver" id="vector_driver" class="form-select">
                        <option value="pgvector" {{ old('vector_driver', $settings['vector_driver']) === 'pgvector' ? 'selected' : '' }}>PostgreSQL with pgvector</option>
                        <option value="sqlite" {{ old('vector_driver', $settings['vector_driver']) === 'sqlite' ? 'selected' : '' }}>SQLite fallback</option>
                    </select>
                    <div class="form-text">
                        pgvector searches with an index inside the database. The SQLite fallback scans every
                        vector in memory, which is fine for small collections and slows as they grow.
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Chunking</div>
            <div class="p-3">
                <div class="row g-3">
                    <div class="col-6">
                        <label for="chunk_size" class="form-label">Chunk size</label>
                        <input type="number" name="chunk_size" id="chunk_size" class="form-control font-monospace"
                               min="200" max="4000" value="{{ old('chunk_size', $settings['chunk_size']) }}" required>
                        <div class="form-text">Characters per passage. Larger means more context and fewer, blunter matches.</div>
                    </div>
                    <div class="col-6">
                        <label for="chunk_overlap" class="form-label">Overlap</label>
                        <input type="number" name="chunk_overlap" id="chunk_overlap" class="form-control font-monospace"
                               min="0" value="{{ old('chunk_overlap', $settings['chunk_overlap']) }}" required>
                        <div class="form-text">Characters repeated between neighbours, so a sentence split across two passages is still findable.</div>
                    </div>
                </div>
                <div class="form-text mt-2">Applies to sources indexed from now on. Existing chunks keep the size they were made with.</div>
            </div>
        </div>

        <div class="form-actions">
            <span class="text-muted d-none d-sm-inline" style="font-size: 0.75rem;">These settings apply to every workspace.</span>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <button type="submit" class="btn btn-brand">Save settings</button>
            </div>
        </div>
    </form>

</div>
@endsection

@push('scripts')
<script>
    function testEmbedding() {
        var out = document.getElementById('embeddingTestResult');
        out.className = 'mt-2 text-muted';
        out.textContent = 'Testing...';

        fetch('{{ route('admin.settings.test') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                embedding_base_url: document.getElementById('embedding_base_url').value,
                embedding_api_key: document.getElementById('embedding_api_key').value,
                embedding_model: document.getElementById('embedding_model').value
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            out.className = 'mt-2 ' + (data.ok ? 'text-success' : 'text-danger');
            out.textContent = data.message || (data.ok ? 'Connected.' : 'Failed.');
            if (data.ok && data.dimensions) {
                document.getElementById('embedding_dimensions').value = data.dimensions;
            }
        })
        .catch(function () {
            out.className = 'mt-2 text-danger';
            out.textContent = 'Could not reach the admin portal.';
        });
    }
</script>
@endpush
