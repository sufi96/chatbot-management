{{-- The platform provider editor, and everything that keeps the page in step
     with it: the providers list, every provider select, and the model search
     behind each picker. A modal, so an unsaved settings form survives adding
     an endpoint halfway through. --}}
<div class="modal fade" id="providerModal" tabindex="-1" aria-labelledby="providerModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="providerModalTitle">New provider</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="providerName" class="form-label">
                        Name <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="text" id="providerName" class="form-control" placeholder="DGX Spark A" maxlength="255">
                    <div class="form-text">What you will recognise it by in every list.</div>
                </div>

                <div class="mb-3">
                    <label for="providerBaseUrl" class="form-label">
                        Base URL <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="text" id="providerBaseUrl" class="form-control font-monospace"
                           placeholder="http://localhost:11434/v1" maxlength="500" spellcheck="false">
                    <div class="form-text">OpenAI-compatible. Ollama serves this at /v1.</div>
                </div>

                <div class="mb-3">
                    <label for="providerApiKey" class="form-label">API key</label>
                    <input type="password" id="providerApiKey" class="form-control font-monospace"
                           placeholder="Not needed for local Ollama" maxlength="500" autocomplete="off">
                </div>

                <div id="providerModalResult" class="small fw-medium" aria-live="polite"></div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary" id="btnTestProvider">
                    <i class="bi bi-plug"></i> Test
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-brand" id="btnSaveProvider">Save provider</button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var routes = {
        models: @json(route('admin.settings.models')),
        test: @json(route('admin.settings.test')),
        store: @json(route('admin.providers.store')),
        base: @json(url('/admin/providers')),
    };
    var providers = @json($providers);
    var csrf = document.querySelector('meta[name="csrf-token"]').content;

    var modalEl = document.getElementById('providerModal');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var editingId = null;
    // The select that asked for a new provider, so the new one lands there.
    var requestedBy = null;

    function send(method, url, body) {
        return fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: body ? JSON.stringify(body) : undefined,
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                data._status = response.status;
                // A validation failure names its first problem, not "422".
                if (response.status === 422 && data.errors) {
                    data.message = Object.values(data.errors)[0][0];
                }
                return data;
            });
        });
    }

    // The engine passes on the HTTP client's own words ("All connection
    // attempts failed"), which need saying whose attempt it was. A 422 is
    // the portal's own sentence and stands as it is.
    function failure(data) {
        if (data._status === 422) return data.message;
        return 'The provider did not answer' + (data.message ? ': ' + data.message : '.');
    }

    function setStatus(el, tone, text) {
        el.className = el.className.replace(/\btext-(success|danger|muted)\b/g, '').trim() + ' text-' + tone;
        el.textContent = text;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    // ---- Keeping the page in step with the list ---------------------------

    function sortProviders() {
        providers.sort(function (a, b) { return a.name.localeCompare(b.name); });
    }

    function renderSelects() {
        document.querySelectorAll('[data-picker-provider]').forEach(function (select) {
            var current = select.value;
            var missing = current && !providers.some(function (p) { return p.id === current; });

            select.textContent = '';
            select.appendChild(new Option(select.dataset.blank, ''));
            providers.forEach(function (p) { select.appendChild(new Option(p.label, p.id)); });
            if (missing) select.appendChild(new Option('Missing provider (' + current + ')', current));
            select.value = current;
        });
    }

    function renderList() {
        var list = document.getElementById('providerList');
        if (!list) return;

        list.textContent = '';

        if (!providers.length) {
            var empty = el('div', 'empty');
            empty.appendChild(el('i', 'bi bi-hdd-network'));
            empty.appendChild(el('h6', '', 'No providers yet'));
            empty.appendChild(el('p', '', 'Add the machine or hosted API your model jobs should run on. Until then, embedding uses Ollama on this machine and every other job falls back.'));
            list.appendChild(empty);
            return;
        }

        providers.forEach(function (p) {
            var row = el('div', 'provider-row');
            var info = el('div', 'min-w-0');
            var title = el('div', 'd-flex flex-wrap align-items-center gap-2');
            title.appendChild(el('span', 'fw-semibold', p.name));
            title.appendChild(el('span', 'chip', p.used_by.length ? 'Used by ' + p.used_by.join(', ') : 'Not used yet'));
            info.appendChild(title);
            info.appendChild(el('div', 'provider-url', p.base_url));
            row.appendChild(info);

            var actions = el('div', 'd-flex gap-2');
            var edit = el('button', 'btn btn-sm btn-outline-secondary');
            edit.type = 'button';
            edit.title = 'Edit ' + p.name;
            edit.setAttribute('aria-label', 'Edit ' + p.name);
            edit.appendChild(el('i', 'bi bi-pencil'));
            edit.addEventListener('click', function () { openModal(p); });

            var del = el('button', 'btn btn-sm btn-outline-danger');
            del.type = 'button';
            del.title = 'Delete ' + p.name;
            del.setAttribute('aria-label', 'Delete ' + p.name);
            del.appendChild(el('i', 'bi bi-trash'));
            del.addEventListener('click', function () { deleteProvider(p); });

            actions.appendChild(edit);
            actions.appendChild(del);
            row.appendChild(actions);
            list.appendChild(row);
        });
    }

    // ---- The modal ---------------------------------------------------------

    function openModal(provider, select) {
        editingId = provider ? provider.id : null;
        requestedBy = select || null;

        document.getElementById('providerModalTitle').textContent = provider ? 'Edit provider' : 'New provider';
        document.getElementById('providerName').value = provider ? provider.name : '';
        document.getElementById('providerBaseUrl').value = provider ? provider.base_url : '';
        document.getElementById('providerApiKey').value = provider ? (provider.api_key || '') : '';
        document.getElementById('providerModalResult').textContent = '';

        modal.show();
    }

    modalEl.addEventListener('shown.bs.modal', function () {
        document.getElementById('providerName').focus();
    });

    function draft() {
        return {
            name: document.getElementById('providerName').value.trim(),
            base_url: document.getElementById('providerBaseUrl').value.trim(),
            api_key: document.getElementById('providerApiKey').value.trim(),
        };
    }

    document.getElementById('btnTestProvider').addEventListener('click', function () {
        var out = document.getElementById('providerModalResult');
        var button = this;
        var values = draft();

        button.disabled = true;
        setStatus(out, 'muted', 'Testing...');

        send('POST', routes.models, {base_url: values.base_url, api_key: values.api_key})
            .then(function (data) {
                if (data.ok) {
                    var count = (data.models || []).length;
                    setStatus(out, 'success', 'Connected. ' + count + (count === 1 ? ' model' : ' models') + ' available.');
                } else {
                    setStatus(out, 'danger', failure(data));
                }
            })
            .catch(function () { setStatus(out, 'danger', 'Could not reach the admin portal.'); })
            .finally(function () { button.disabled = false; });
    });

    document.getElementById('btnSaveProvider').addEventListener('click', function () {
        var out = document.getElementById('providerModalResult');
        var button = this;

        button.disabled = true;
        setStatus(out, 'muted', 'Saving...');

        var request = editingId
            ? send('PUT', routes.base + '/' + encodeURIComponent(editingId), draft())
            : send('POST', routes.store, draft());

        request
            .then(function (data) {
                if (!data.success) {
                    setStatus(out, 'danger', data.message || 'Could not save the provider.');
                    return;
                }

                providers = providers.filter(function (p) { return p.id !== data.provider.id; });
                providers.push(data.provider);
                sortProviders();
                renderSelects();
                renderList();

                if (requestedBy) {
                    requestedBy.value = data.provider.id;
                    requestedBy.dispatchEvent(new Event('change', {bubbles: true}));
                }

                modal.hide();
            })
            .catch(function () { setStatus(out, 'danger', 'Could not reach the admin portal.'); })
            .finally(function () { button.disabled = false; });
    });

    function deleteProvider(provider) {
        var out = document.getElementById('providerListStatus');

        if (!confirm('Delete ' + provider.name + '?')) return;

        send('DELETE', routes.base + '/' + encodeURIComponent(provider.id))
            .then(function (data) {
                if (!data.success) {
                    setStatus(out, 'danger', data.message || 'Could not delete the provider.');
                    return;
                }
                providers = providers.filter(function (p) { return p.id !== provider.id; });
                renderSelects();
                renderList();
                setStatus(out, 'success', data.message);
            })
            .catch(function () { setStatus(out, 'danger', 'Could not reach the admin portal.'); });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-provider-new]');
        if (!button) return;

        var picker = button.closest('[data-picker]');
        openModal(null, picker ? picker.querySelector('[data-picker-provider]') : null);
    });

    // ---- Model pickers -----------------------------------------------------
    //
    // The search button is a Bootstrap dropdown toggle. Opening it always asks
    // the provider afresh, so the list never shows models a provider has since
    // dropped, and a failure reads as a failure right where the model is typed.

    function bindPicker(picker) {
        var select = picker.querySelector('[data-picker-provider]');
        var input = picker.querySelector('[data-picker-model]');
        var toggle = picker.querySelector('[data-picker-search]');
        var filter = picker.querySelector('[data-picker-filter]');
        var list = picker.querySelector('[data-picker-list]');
        var status = picker.querySelector('[data-picker-status]');
        var icon = toggle.querySelector('i');
        var dropdown = bootstrap.Dropdown.getOrCreateInstance(toggle);
        var loaded = false;

        function renderModels(models) {
            list.textContent = '';

            models.forEach(function (name) {
                var item = el('button', 'dropdown-item');
                item.type = 'button';
                item.dataset.model = name;
                item.appendChild(el('span', '', name));
                if (name === input.value.trim()) {
                    item.classList.add('active');
                    item.appendChild(el('i', 'bi bi-check2'));
                }
                item.addEventListener('click', function () {
                    input.value = name;
                    input.dispatchEvent(new Event('input', {bubbles: true}));
                    input.classList.remove('is-invalid');
                    dropdown.hide();
                    setStatus(status, 'success', 'Selected ' + name + '.');
                    input.focus();
                });
                list.appendChild(item);
            });

            if (!models.length) {
                list.appendChild(el('div', 'dropdown-item-text text-muted small', 'The provider lists no models. Type the name instead.'));
            }
        }

        function applyFilter() {
            var needle = filter.value.trim().toLowerCase();
            list.querySelectorAll('[data-model]').forEach(function (item) {
                item.classList.toggle('d-none', needle !== '' && item.dataset.model.toLowerCase().indexOf(needle) === -1);
            });
        }

        toggle.addEventListener('show.bs.dropdown', function (event) {
            if (loaded) return;
            event.preventDefault();

            if (!select.value) {
                setStatus(status, 'danger', 'Choose a provider first.');
                select.focus();
                return;
            }

            toggle.disabled = true;
            icon.className = 'spinner-border spinner-border-sm';
            setStatus(status, 'muted', 'Asking the provider...');

            send('POST', routes.models, {provider_id: select.value})
                .then(function (data) {
                    if (!data.ok) {
                        setStatus(status, 'danger', failure(data));
                        return;
                    }

                    var models = data.models || [];
                    renderModels(models);
                    filter.value = '';
                    setStatus(status, 'success', 'Connected. ' + models.length + (models.length === 1 ? ' model' : ' models') + ' available.');

                    loaded = true;
                    toggle.disabled = false;
                    dropdown.show();
                })
                .catch(function () { setStatus(status, 'danger', 'Could not reach the admin portal.'); })
                .finally(function () {
                    toggle.disabled = false;
                    icon.className = 'bi bi-search';
                });
        });

        toggle.addEventListener('shown.bs.dropdown', function () { filter.focus(); });
        toggle.addEventListener('hidden.bs.dropdown', function () { loaded = false; });

        filter.addEventListener('input', applyFilter);
        filter.addEventListener('keydown', function (event) {
            // Enter picks the first match rather than submitting the settings form.
            if (event.key !== 'Enter') return;
            event.preventDefault();
            var first = list.querySelector('[data-model]:not(.d-none)');
            if (first) first.click();
        });

        select.addEventListener('change', function () {
            status.textContent = '';
            select.classList.remove('is-invalid');
        });
    }

    document.querySelectorAll('[data-picker]').forEach(bindPicker);

    // ---- Embedding test ----------------------------------------------------

    var testEmbedding = document.getElementById('btnTestEmbedding');
    if (testEmbedding) {
        testEmbedding.addEventListener('click', function () {
            var out = document.getElementById('embeddingTestResult');
            var button = this;

            button.disabled = true;
            setStatus(out, 'muted', 'Testing...');

            send('POST', routes.test, {
                provider_id: document.getElementById('embedding_provider_id').value || null,
                embedding_model: document.getElementById('embedding_model').value.trim(),
            })
                .then(function (data) {
                    var ok = !!data.ok;
                    setStatus(out, ok ? 'success' : 'danger', data.message || (ok ? 'Connected.' : 'Failed.'));
                    if (ok && data.dimensions) {
                        document.getElementById('embedding_dimensions').value = data.dimensions;
                    }
                })
                .catch(function () { setStatus(out, 'danger', 'Could not reach the admin portal.'); })
                .finally(function () { button.disabled = false; });
        });
    }

    renderList();

    // A refused save lands on the right category, but the field can be a
    // long way down it.
    var invalid = document.querySelector('form .is-invalid');
    if (invalid) {
        invalid.scrollIntoView({block: 'center'});
        invalid.focus({preventScroll: true});
    }
})();
</script>
@endpush
