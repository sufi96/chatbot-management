{{-- One job a model does: the provider it runs on, and the model, picked from
     what that provider publishes. The search button lists the models, which
     is also the proof that the provider answers with the key it holds.

     $providerField, $modelField  setting keys
     $blank                       what None means for this job
     $placeholder                 an example model name
     $inline                      provider and model side by side (default: stacked) --}}
@php
    $providerValue = (string) old($providerField, $settings[$providerField] ?? '');
    $modelValue = (string) old($modelField, $settings[$modelField] ?? '');
    $inline = $inline ?? false;
@endphp

<div class="model-picker" data-picker>
    <div class="row g-2">
        <div class="{{ $inline ? 'col-md-6' : 'col-12' }}">
            <div class="input-group input-group-sm has-validation">
                <label for="{{ $providerField }}" class="input-group-text picker-label">Provider</label>
                <select name="{{ $providerField }}" id="{{ $providerField }}"
                        class="form-select @error($providerField) is-invalid @enderror"
                        data-picker-provider data-blank="{{ $blank }}" title="No provider: {{ $blank }}">
                    <option value="">{{ $blank }}</option>
                    @foreach($providers as $provider)
                        <option value="{{ $provider['id'] }}" @selected($providerValue === $provider['id'])>
                            {{ $provider['name'] }}
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

        <div class="{{ $inline ? 'col-md-6' : 'col-12' }}">
            <div class="input-group input-group-sm has-validation">
                <label for="{{ $modelField }}" class="input-group-text picker-label">Model</label>
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
        </div>
    </div>
    <div class="form-text model-status" data-picker-status aria-live="polite"></div>
</div>
