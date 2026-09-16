{{-- One job a model does: the provider it runs on, and the model, picked from
     what that provider publishes. The search button lists the models, which
     is also the proof that the provider answers with the key it holds.

     $providerField, $modelField  setting keys
     $blank                       what None means for this job
     $placeholder                 an example model name
     $label, $job                 optional heading and description --}}
@php
    $providerValue = (string) old($providerField, $settings[$providerField] ?? '');
    $modelValue = (string) old($modelField, $settings[$modelField] ?? '');
@endphp

<div class="model-job" data-picker>
    @isset($label)
        <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-1">
            <span class="fw-semibold" style="font-size: 0.875rem;">{{ $label }}</span>
            <span class="text-muted" style="font-size: 0.75rem;">None: {{ $blank }}</span>
        </div>
        <p class="text-muted mb-2" style="font-size: 0.78rem;">{{ $job }}</p>
    @endisset

    <div class="row g-3">
        <div class="col-md-6">
            <label for="{{ $providerField }}" class="form-label">Provider</label>
            <div class="input-group has-validation">
                <select name="{{ $providerField }}" id="{{ $providerField }}"
                        class="form-select @error($providerField) is-invalid @enderror"
                        data-picker-provider data-blank="None ({{ $blank }})">
                    <option value="">None ({{ $blank }})</option>
                    @foreach($providers as $provider)
                        <option value="{{ $provider['id'] }}" @selected($providerValue === $provider['id'])>
                            {{ $provider['label'] }}
                        </option>
                    @endforeach
                    {{-- A value the list no longer holds stays visible, so a
                         deleted provider reads as broken rather than as None. --}}
                    @if($providerValue !== '' && !collect($providers)->contains('id', $providerValue))
                        <option value="{{ $providerValue }}" selected>Missing provider ({{ $providerValue }})</option>
                    @endif
                </select>
                <button type="button" class="btn btn-outline-secondary" data-provider-new
                        title="Add a provider" aria-label="Add a provider">
                    <i class="bi bi-plus-lg"></i>
                </button>
                @error($providerField)<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="col-md-6">
            <label for="{{ $modelField }}" class="form-label">Model</label>
            <div class="input-group has-validation">
                <input type="text" name="{{ $modelField }}" id="{{ $modelField }}"
                       class="form-control font-monospace @error($modelField) is-invalid @enderror"
                       value="{{ $modelValue }}" placeholder="{{ $placeholder }}"
                       autocomplete="off" spellcheck="false" data-picker-model>
                <button type="button" class="btn btn-outline-secondary" data-picker-search
                        data-bs-toggle="dropdown" data-bs-reference="parent" data-bs-auto-close="outside"
                        aria-expanded="false" title="List this provider's models"
                        aria-label="List this provider's models">
                    <i class="bi bi-search"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end model-menu">
                    <div class="model-menu-filter">
                        <input type="search" class="form-control form-control-sm" placeholder="Filter models"
                               aria-label="Filter models" data-picker-filter>
                    </div>
                    <div class="model-menu-list" data-picker-list></div>
                </div>
                @error($modelField)<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-text model-status" data-picker-status aria-live="polite"></div>
        </div>
    </div>
</div>
