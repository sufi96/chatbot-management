{{-- One job a model does: where its endpoint is, and what blank means. --}}
<div>
    <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-1">
        <span class="fw-semibold" style="font-size: 0.875rem;">{{ $meta['label'] }}</span>
        <span class="text-muted" style="font-size: 0.75rem;">Blank: {{ $meta['blank'] }}</span>
    </div>
    <p class="text-muted mb-2" style="font-size: 0.78rem;">{{ $meta['job'] }}</p>

    <div class="mb-2">
        <label for="{{ $role }}_model_base_url" class="form-label">Base URL</label>
        <input type="text" name="{{ $role }}_model_base_url" id="{{ $role }}_model_base_url"
               class="form-control font-monospace" placeholder="http://localhost:11434/v1"
               value="{{ old($role . '_model_base_url', $settings[$role . '_model_base_url']) }}">
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <label for="{{ $role }}_model_name" class="form-label">Model</label>
            <input type="text" name="{{ $role }}_model_name" id="{{ $role }}_model_name"
                   class="form-control font-monospace" placeholder="{{ $meta['placeholder'] }}"
                   value="{{ old($role . '_model_name', $settings[$role . '_model_name']) }}">
        </div>
        <div class="col-md-6">
            <label for="{{ $role }}_model_api_key" class="form-label">API key</label>
            <input type="password" name="{{ $role }}_model_api_key" id="{{ $role }}_model_api_key"
                   class="form-control font-monospace" autocomplete="off"
                   value="{{ old($role . '_model_api_key', $settings[$role . '_model_api_key']) }}">
        </div>
    </div>
</div>
